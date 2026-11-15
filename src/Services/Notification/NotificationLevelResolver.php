<?php

namespace App\Services\Notification;

use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * Le niveau des courriels de suivi qui s'applique (lot NOTIF) : celui que le compte a choisi, sinon celui du site.
 * Niveau du site : le réglage de la fiche du site (réservé à la plateforme), sinon « automatique » : `essential` pour un site d'essai,
 * `normal` pour un site en activité. Un site est « en activité » quand la plateforme est en mode « live » ET qu'il a au moins un droit
 * actif PAYÉ (source `subscription` ou `purchase`) ; le mode « demo » de la plateforme, un essai, un droit offert ou accordé à la main
 * n'en font pas un site en activité (décision du propriétaire).
 */
class NotificationLevelResolver
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';
    public const REASON_PLATFORM_DEMO = 'platform_demo';
    public const REASON_TRIAL = 'trial';
    public const REASON_ACTIVE = 'active';
    private const PAID_SOURCES = ['subscription', 'purchase'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly EntitlementService $entitlements,
        private readonly PlatformModeService $platform,
    ) {
    }

    /** @return array{value: string, mode: string, reason: ?string} le niveau du site ; `reason` seulement en mode automatique */
    public function site(): array
    {
        $manual = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT notification_level FROM entreprise ORDER BY id LIMIT 1');
        if (NotificationLevel::isValid($manual)) {
            return ['value' => $manual, 'mode' => self::MODE_MANUAL, 'reason' => null];
        }
        if ($this->platform->isDemo()) {
            return ['value' => NotificationLevel::ESSENTIAL, 'mode' => self::MODE_AUTO, 'reason' => self::REASON_PLATFORM_DEMO];
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        foreach ($code !== '' ? $this->entitlements->effective($code) : [] as $right) {
            if (($right['enabled'] ?? false) && in_array($right['source'] ?? null, self::PAID_SOURCES, true)) {
                return ['value' => NotificationLevel::NORMAL, 'mode' => self::MODE_AUTO, 'reason' => self::REASON_ACTIVE];
            }
        }

        return ['value' => NotificationLevel::ESSENTIAL, 'mode' => self::MODE_AUTO, 'reason' => self::REASON_TRIAL];
    }

    /** Le niveau que ce compte a choisi, ou null : « comme le site » */
    public function user(int $userId): ?string
    {
        $level = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT notification_level FROM "user" WHERE id = ?', [$userId]);

        return NotificationLevel::isValid($level) ? $level : null;
    }

    /** @return array{value: string, source: string, userLevel: ?string, siteLevel: array{value: string, mode: string, reason: ?string}} */
    public function forUser(int $userId): array
    {
        $site = $this->site();
        $own = $this->user($userId);

        return ['value' => $own ?? $site['value'], 'source' => $own !== null ? 'user' : 'site', 'userLevel' => $own, 'siteLevel' => $site];
    }

    /**
     * Le niveau qui s'applique à une adresse : celui du compte de l'ÉQUIPE du site qui la porte (administrateur, agent d'assistance ou
     * propriétaire de la plateforme, vérifié, non supprimé : son choix, sinon le site), sinon celui du site (adresse de contact qui n'est
     * celle d'aucun compte de l'équipe ; un compte client à la même adresse ne règle rien).
     *
     * @return array{level: string, userId: ?int}
     */
    public function forAddress(string $email): array
    {
        $id = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT id FROM "user" WHERE LOWER(email) = LOWER(?) AND deleted_at IS NULL AND is_verified = true'
            . " AND (roles::text LIKE '%\"ROLE_ADMIN\"%' OR roles::text LIKE '%\"ROLE_SUPPORT\"%' OR roles::text LIKE '%\"ROLE_SUPER_ADMIN\"%') ORDER BY id LIMIT 1", [trim($email)]);
        if ($id === false) {
            return ['level' => $this->site()['value'], 'userId' => null];
        }

        return ['level' => $this->forUser((int) $id)['value'], 'userId' => (int) $id];
    }
}
