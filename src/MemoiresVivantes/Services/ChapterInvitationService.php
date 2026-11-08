<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterInvitation;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Exception\ChapterInvitationException;
use App\MemoiresVivantes\Repository\ChapterInvitationRepository;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Invitations par e-mail à répondre aux questions d'un chapitre (08/11/2026).
 *
 * Cycle : création (premier envoi, lien de 7 jours) → renouvellement automatique à l'expiration par la commande
 * app:memoires:invitations:renew (RENEWALS_MAX fois, 4 semaines en tout) → `exhausted` avec avis au propriétaire du livre.
 * Fin anticipée : la personne termine (lien de chapitre : POST …/invitation/complete ; contributeur : sa validation),
 * le propriétaire arrête (`cancelled`), ou la personne se désinscrit (`unsubscribed`, lien dans chaque e-mail ; l'adresse
 * n'est alors plus invitée sur ce site). Un renvoi manuel repart pour 7 jours et remet le compteur à zéro.
 * Les e-mails passent par le serveur d'envoi du site, dans la langue de l'invitation (EmailSenderService::sendCustomerEmail).
 */
class ChapterInvitationService
{
    /** Délai minimal entre deux renvois manuels d'une même invitation */
    public const RESEND_INTERVAL = 600;

    private const TEMPLATE_LINK = 'emails/memoires_chapter_invitation.html.twig';
    private const TEMPLATE_EXHAUSTED = 'emails/memoires_invitation_exhausted.html.twig';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly ShareLinkSigner $signer,
        private readonly EmailSenderService $emailSender,
        private readonly EmailLocale $emailLocale,
        private readonly FrontendUrlResolver $frontendUrl,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Crée l'invitation et envoie le premier lien ; rien n'est enregistré si l'e-mail ne part pas */
    public function create(Chapter $chapter, ?Contributor $contributor, string $email, mixed $locale, Request $request): ChapterInvitation
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ChapterInvitationException(400, 'EMAIL_INVALID', 'Adresse e-mail manquante ou invalide.');
        }
        if ($contributor !== null && (string) $contributor->getBook()?->getId() !== (string) $chapter->getBook()?->getId()) {
            throw new ChapterInvitationException(404, 'CONTRIBUTOR_NOT_FOUND', 'Ce contributeur ne fait pas partie du livre.');
        }
        $repository = $this->repository();
        if ($repository->hasUnsubscribed($email)) {
            throw new ChapterInvitationException(409, 'EMAIL_UNSUBSCRIBED', 'Cette adresse a demandé à ne plus recevoir de lien.');
        }
        $active = $repository->findActiveFor($chapter, $contributor);
        if ($active !== null) {
            throw new ChapterInvitationException(409, 'INVITATION_ALREADY_ACTIVE', 'Une invitation est déjà en cours pour ce chapitre.', ['invitationId' => (string) $active->getId()]);
        }

        $now = new \DateTimeImmutable();
        $invitation = (new ChapterInvitation())
            ->setChapter($chapter)
            ->setContributor($contributor)
            ->setEmail($email)
            ->setLocale($this->emailLocale->language($locale))
            ->setBackendOrigin($request->getSchemeAndHttpHost())
            ->setFirstSentAt($now)
            ->setLastSentAt($now)
            ->setExpiresAt(self::expiry($now));

        if (!$this->sendLink($invitation, 'first', $request)) {
            throw new ChapterInvitationException(502, 'EMAIL_SEND_FAILED', "L'e-mail n'a pas pu être envoyé ; l'invitation n'a pas été créée.");
        }

        $em = $this->emProvider->getEntityManager();
        $em->persist($invitation);
        $em->flush();

        return $invitation;
    }

    /** Nouveau lien tout de suite (invitation active ou épuisée) : 7 jours, compteur de renouvellements remis à zéro */
    public function resend(ChapterInvitation $invitation, ?Request $request): ChapterInvitation
    {
        if (!$invitation->isOpen()) {
            throw new ChapterInvitationException(409, 'INVITATION_CLOSED', 'Cette invitation est terminée : créez-en une nouvelle.', ['status' => $invitation->getStatus()]);
        }
        $now = new \DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $invitation->getLastSentAt()->getTimestamp();
        if ($elapsed < self::RESEND_INTERVAL) {
            throw new ChapterInvitationException(429, 'RESEND_TOO_SOON', "Un lien vient de partir : patientez avant d'en renvoyer un.", ['retryAfterSeconds' => self::RESEND_INTERVAL - $elapsed]);
        }

        $invitation->setStatus(ChapterInvitation::STATUS_ACTIVE)->setRenewalsUsed(0)->setLastSentAt($now)->setExpiresAt(self::expiry($now));
        $em = $this->emProvider->getEntityManager();
        if (!$this->sendLink($invitation, 'resend', $request)) {
            $em->refresh($invitation);
            throw new ChapterInvitationException(502, 'EMAIL_SEND_FAILED', "L'e-mail n'a pas pu être envoyé ; rien n'a changé.");
        }
        $em->flush();

        return $invitation;
    }

    /** Plus aucun envoi ; le dernier lien parti reste valable jusqu'à sa date */
    public function cancel(ChapterInvitation $invitation): ChapterInvitation
    {
        if ($invitation->isOpen()) {
            $invitation->setStatus(ChapterInvitation::STATUS_CANCELLED);
            $this->emProvider->getEntityManager()->flush();
        }

        return $invitation;
    }

    /** La personne a terminé par le lien du chapitre (ou son lien personnel) ; @return int invitations fermées */
    public function complete(Chapter $chapter, ?Contributor $contributor): int
    {
        return $this->markCompleted($this->repository()->findOpenFor($chapter, $contributor));
    }

    /**
     * Validation d'un contributeur : ferme ses invitations du chapitre donné (lien signé, qui porte chapterId), ou de
     * tous les chapitres (validation par le propriétaire). Chapitre inconnu ou d'un autre livre : rien.
     */
    public function completeForContributor(Contributor $contributor, ?string $chapterId): int
    {
        $chapter = null;
        if ($chapterId !== null) {
            try {
                $chapter = $this->emProvider->getEntityManager()->getRepository(Chapter::class)->find(Uuid::fromString($chapterId));
            } catch (\Throwable) {
                return 0;
            }
            if ($chapter === null || (string) $chapter->getBook()?->getId() !== (string) $contributor->getBook()?->getId()) {
                return 0;
            }
        }

        return $this->markCompleted($this->repository()->findOpenForContributor($contributor, $chapter));
    }

    /** Lien « ne plus recevoir » : l'invitation passe à `unsubscribed` (sauf déjà terminée) ; null si le jeton est inconnu */
    public function unsubscribe(string $token): ?ChapterInvitation
    {
        $invitation = $this->repository()->findOneByToken($token);
        if ($invitation !== null && $invitation->getStatus() !== ChapterInvitation::STATUS_COMPLETED) {
            $invitation->setStatus(ChapterInvitation::STATUS_UNSUBSCRIBED);
            $this->emProvider->getEntityManager()->flush();
        }

        return $invitation;
    }

    /** Un nouveau lien partira-t-il automatiquement pour ce chapitre (et ce contributeur) ? Pour la réponse « lien expiré » */
    public function renewalPending(string $chapterId, ?string $contributorId): bool
    {
        try {
            return $this->repository()->renewalPending($chapterId, $contributorId);
        } catch (\Throwable $e) {
            $this->logger->warning('Invitations : renouvellement non vérifié : ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Tâche planifiée, site courant : renvoie un lien pour chaque invitation active expirée, ou la déclare épuisée (avis
     * au propriétaire) quand les renouvellements sont consommés. Un envoi raté est retenté au passage suivant.
     *
     * @return array{renewed: int, exhausted: int, failed: int}
     */
    public function renewDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $result = ['renewed' => 0, 'exhausted' => 0, 'failed' => 0];
        $em = $this->emProvider->getEntityManager();

        foreach ($this->repository()->findDue($now) as $invitation) {
            if ($invitation->getRenewalsUsed() >= ChapterInvitation::RENEWALS_MAX) {
                $invitation->setStatus(ChapterInvitation::STATUS_EXHAUSTED);
                $this->notifyOwner($invitation);
                ++$result['exhausted'];
                continue;
            }
            $invitation->setRenewalsUsed($invitation->getRenewalsUsed() + 1)->setLastSentAt($now)->setExpiresAt(self::expiry($now));
            if ($this->sendLink($invitation, 'renewal', null)) {
                ++$result['renewed'];
            } else {
                $em->refresh($invitation);
                ++$result['failed'];
            }
        }
        $em->flush();

        return $result;
    }

    public static function expiry(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return $from->modify('+' . ChapterInvitation::LINK_DURATION . ' seconds');
    }

    /** @param list<ChapterInvitation> $invitations */
    private function markCompleted(array $invitations): int
    {
        $now = new \DateTimeImmutable();
        foreach ($invitations as $invitation) {
            $invitation->setStatus(ChapterInvitation::STATUS_COMPLETED)->setCompletedAt($now);
        }
        if ($invitations !== []) {
            $this->emProvider->getEntityManager()->flush();
        }

        return count($invitations);
    }

    /** @param 'first'|'resend'|'renewal' $kind */
    private function sendLink(ChapterInvitation $invitation, string $kind, ?Request $request): bool
    {
        $chapter = $invitation->getChapter();
        $book = $chapter?->getBook();
        if ($chapter === null || $book === null) {
            return false;
        }

        $context = [
            'kind' => $kind,
            'bookTitle' => (string) $book->getTitle(),
            'chapterTitle' => (string) $chapter->getTitle(),
            'ownerName' => self::ownerName($book),
            'firstName' => $invitation->getContributor()?->getFirstName(),
            'shareUrl' => $this->signer->url($chapter, $invitation->getContributor(), $invitation->getExpiresAt()->getTimestamp(), $request),
            'expiresAt' => $invitation->getExpiresAt(),
            'renewalsLeft' => $invitation->renewalsLeft(),
            'isLastRenewal' => $kind === 'renewal' && $invitation->renewalsLeft() === 0,
            'unsubscribeUrl' => $this->unsubscribeUrl($invitation),
        ];

        try {
            return $this->emailSender->sendCustomerEmail(
                $invitation->getEmail(),
                'chapter_invitation.subject',
                ['%book%' => (string) $book->getTitle()],
                self::TEMPLATE_LINK,
                $context,
                $invitation->getLocale(),
                $this->frontendUrl->baseUrl($request)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Invitation de chapitre non envoyée : ' . $e->getMessage(), ['invitation' => (string) $invitation->getId()]);

            return false;
        }
    }

    /** Avis au propriétaire du livre : les renouvellements sont épuisés sans réponse complète */
    private function notifyOwner(ChapterInvitation $invitation): void
    {
        $chapter = $invitation->getChapter();
        $book = $chapter?->getBook();
        $owner = $book?->getUser();
        $to = $owner?->getEmail();
        if ($to === null || $to === '') {
            return;
        }

        $domain = $this->frontendUrl->baseUrl(null);
        try {
            $this->emailSender->sendCustomerEmail(
                $to,
                'chapter_invitation.exhausted_subject',
                ['%chapter%' => (string) $chapter->getTitle()],
                self::TEMPLATE_EXHAUSTED,
                [
                    'bookTitle' => (string) $book->getTitle(),
                    'chapterTitle' => (string) $chapter->getTitle(),
                    'email' => $invitation->getEmail(),
                    'firstName' => $invitation->getContributor()?->getFirstName(),
                    'count' => ChapterInvitation::RENEWALS_MAX,
                    'ownerFirstName' => $owner->getFirstname(),
                    'bookUrl' => $domain,
                ],
                null,
                $domain
            );
        } catch (\Throwable $e) {
            $this->logger->error('Avis d\'invitation épuisée non envoyé : ' . $e->getMessage(), ['invitation' => (string) $invitation->getId()]);
        }
    }

    private function unsubscribeUrl(ChapterInvitation $invitation): string
    {
        return sprintf(
            '%s/api/memoires/invitations/unsubscribe/%s/%s',
            $invitation->getBackendOrigin(),
            rawurlencode((string) $this->tenants->getCurrentTenantCode()),
            $invitation->getUnsubscribeToken()
        );
    }

    private static function ownerName(Book $book): string
    {
        $owner = $book->getUser();

        return trim(($owner?->getFirstname() ?? '') . ' ' . ($owner?->getLastname() ?? ''));
    }

    private function repository(): ChapterInvitationRepository
    {
        /** @var ChapterInvitationRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(ChapterInvitation::class);

        return $repository;
    }
}
