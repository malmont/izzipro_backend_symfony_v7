<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Code à usage unique envoyé par e-mail à la connexion (OtpService) : demandé si le compte l'a activé (otpEnabled,
 * EasyAdmin), et pour tout administrateur quand ADMIN_OTP_REQUIRED=1 dans .env. Un administrateur peut modifier tout
 * le contenu public d'un site depuis l'éditeur des landing pages.
 *
 * Avant d'activer ADMIN_OTP_REQUIRED : l'adresse e-mail de chaque compte administrateur doit recevoir le courrier
 * (sinon le compte ne peut plus se connecter), et l'éditeur doit savoir demander le code (réponse otp_required, puis
 * POST /api/otp-verify).
 */
final class TwoFactorPolicy
{
    public const ADMIN_ROLES = ['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN];

    public function __construct(
        #[Autowire('%env(bool:default::ADMIN_OTP_REQUIRED)%')]
        private readonly bool $adminOtpRequired = false
    ) {
    }

    public function required(User $user): bool
    {
        return (bool) $user->isOtpEnabled()
            || ($this->adminOtpRequired && array_intersect(self::ADMIN_ROLES, $user->getRoles()) !== []);
    }

    public function adminOtpRequired(): bool
    {
        return $this->adminOtpRequired;
    }
}
