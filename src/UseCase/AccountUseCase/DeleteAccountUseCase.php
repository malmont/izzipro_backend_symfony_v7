<?php

namespace App\UseCase\AccountUseCase;

use App\Entity\User;
use App\Services\AccountService\AccountDeletionGuard;
use App\Services\AccountService\PersonalDataEraser;
use App\Services\EmailConfigurationService\EmailSenderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/** DELETE /api/account (01/11/2026) : conditions (AccountDeletionGuard), courriel de confirmation à l'ancienne adresse, effacement */
class DeleteAccountUseCase
{
    public function __construct(
        private readonly AccountDeletionGuard $guard,
        private readonly PersonalDataEraser $eraser,
        private readonly EmailSenderService $mailer,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    /** @return array{status: int, body: array} */
    public function execute(User $user, array $data, Request $request): array
    {
        if ($refused = $this->guard->authorize($user, $data, $request)) {
            return $refused;
        }
        $email = (string) $user->getEmail();
        $userId = (int) $user->getId();
        $context = ['firstname' => $user->getFirstname(), 'email' => $email, 'deletedAt' => new \DateTimeImmutable()];
        $notified = false;
        try {
            $notified = $this->mailer->sendTemplatedEmail($email, 'Votre compte a été supprimé', 'emails/account_deleted_notice.html.twig', $context, $request->getLocale(), $request->getSchemeAndHttpHost());
            if (!$notified) {
                $notified = $this->mailer->sendPlatformEmail($email, 'Votre compte a été supprimé', 'emails/account_deleted_notice.html.twig', $context);
            }
        } catch (\Throwable $e) {
            $this->securityLogger->error('account.delete.notice_failed', ['userId' => $userId, 'error' => $e->getMessage()]);
        }
        $result = $this->eraser->erase($user);
        $this->securityLogger->info('account.deleted', ['userId' => $userId, 'noticeSent' => $notified, 'ip' => $request->getClientIp()] + $result);

        return ['status' => 200, 'body' => ['deleted' => true] + $result];
    }
}
