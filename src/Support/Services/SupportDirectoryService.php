<?php

namespace App\Support\Services;

use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\CaisseService\SiteLocaleResolver;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;

/**
 * Ce que le web doit savoir pour afficher l'Assistance à un compte qui n'ouvre que le module (lot SUPPORT-WEB, 14/11/2026,
 * `docs/assistance.md`) : qui est connecté et dans quel état est le module (`me`), et qui peut recevoir une demande (`agents`).
 * Un compte Assistance seul ne lit ni son profil ni les fonctionnalités du site : ces deux réponses ne portent rien d'autre du site.
 */
class SupportDirectoryService
{
    public const FEATURE = 'module.support';
    /** Rôles d'accès autres que l'Assistance : un compte qui en porte un n'est pas « Assistance seul » (la liste de `SupportScopeSubscriber`) */
    private const OTHER_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_INTERNET', 'ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_COMPANY', 'ROLE_CONSULTANT'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly EntitlementService $entitlements,
        private readonly PlatformModeService $platform,
        private readonly SiteLocaleResolver $locale,
        private readonly StoreClock $clock,
    ) {
    }

    /**
     * GET /api/support/me : le compte connecté et l'état du module pour ce site.
     *
     * @return array<string, mixed>
     */
    public function me(User $user): array
    {
        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER']));
        $supportOnly = in_array('ROLE_SUPPORT', $roles, true) && array_intersect(self::OTHER_ROLES, $roles) === [];
        $code = (string) $this->tenants->getCurrentTenantCode();
        $site = $code !== '' ? $this->hosts->describe($code) : null;

        return [
            'id' => (int) $user->getId(), 'name' => self::name($user), 'email' => $user->getEmail(), 'roles' => $roles, 'supportOnly' => $supportOnly,
            'site' => ['code' => $code, 'name' => (string) ($site['name'] ?? $code), 'locale' => $this->locale->siteLocale(), 'timezone' => $this->clock->timezone()->getName()],
            'module' => $this->module($code),
            // un agent lit les réglages de l'Assistance, seul l'administrateur du site les change
            'screens' => ['assistance', 'assistance-reglages'],
            'canEditSettings' => in_array('ROLE_ADMIN', $roles, true),
        ];
    }

    /**
     * État du module pour ce site : `active` (droit accordé), `trial` (essai en cours, avec sa fin), `inactive`. En mode « demo » de la
     * plateforme les routes répondent même sans le droit : `platformMode` le dit.
     *
     * @return array{key: string, state: string, endsAt: ?string, platformMode: string}
     */
    public function module(string $tenantCode): array
    {
        $right = $tenantCode !== '' ? ($this->entitlements->effective($tenantCode)[self::FEATURE] ?? null) : null;
        $enabled = (bool) ($right['enabled'] ?? false);
        $trial = $enabled && ($right['source'] ?? null) === 'trial';

        return ['key' => self::FEATURE, 'state' => $enabled ? ($trial ? 'trial' : 'active') : 'inactive', 'endsAt' => $enabled ? ($right['endsAtIso'] ?? null) : null,
            'platformMode' => $this->platform->isDemo() ? 'demo' : 'live'];
    }

    /** Le droit du module est-il accordé au site ? (le droit lui-même, pas le mode « demo » de la plateforme) */
    public function moduleGranted(): bool
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $code !== '' && $this->entitlements->isEnabled($code, self::FEATURE);
    }

    /**
     * GET /api/support/agents : les comptes à qui une demande peut être attribuée (agents d'assistance et administrateurs du site,
     * vérifiés). Un nom, jamais un courriel ; le propriétaire de la plateforme n'y figure pas, SAUF pour lui-même : le compte connecté
     * est toujours dans sa propre liste (`me: true`), parce qu'il peut s'attribuer une demande (15/11/2026). C'est aussi la règle de
     * `PATCH /api/support/tickets/{id}` (`assignable`) : la liste et l'attribution acceptent exactement les mêmes comptes.
     *
     * @return array{agents: list<array{id: int, name: string, role: string, me: bool}>}
     */
    public function agents(User $me): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, firstname, lastname, roles::text AS roles FROM "user" WHERE deleted_at IS NULL AND is_verified = true'
            . " AND (roles::text LIKE '%\"ROLE_SUPPORT\"%' OR roles::text LIKE '%\"ROLE_ADMIN\"%') AND roles::text NOT LIKE '%\"ROLE_SUPER_ADMIN\"%' ORDER BY id"
        );
        $agents = [];
        foreach ($rows as $r) {
            $name = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
            $agents[] = ['id' => (int) $r['id'], 'name' => $name !== '' ? $name : sprintf('Compte n° %d', (int) $r['id']),
                'role' => str_contains((string) $r['roles'], '"ROLE_ADMIN"') ? 'admin' : 'support', 'me' => (int) $r['id'] === (int) $me->getId()];
        }
        if (!in_array((int) $me->getId(), array_column($agents, 'id'), true)) {
            // le compte connecté hors de la requête (propriétaire de la plateforme) : dans sa propre liste seulement
            $alone = array_values(array_diff($me->getRoles(), ['ROLE_USER'])) === ['ROLE_SUPPORT'];
            $agents[] = ['id' => (int) $me->getId(), 'name' => self::name($me), 'role' => $alone ? 'support' : 'admin', 'me' => true];
        }
        usort($agents, fn (array $a, array $b) => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return ['agents' => $agents];
    }

    /** Ce compte peut-il recevoir une demande attribuée par `$by` ? Exactement les comptes que `agents($by)` lui montre */
    public function assignable(int $userId, User $by): bool
    {
        return in_array($userId, array_column($this->agents($by)['agents'], 'id'), true);
    }

    public static function name(User $user): string
    {
        $name = trim($user->getFirstname() . ' ' . $user->getLastname());

        return $name !== '' ? $name : sprintf('Compte n° %d', (int) $user->getId());
    }
}
