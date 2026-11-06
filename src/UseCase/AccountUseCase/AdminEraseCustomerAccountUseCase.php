<?php

namespace App\UseCase\AccountUseCase;

use App\Entity\User;
use App\MemoiresVivantes\Services\AccountDeletionService;
use App\Services\AccountService\AccountDeletionGuard;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Action « Effacer ce compte client » du back-office (B19, 06/11/2026) : une boutique qui reçoit une demande écrite de
 * suppression (page publique exigée par Google Play) efface le compte avec les mêmes effets que `DELETE /api/account`,
 * sans mot de passe ni code (c'est l'administrateur qui agit), et avec les mêmes refus (abonnement en cours, réservation à
 * venir). Un compte Mémoires Vivantes perd aussi ses livres, comme depuis l'application (AccountDeletionService, qui
 * appelle PersonalDataEraser). Ordre : effacement d'abord, courriel ensuite à l'adresse relevée avant (un envoi avant un
 * effacement raté annoncerait une suppression qui n'a pas eu lieu) ; un échec d'envoi n'annule rien, il est journalisé et
 * rendu à l'administrateur. Trace : journal sécurité et `content_audit_log` (identifiants et compteurs, aucune donnée
 * personnelle du client).
 */
class AdminEraseCustomerAccountUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AccountDeletionGuard $guard,
        private readonly AccountDeletionService $deletion,
        private readonly EmailSenderService $mailer,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    /**
     * Ce que la page de confirmation affiche avant d'agir.
     *
     * @return array{customer: bool, erased: bool, self: bool, orders: int, books: int, blockers: array{subscriptions: list<array{id: int, status: string}>, reservations: list<array{id: int, date: string}>}, allowed: bool, reason: ?string}
     */
    public function preview(User $target, User $actor): array
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $customer = AccountDeletionGuard::isCustomerAccount($target->getRoles());
        $erased = $target->getDeletedAt() !== null;
        $self = (int) $actor->getId() === (int) $target->getId();
        $blockers = $customer && !$erased ? $this->guard->blockingItems($target) : ['subscriptions' => [], 'reservations' => []];
        $books = 0;
        try {
            $books = (int) $connection->fetchOne('SELECT COUNT(*) FROM mv_book WHERE user_id = ?', [$target->getId()]);
        } catch (\Throwable) {
            // site sans Mémoires Vivantes
        }
        $reason = match (true) {
            $self => 'SELF_ACCOUNT',
            $erased => 'ALREADY_ERASED', // avant le test des rôles : un compte effacé n'en a plus
            !$customer => 'NOT_CUSTOMER_ACCOUNT',
            $blockers['subscriptions'] !== [] => 'SUBSCRIPTION_ACTIVE',
            $blockers['reservations'] !== [] => 'RESERVATION_UPCOMING',
            default => null,
        };

        return [
            'customer' => $customer,
            'erased' => $erased,
            'self' => $self,
            'orders' => (int) $connection->fetchOne('SELECT COUNT(*) FROM "order" WHERE user_id_id = ?', [$target->getId()]),
            'books' => $books,
            'blockers' => $blockers,
            'allowed' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{status: int, body: array} 200 avec le détail (mêmes clés que l'API, plus booksDeleted, printOrdersAnonymized,
     *                                        noticeSent) ; 403 SELF_ACCOUNT / NOT_CUSTOMER_ACCOUNT ; 409 ALREADY_ERASED /
     *                                        SUBSCRIPTION_ACTIVE / RESERVATION_UPCOMING
     */
    public function execute(User $target, User $actor, Request $request): array
    {
        $preview = $this->preview($target, $actor);
        if (!$preview['allowed']) {
            $status = in_array($preview['reason'], ['SELF_ACCOUNT', 'NOT_CUSTOMER_ACCOUNT'], true) ? 403 : 409;
            $this->securityLogger->warning('account.admin_erase.refused', ['targetUserId' => $target->getId(), 'actorUserId' => $actor->getId(), 'reason' => $preview['reason']]);

            return ['status' => $status, 'body' => ['error' => $preview['reason'], 'message' => self::MESSAGES[$preview['reason']]]];
        }

        $targetId = (int) $target->getId();
        $email = (string) $target->getEmail();
        $firstname = $target->getFirstname();
        $deletedAt = new \DateTimeImmutable();

        $result = $this->deletion->erase($target);

        $notified = false;
        try {
            // Langue du site (aucun ?locale= : la demande est arrivée par écrit) ; repli par le serveur de la plateforme
            $notified = $this->mailer->sendCustomerEmail($email, 'account_deleted.subject', [], 'emails/account_deleted_notice.html.twig', ['firstname' => $firstname, 'email' => $email, 'deletedAt' => $deletedAt], null, $request->getSchemeAndHttpHost(), 'account_deleted.subject');
        } catch (\Throwable $e) {
            $this->securityLogger->error('account.admin_erase.notice_failed', ['targetUserId' => $targetId, 'error' => $e->getMessage()]);
        }
        $trace = ['actorUserId' => (int) $actor->getId(), 'noticeSent' => $notified] + $result;
        $this->securityLogger->info('account.deleted_by_admin', ['targetUserId' => $targetId, 'ip' => $request->getClientIp()] + $trace);
        $this->audit->record('user', $targetId, 'erase', null, $trace, ['email', 'firstname', 'lastname', 'password', 'roles', 'addresses']);

        return ['status' => 200, 'body' => ['deleted' => true, 'noticeSent' => $notified] + $result];
    }

    public const MESSAGES = [
        'SELF_ACCOUNT' => 'Vous ne pouvez pas effacer votre propre compte.',
        'NOT_CUSTOMER_ACCOUNT' => 'Seul un compte client du site peut être effacé ici : les comptes administrateur et caisse se gèrent par la fiche.',
        'ALREADY_ERASED' => 'Ce compte a déjà été effacé.',
        'SUBSCRIPTION_ACTIVE' => 'Un abonnement est en cours : résiliez-le d\'abord depuis Abonnements.',
        'RESERVATION_UPCOMING' => 'Une réservation à venir est enregistrée : annulez-la d\'abord depuis Réservations.',
    ];
}
