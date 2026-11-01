<?php

namespace App\Services\AccountService;

use App\Entity\OtpCode;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\OtpService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Conditions de la suppression d'un compte de la boutique (DELETE /api/account, 01/11/2026), même mécanique que le
 * changement de courriel (EmailChangeGuard) :
 *  - 403 ADMIN_ACCOUNT : un administrateur se supprime depuis la console ;
 *  - 429 TOO_MANY_ATTEMPTS (limiteur account_delete_account, par compte) ;
 *  - 409 SUBSCRIPTION_ACTIVE : un abonnement Stripe en cours doit d'abord être résilié par le client ;
 *  - 409 RESERVATION_UPCOMING : une réservation à venir doit d'abord être annulée ;
 *  - confirmation : `password` pour un compte avec mot de passe choisi (403 PASSWORD_REQUIRED / PASSWORD_INVALID), sinon
 *    `otpCode` envoyé au courriel du compte (403 OTP_REQUIRED avec sentTo et lifetimeSeconds / OTP_INVALID).
 */
final class AccountDeletionGuard
{
    private const BLOCKING_SUBSCRIPTION_STATUSES = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE, Subscription::STATUS_PAUSED];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwords,
        private readonly OtpService $otp,
        #[Autowire(service: 'limiter.account_delete_account')] private readonly RateLimiterFactory $limiter,
        private readonly LoggerInterface $securityLogger,
        private readonly ?TenantConnectionProvider $tenant = null,
    ) {
    }

    /** Compteur par site et par compte (01/11/2026) */
    private function limiterKey(User $user): string
    {
        return ($this->tenant?->getTenantCode() ?? 'default') . ':user:' . $user->getId();
    }

    /** @return array{status: int, body: array}|null null = suppression autorisée */
    public function authorize(User $user, array $data, Request $request): ?array
    {
        if (array_intersect(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $user->getRoles()) !== []) {
            return ['status' => 403, 'body' => ['error' => 'ADMIN_ACCOUNT', 'message' => 'Un compte administrateur ne peut pas être supprimé depuis l\'application.']];
        }
        $limit = $this->limiter->create($this->limiterKey($user))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $this->securityLogger->warning('account.delete.rate_limited', ['userId' => $user->getId(), 'ip' => $request->getClientIp()]);

            return ['status' => 429, 'body' => ['error' => 'TOO_MANY_ATTEMPTS', 'message' => 'Trop d\'essais : réessayez plus tard', 'retryAfterSeconds' => $retry]];
        }
        if ($blocked = $this->blocker($user)) {
            return $blocked;
        }
        if (EmailChangeGuard::method($user) === EmailChangeGuard::METHOD_PASSWORD) {
            $password = $data['password'] ?? $data['currentPassword'] ?? null;
            if (!is_string($password) || $password === '') {
                return ['status' => 403, 'body' => ['error' => 'PASSWORD_REQUIRED', 'message' => 'Pour supprimer votre compte, confirmez votre mot de passe (password)']];
            }
            if (!$this->passwords->isPasswordValid($user, $password)) {
                $this->securityLogger->warning('account.delete.password_invalid', ['userId' => $user->getId(), 'ip' => $request->getClientIp()]);

                return ['status' => 403, 'body' => ['error' => 'PASSWORD_INVALID', 'message' => 'Mot de passe incorrect']];
            }
            $this->limiter->create($this->limiterKey($user))->reset();

            return null;
        }
        $code = $data['otpCode'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            $this->otp->generateAndSendOtp($user, $request, OtpCode::PURPOSE_ACCOUNT_DELETION);
            $this->securityLogger->info('account.delete.otp_sent', ['userId' => $user->getId()]);

            return ['status' => 403, 'body' => ['error' => 'OTP_REQUIRED', 'message' => 'Un code de confirmation a été envoyé à votre adresse courriel ; renvoyez la demande avec otpCode', 'sentTo' => EmailChangeGuard::mask((string) $user->getEmail()), 'lifetimeSeconds' => 300]];
        }
        $em = $this->emProvider->getEntityManager();
        $otp = $em->getRepository(OtpCode::class)->findOneBy(['userOtp' => $user, 'code' => trim($code), 'purpose' => OtpCode::PURPOSE_ACCOUNT_DELETION]);
        if ($otp === null || $otp->getExpiration() < new \DateTime()) {
            $this->securityLogger->warning('account.delete.otp_invalid', ['userId' => $user->getId(), 'ip' => $request->getClientIp()]);

            return ['status' => 403, 'body' => ['error' => 'OTP_INVALID', 'message' => 'Code de confirmation invalide ou expiré']];
        }
        $em->remove($otp);
        $em->flush();
        $this->limiter->create($this->limiterKey($user))->reset();

        return null;
    }

    /** Abonnement en cours ou réservation à venir : le client les règle d'abord (le plus sûr : rien n'est résilié à son insu) */
    private function blocker(User $user): ?array
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $subscriptions = (int) $connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE user_id = ? AND status IN (?, ?, ?, ?)', [$user->getId(), ...self::BLOCKING_SUBSCRIPTION_STATUSES]);
        if ($subscriptions > 0) {
            return ['status' => 409, 'body' => ['error' => 'SUBSCRIPTION_ACTIVE', 'message' => 'Un abonnement est en cours : résiliez-le avant de supprimer votre compte', 'count' => $subscriptions]];
        }
        try {
            $reservations = (int) $connection->fetchOne("SELECT COUNT(*) FROM reservation WHERE LOWER(client_email) = LOWER(?) AND reservation_date >= CURRENT_DATE AND status <> 'cancelled'", [(string) $user->getEmail()]);
        } catch (\Throwable) {
            $reservations = 0; // site sans module de réservation
        }
        if ($reservations > 0) {
            return ['status' => 409, 'body' => ['error' => 'RESERVATION_UPCOMING', 'message' => 'Une réservation à venir est enregistrée : annulez-la avant de supprimer votre compte', 'count' => $reservations]];
        }

        return null;
    }
}
