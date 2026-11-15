<?php

namespace App\Import;

use App\Entity\NewsletterSubscriber;
use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\AppMembershipService\AppTermsService;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\Site\SiteName;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot IMP-2 (`docs/import-boutique.md` § 9) : le lien d'invitation d'un client repris d'une ancienne boutique, côté client. Trois
 * gestes, tous PUBLICS (la preuve est le jeton du lien, à usage unique) : lire l'invitation, l'accepter, la refuser.
 *
 * Accepter : le client choisit son mot de passe et accepte les conditions de la boutique, dans leur version du moment. C'est à cet
 * instant seulement que son compte devient un compte de client (vérifié, rôle de client) et que naît son appartenance à la famille
 * `boutique`, de source `import`, avec la version acceptée et la date. Les courriels commerciaux ne sont repris que si le fichier le
 * disait, si le commerçant l'a attesté, et si le client ne l'a pas décoché.
 * Refuser : tout ce qui a été repris est effacé tout de suite.
 * Un lien inconnu, expiré, déjà servi ou d'un compte effacé rend toujours le même refus (`IMPORT_INVITATION_INVALID`).
 */
class ImportInvitationService
{
    public const MIN_PASSWORD = 8;
    public const MAX_PASSWORD = 4096;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ImportGuests $guests,
        private readonly AppMembershipService $memberships,
        private readonly AppTermsService $terms,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SiteName $siteName,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ce que la page du lien affiche avant tout choix.
     *
     * @return array<string, mixed> { firstname, email, site, expiresAt, marketing, terms }
     */
    public function show(mixed $token, string $host, mixed $locale): array
    {
        [$invitation, $user] = $this->open($token);

        return ['firstname' => (string) $user->getFirstname(), 'email' => (string) $user->getEmail(), 'site' => $this->siteName->name(),
            'expiresAt' => (new \DateTimeImmutable((string) $invitation['expires_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            // vrai : l'ancienne boutique lui envoyait ses courriels commerciaux (dit par le fichier, attesté par le commerçant) ; il peut le décocher
            'marketing' => (bool) $invitation['marketing_claimed'], 'terms' => $this->termsOf($host, $locale)];
    }

    /**
     * @return array{accepted: true, email: string, marketing: bool}
     *
     * @throws ImportException 404 IMPORT_INVITATION_INVALID ; 503 IMPORT_INVITATION_FAILED (rien d'écrit, le lien reste valable) ; 422 VALIDATION_FAILED (fields.password REQUIRED | TOO_SHORT | TOO_LONG, fields.marketing MUST_BE_BOOLEAN) ;
     *                         422 TERMS_NOT_ACCEPTED ; 409 TERMS_VERSION_CHANGED (avec les conditions du moment ; le lien reste valable)
     */
    public function accept(mixed $token, mixed $password, mixed $termsVersion, mixed $accepted, mixed $marketing, string $host, mixed $locale): array
    {
        [$invitation, $user] = $this->open($token);
        $fields = [];
        if (!is_string($password) || $password === '') {
            $fields['password'] = 'REQUIRED';
        } elseif (mb_strlen($password) < self::MIN_PASSWORD) {
            $fields['password'] = 'TOO_SHORT';
        } elseif (strlen($password) > self::MAX_PASSWORD) {
            $fields['password'] = 'TOO_LONG';
        }
        if ($marketing !== null && !is_bool($marketing)) {
            $fields['marketing'] = 'MUST_BE_BOOLEAN';
        }
        if ($fields !== []) {
            throw new ImportException('VALIDATION_FAILED', 422, ['fields' => $fields]);
        }
        if ($accepted !== true) {
            throw new ImportException('TERMS_NOT_ACCEPTED', 422);
        }
        $version = $this->terms->version(AppFamilies::BOUTIQUE);
        if ((is_string($termsVersion) && $termsVersion !== '' ? $termsVersion : null) !== $version) {
            throw new ImportException('TERMS_VERSION_CHANGED', 409, ['terms' => $this->termsOf($host, $locale)]);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $userId = (int) $user->getId();
        $subscribe = (bool) $invitation['marketing_claimed'] && $marketing !== false;
        $conn->beginTransaction();
        try {
            if (!$this->guests->accept((int) $invitation['id'])) {
                throw new ImportException('IMPORT_INVITATION_INVALID', 404); // le lien vient de servir, ou d'expirer
            }
            // le mot de passe d'abord : une appartenance ne se crée que pour un compte qui a choisi le sien
            $user->setPassword($this->hasher->hashPassword($user, $password))->setPasswordSet(true)->setIsVerified(true)->setRoles(['ROLE_USER_INTERNET']);
            $em->flush();
            $this->memberships->grant($user, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_IMPORT, $version, null, new \DateTimeImmutable());
            if (!$this->memberships->has($user, AppFamilies::BOUTIQUE)) {
                throw new \RuntimeException('appartenance non créée');
            }
            if ($subscribe && $conn->fetchOne('SELECT 1 FROM newsletter_subscriber WHERE LOWER(email) = ?', [mb_strtolower((string) $user->getEmail())]) === false) {
                $em->persist((new NewsletterSubscriber())->setEmail((string) $user->getEmail())->setSubscribedAt(new \DateTimeImmutable()));
                $em->flush();
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $em->clear(); // rien de ce que cette acceptation avait préparé ne doit partir plus tard
            if ($e instanceof ImportException) {
                throw $e;
            }
            $this->logger->error('[Import] acceptation d\'une invitation en échec (' . $e::class . ').');
            throw new ImportException('IMPORT_INVITATION_FAILED', 503); // rien n'a été écrit : le lien reste valable, le client peut réessayer
        }
        $this->journal($userId, 'accept', ['importId' => (int) $invitation['job_id'], 'termsVersion' => $version, 'marketing' => $subscribe]);

        return ['accepted' => true, 'email' => (string) $user->getEmail(), 'marketing' => $subscribe];
    }

    /**
     * « Ne pas rouvrir mon compte » : ce qui a été repris est effacé tout de suite. Un compte qu'une commande tient est gardé (il
     * devient un compte d'achat sans compte ordinaire) ; dans les deux cas le lien ne vaut plus rien.
     *
     * @return array{declined: true}
     */
    public function decline(mixed $token): array
    {
        [$invitation, $user] = $this->open($token);
        $userId = (int) $user->getId();
        $this->emProvider->getEntityManager()->clear();
        $outcome = $this->guests->erase($userId);
        $this->journal($userId, 'decline', ['importId' => (int) $invitation['job_id'], 'outcome' => $outcome]);

        return ['declined' => true];
    }

    /** Écrit au journal du site ; un journal en échec n'annule jamais le geste déjà fait */
    private function journal(int $userId, string $action, array $after): void
    {
        try {
            $this->audit->record('user_import', $userId, $action, null, $after, ['state']);
        } catch (\Throwable $e) {
            $this->logger->error('[Import] journal d\'une invitation non écrit (' . $e::class . ').');
        }
    }

    /** @return array{0: array<string, mixed>, 1: User} l'invitation ouverte par ce jeton et son compte */
    private function open(mixed $token): array
    {
        $invalid = new ImportException('IMPORT_INVITATION_INVALID', 404);
        $invitation = $this->guests->byToken($token) ?? throw $invalid;
        $user = $this->emProvider->getEntityManager()->find(User::class, (int) $invitation['user_id']);
        if (!$user instanceof User || $user->isDeleted() || $user->isPasswordSet() || AppFamilies::isTeam($user)) {
            throw $invalid; // un compte qui a déjà un mot de passe n'est plus un invité : ce lien ne lui en donnerait pas un autre
        }

        return [$invitation, $user];
    }

    /** @return array<string, mixed> les conditions de la boutique, telles que l'inscription les lit */
    private function termsOf(string $host, mixed $locale): array
    {
        return array_diff_key($this->terms->terms(AppFamilies::BOUTIQUE, $host, AppTermsService::locale($locale)), ['app' => 1]);
    }
}
