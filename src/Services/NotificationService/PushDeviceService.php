<?php

namespace App\Services\NotificationService;

use App\Entity\PushDevice;
use App\Repository\PushDeviceRepository;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\TenantEntityManagerProvider;

/**
 * Appareils inscrits aux notifications (lot N1, 11/11/2026, `docs/notifications-gestion.md`), générique pour toutes les applications :
 * un jeton est rattaché au site où il est inscrit (base du site) ; le même téléphone sur deux sites a deux inscriptions indépendantes.
 */
class PushDeviceService
{
    public const PLATFORMS = ['admin', 'mobile', 'pos'];
    /** rôles exigés par application (mobile : tout utilisateur connecté) */
    public const PLATFORM_ROLES = ['admin' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'pos' => ['ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * @param list<string> $roles rôles de l'utilisateur connecté
     * @return array{created: bool, device: array<string, mixed>}
     */
    public function register(int $userId, array $roles, array $body): array
    {
        $fields = [];
        $token = is_string($body['token'] ?? null) ? trim($body['token']) : '';
        if ($token === '') {
            $fields['token'] = 'REQUIRED';
        } elseif (mb_strlen($token) > 512) {
            $fields['token'] = 'TOO_LONG';
        }
        $platform = is_string($body['platform'] ?? null) ? strtolower(trim($body['platform'])) : '';
        if ($platform === '') {
            $fields['platform'] = 'REQUIRED';
        } elseif (!in_array($platform, self::PLATFORMS, true)) {
            $fields['platform'] = 'INVALID_PLATFORM';
        }
        $locale = $body['locale'] ?? null;
        if ($locale !== null && (!is_string($locale) || !in_array(strtolower($locale), ['fr', 'en'], true))) {
            $fields['locale'] = 'INVALID_LOCALE';
        }
        if ($fields !== []) {
            throw new GestionValidationException($fields);
        }
        if (isset(self::PLATFORM_ROLES[$platform]) && array_intersect($roles, self::PLATFORM_ROLES[$platform]) === []) {
            throw new GestionConflictException('PLATFORM_FORBIDDEN', 'Application réservée à d\'autres comptes du site.', 403, ['platform' => $platform]);
        }

        return $this->repo()->register($userId, $platform, $token, self::short($body['deviceName'] ?? null, 120), is_string($locale) ? strtolower($locale) : null, self::short($body['appVersion'] ?? null, 40));
    }

    public function unregister(string $token, int $userId): void
    {
        $this->repo()->disable($token, 'logout', $userId);
    }

    private function repo(): PushDeviceRepository
    {
        /** @var PushDeviceRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(PushDevice::class);

        return $repo;
    }

    private static function short(mixed $v, int $max): ?string
    {
        $t = is_scalar($v) ? trim((string) $v) : '';

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
