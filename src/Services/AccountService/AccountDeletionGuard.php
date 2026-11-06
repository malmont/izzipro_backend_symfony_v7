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

    /** Rôles qui font d'un compte un compte interne du site (jamais effaçable par l'action du back-office, B19, 06/11/2026) */
    public const INTERNAL_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_POS', 'ROLE_POS_MANAGER'];

    /** Compte client du site : connecté au site web ou à l'application, sans aucun rôle interne */
    public static function isCustomerAccount(array $roles): bool
    {
        return in_array('ROLE_USER_INTERNET', $roles, true) && array_intersect(self::INTERNAL_ROLES, $roles) === [];
    }

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
        if ($blocked = $this->blockers($user)) {
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

    /**
     * Abonnement en cours ou réservation à venir : le client les règle d'abord (le plus sûr : rien n'est résilié à son insu).
     * Public depuis B19 (06/11/2026) : l'action du back-office applique les mêmes refus, sans mot de passe ni code.
     *
     * @return array{status: int, body: array}|null le premier refus (409), null si rien ne bloque
     */
    public function blockers(User $user): ?array
    {
        $items = $this->blockingItems($user);
        if ($items['subscriptions'] !== []) {
            return ['status' => 409, 'body' => ['error' => 'SUBSCRIPTION_ACTIVE', 'message' => 'Un abonnement est en cours : résiliez-le avant de supprimer votre compte', 'count' => count($items['subscriptions'])]];
        }
        if ($items['reservations'] !== []) {
            return ['status' => 409, 'body' => ['error' => 'RESERVATION_UPCOMING', 'message' => 'Une réservation à venir est enregistrée : annulez-la avant de supprimer votre compte', 'count' => count($items['reservations'])]];
        }

        return null;
    }

    /**
     * Ce qui bloque, avec les identifiants (la page de confirmation du back-office y mène par un lien) : abonnements Stripe
     * `active`, `trialing`, `past_due` ou `paused` ; réservations non annulées à venir au courriel du compte.
     *
     * @return array{subscriptions: list<array{id: int, status: string}>, reservations: list<array{id: int, date: string}>}
     */
    public function blockingItems(User $user): array
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $subscriptions = $connection->fetchAllAssociative('SELECT id, status FROM subscription WHERE user_id = ? AND status IN (?, ?, ?, ?) ORDER BY id', [$user->getId(), ...self::BLOCKING_SUBSCRIPTION_STATUSES]);
        try {
            $reservations = $connection->fetchAllAssociative("SELECT id, reservation_date AS date FROM reservation WHERE LOWER(client_email) = LOWER(?) AND reservation_date >= CURRENT_DATE AND status <> 'cancelled' ORDER BY reservation_date, id", [(string) $user->getEmail()]);
        } catch (\Throwable) {
            $reservations = []; // site sans module de réservation
        }

        return [
            'subscriptions' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'status' => (string) $r['status']], $subscriptions),
            'reservations' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'date' => substr((string) $r['date'], 0, 10)], $reservations),
        ];
    }
}
