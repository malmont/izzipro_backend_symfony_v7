<?php

namespace App\Services\AccountService;

use App\Entity\OtpCode;
use App\Entity\User;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\OtpService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Changement d'adresse courriel du compte (PATCH /api/profile, S7, 31/10/2026) : confirmation exigée SEULEMENT quand l'adresse
 * change réellement (comparaison après trim et en minuscules ; le tunnel de paiement du site renvoie l'adresse actuelle).
 *  - compte avec mot de passe : `currentPassword` dans le corps → 403 PASSWORD_REQUIRED / PASSWORD_INVALID ;
 *  - compte sans mot de passe utilisable ou en connexion par code (otpEnabled) : code envoyé à l'ANCIENNE adresse par le
 *    mécanisme OTP existant (OtpService) → 403 OTP_REQUIRED, puis `otpCode` dans le corps → 403 OTP_INVALID si faux ou expiré ;
 *  - essais limités par compte (limiteur profile_email_change_account, comme la connexion) → 429 TOO_MANY_ATTEMPTS ;
 *  - après le changement : courriel d'information à l'ancienne adresse (sans lien d'annulation pour l'instant), journal sécurité.
 */
final class EmailChangeGuard
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_OTP = 'otp';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwords,
        private readonly OtpService $otp,
        private readonly EmailSenderService $mailer,
        #[Autowire(service: 'limiter.profile_email_change_account')] private readonly RateLimiterFactory $limiter,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    /** true si l'adresse demandée diffère de l'actuelle (après trim et minuscules) ; vide ou absente : jamais un changement */
    public static function isChange(?string $current, mixed $requested): bool
    {
        $new = is_string($requested) ? trim($requested) : '';

        return $new !== '' && mb_strtolower($new) !== mb_strtolower(trim((string) $current));
    }

    public static function method(User $user): string
    {
        return ($user->isOtpEnabled() ?? false) || trim((string) $user->getPassword()) === '' ? self::METHOD_OTP : self::METHOD_PASSWORD;
    }

    /**
     * @return array{status: int, body: array}|null null = changement autorisé
     */
    public function authorize(User $user, array $data, Request $request): ?array
    {
        $limit = $this->limiter->create('user:' . $user->getId())->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $this->securityLogger->warning('profile.email_change.rate_limited', ['userId' => $user->getId(), 'ip' => $request->getClientIp()]);

            return ['status' => 429, 'body' => ['error' => 'TOO_MANY_ATTEMPTS', 'message' => 'Trop d\'essais : réessayez plus tard', 'retryAfterSeconds' => $retry]];
        }
        if (self::method($user) === self::METHOD_PASSWORD) {
            $password = $data['currentPassword'] ?? null;
            if (!is_string($password) || $password === '') {
                return ['status' => 403, 'body' => ['error' => 'PASSWORD_REQUIRED', 'message' => 'Pour changer votre adresse courriel, confirmez votre mot de passe actuel (currentPassword)']];
            }
            if (!$this->passwords->isPasswordValid($user, $password)) {
                $this->securityLogger->warning('profile.email_change.password_invalid', ['userId' => $user->getId(), 'ip' => $request->getClientIp()]);

                return ['status' => 403, 'body' => ['error' => 'PASSWORD_INVALID', 'message' => 'Mot de passe actuel incorrect']];
            }
            $limit->ensureAccepted();
            $this->limiter->create('user:' . $user->getId())->reset();

            return null;
        }
        // Connexion par code : le code part à l'adresse actuelle (l'ancienne), jamais à la nouvelle
        $code = $data['otpCode'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            $this->otp->generateAndSendOtp($user, $request);
            $this->securityLogger->info('profile.email_change.otp_sent', ['userId' => $user->getId()]);

            return ['status' => 403, 'body' => ['error' => 'OTP_REQUIRED', 'message' => 'Un code de confirmation a été envoyé à votre adresse courriel actuelle ; renvoyez la demande avec otpCode', 'sentTo' => self::mask((string) $user->getEmail()), 'lifetimeSeconds' => 300]];
        }
        $em = $this->emProvider->getEntityManager();
        $otp = $em->getRepository(OtpCode::class)->findOneBy(['userOtp' => $user, 'code' => trim($code)]);
        if ($otp === null || $otp->getExpiration() < new \DateTime()) {
            $this->securityLogger->warning('profile.email_change.otp_invalid', ['userId' => $user->getId(), 'ip' => $request->getClientIp()]);

            return ['status' => 403, 'body' => ['error' => 'OTP_INVALID', 'message' => 'Code de confirmation invalide ou expiré']];
        }
        $em->remove($otp);
        $em->flush();
        $this->limiter->create('user:' . $user->getId())->reset();

        return null;
    }

    /** Information à l'ANCIENNE adresse et journal sécurité, après l'enregistrement du changement */
    public function notifyChanged(User $user, string $oldEmail, string $newEmail, string $method, Request $request): void
    {
        $context = ['oldEmail' => $oldEmail, 'newEmail' => self::mask($newEmail), 'firstname' => $user->getFirstname(), 'changedAt' => new \DateTimeImmutable(), 'method' => $method];
        $sent = false;
        try {
            $sent = $this->mailer->sendTemplatedEmail($oldEmail, 'Votre adresse courriel a été modifiée', 'emails/email_changed_notice.html.twig', $context, $request->getLocale(), $request->getSchemeAndHttpHost());
            if (!$sent) {
                $sent = $this->mailer->sendPlatformEmail($oldEmail, 'Votre adresse courriel a été modifiée', 'emails/email_changed_notice.html.twig', $context);
            }
        } catch (\Throwable $e) {
            $this->securityLogger->error('profile.email_change.notice_failed', ['userId' => $user->getId(), 'error' => $e->getMessage()]);
        }
        $this->securityLogger->info('profile.email_changed', ['userId' => $user->getId(), 'from' => $oldEmail, 'to' => $newEmail, 'method' => $method, 'noticeSent' => $sent, 'ip' => $request->getClientIp()]);
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return (mb_substr($local, 0, 1) ?: '*') . str_repeat('*', max(2, mb_strlen($local) - 1)) . ($domain !== '' ? '@' . $domain : '');
    }
}
