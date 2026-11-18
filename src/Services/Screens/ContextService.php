<?php

namespace App\Services\Screens;

use App\Security\AgencyRoles;
use App\Services\Agency\AgencyRepository;
use App\Services\Platform\Sites\PlatformSiteService;
use App\Services\TenantConnectionManager;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Les CONTEXTES du compte connecté (lot AG-3a, 16/11/2026, `docs/ecrans-et-contextes.md`) : son mode, les sites qu'il peut ouvrir et,
 * pour chacun, ses pastilles, son état, ses modules actifs et les codes des écrans qu'un menu peut lui montrer. Les fronts
 * construisent leur sélecteur et leur menu de cette réponse, sans coder les règles d'un mode.
 *
 * Le mode se CALCULE depuis les liens réels (rôles du compte, agence dont le site courant est l'attache), jamais d'un champ tenu à la
 * main. Ce lot ne change aucun droit : la réponse dit ce qui peut se MONTRER ; refuser est l'affaire des gardes (AG-3b pour l'entrée).
 *
 * Un compte ne voit QUE les sites qu'il peut ouvrir : le site de sa connexion ; pour un compte d'agence, les sites que son agence
 * gère ; pour le super administrateur, tous les sites.
 */
class ContextService
{
    public const MODE_SINGLE = 'single';
    public const MODE_AGENCY = 'agency';
    public const MODE_SUPER_ADMIN = 'super_admin';
    /** Lot G-1b : un administrateur du SIÈGE d'un groupe : son site, puis les boutiques du groupe, où il entre comme directeur */
    public const MODE_GROUP = 'group';
    /** Familles d'un site que voit le compte de ce site (ses rôles tranchent ensuite, écran par écran) */
    private const SITE_FAMILIES = [ScreenRegistry::CONSTRUCTION, ScreenRegistry::VENTE, ScreenRegistry::CLIENTS, ScreenRegistry::GESTION, ScreenRegistry::PERTES,
        ScreenRegistry::ASSISTANCE, ScreenRegistry::CONTENUS, ScreenRegistry::SECURITE];
    /** Mode agence chez un client : construction et entretien, jamais un chiffre */
    private const AGENCY_FAMILIES = [ScreenRegistry::CONSTRUCTION];
    /** Le compte de la requête porte-t-il un rôle d'équipe ? est-il celui de la plateforme ? (posés par `contexts`, lus par `site`) */
    private bool $team = false;
    private bool $platform = false;

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly AgencyRepository $agencies,
        private readonly PlatformSiteService $platformSites,
        private readonly SiteSnapshotReader $snapshots,
        private readonly RoleHierarchyInterface $hierarchy,
        private readonly ?\App\Services\Platform\PlatformModeService $platformMode = null,
        private readonly ?\App\Services\Group\GroupRepository $groups = null,
        private readonly ?\App\Services\FeatureAccess\ModuleAccessService $modules = null,
    ) {
    }

    /**
     * Lot G-1b : le site courant est-il le SIÈGE d'un groupe actif, au module actif (lecture légère pour le menu d'EasyAdmin et le sélecteur ;
     * le rôle d'administrateur se juge à part) ?
     */
    public function isGroupHead(): bool
    {
        $current = (string) $this->tenants->getCurrentTenantCode();
        $group = $current !== '' && $this->groups !== null ? $this->groups->ofSite($current) : null;

        return $group !== null && $group['head_tenant_code'] === $current && $this->modules?->isEnabled(\App\Services\FeatureAccess\ModuleAccessService::MULTI_SHOP) === true;
    }

    /**
     * @param list<string> $roles les rôles du compte connecté
     * @return array<string, mixed>
     */
    public function contexts(array $roles): array
    {
        $reachable = $this->hierarchy->getReachableRoleNames($roles);
        $hasRole = static fn (string $role): bool => in_array($role, $reachable, true);
        $current = (string) $this->tenants->getCurrentTenantCode();
        // un compte sans aucun rôle d'équipe (un client) ne lit ni les modules ni les liens d'agence de son site : rien que le site public
        // ne dise déjà. Le numéro d'une demande de création n'intéresse que la plateforme.
        $this->team = array_intersect(ScreenRegistry::teamRoles(), $reachable) !== [];
        $this->platform = $hasRole('ROLE_SUPER_ADMIN');

        if ($hasRole('ROLE_SUPER_ADMIN')) {
            // tous les sites, plus les écrans de la plateforme
            $sites = array_map(fn (array $site): array => $this->site($site, $current, self::SITE_FAMILIES, $hasRole), $this->platformSites->sites());

            return ['mode' => self::MODE_SUPER_ADMIN, 'site' => $current, 'agency' => null, 'group' => null, 'sites' => $sites,
                'all' => ['key' => 'sites', 'screens' => ScreenRegistry::visible([ScreenRegistry::PLATEFORME], [], $hasRole)]];
        }
        $agency = AgencyRoles::has($roles) && $current !== '' ? $this->agencies->findByHome($current) : null;
        if ($agency !== null && $agency['status'] !== 'closed') {
            // le site d'attache, avec ce que les rôles du compte y ouvrent ; puis les sites que l'agence gère, en construction seulement
            $managed = array_values(array_filter(array_map(static fn (array $row): string => (string) $row['tenant_code'], $this->agencies->sites((int) $agency['id'])),
                static fn (string $code): bool => $code !== $current));
            $sites = [];
            foreach ($this->platformSites->sites(null, [$current]) as $site) {
                $sites[] = $this->site($site, $current, self::SITE_FAMILIES, $hasRole) + ['home' => true];
            }
            // chez un client, l'agence tient les écrans de construction d'un administrateur : son rôle d'agence en tient lieu.
            // Une agence SUSPENDUE garde la liste de ses clients, sans aucun écran chez eux : suspendre ferme toutes les entrées.
            $asBuilder = static fn (string $role): bool => $role === 'ROLE_ADMIN';
            $families = $agency['status'] === 'suspended' ? [] : self::AGENCY_FAMILIES;
            foreach ($managed === [] ? [] : $this->platformSites->sites(null, $managed) as $site) {
                $sites[] = $this->site($site, $current, $families, $asBuilder) + ['home' => false];
            }

            return ['mode' => self::MODE_AGENCY, 'site' => $current, 'agency' => ['id' => (int) $agency['id'], 'code' => (string) $agency['code'], 'name' => (string) $agency['name'], 'status' => (string) $agency['status']], 'group' => null,
                'sites' => $sites, 'all' => ['key' => 'clients', 'screens' => ScreenRegistry::visible([ScreenRegistry::AGENCE], [], $hasRole)]];
        }
        $group = $current !== '' && $this->groups !== null ? $this->groups->ofSite($current) : null;
        if ($group !== null && $hasRole('ROLE_ADMIN') && $this->isGroupHead()) {
            // lot G-1b : le siège, puis les boutiques du groupe ; le directeur y a les écrans d'un administrateur (la garde ferme Stripe, les
            // remboursements et le compte)
            $sites = [];
            foreach ($this->platformSites->sites(null, [$current]) as $site) {
                $sites[] = $this->site($site, $current, self::SITE_FAMILIES, $hasRole) + ['home' => true];
            }
            $shops = array_values(array_filter(array_map(static fn (array $row): string => (string) $row['tenant_code'], $this->groups->shops((int) $group['id'])), static fn (string $code): bool => $code !== $current));
            foreach ($shops === [] ? [] : $this->platformSites->sites(null, $shops) as $site) {
                $sites[] = $this->site($site, $current, self::SITE_FAMILIES, static fn (string $role): bool => $role === 'ROLE_ADMIN') + ['home' => false];
            }

            return ['mode' => self::MODE_GROUP, 'site' => $current, 'agency' => null, 'group' => ['id' => (int) $group['id'], 'name' => (string) $group['name'], 'role' => 'head'],
                'sites' => $sites, 'all' => null];
        }
        $sites = [];
        foreach ($current === '' ? [] : $this->platformSites->sites(null, [$current]) as $site) {
            $sites[] = $this->site($site, $current, self::SITE_FAMILIES, $hasRole);
        }
        // une boutique du groupe (pas le siège) le dit à son administrateur ; jamais à un compte sans rôle d'équipe
        $member = $group !== null && $this->team && $group['head_tenant_code'] !== $current ? ['id' => (int) $group['id'], 'name' => (string) $group['name'], 'role' => 'shop'] : null;

        return ['mode' => self::MODE_SINGLE, 'site' => $current, 'agency' => null, 'group' => $member, 'sites' => $sites, 'all' => null];
    }

    /**
     * Lot AG-3c : les écrans du SITE COURANT pour le compte de la requête, par la même règle que `/api/me/contexts` (famille du mode,
     * module actif, rôle) : le menu d'EasyAdmin s'en sert. L'opérateur d'une agence entrée : la construction seule, son rôle d'agence
     * tenant lieu d'administrateur. Le super administrateur garde aussi les écrans de la plateforme.
     *
     * @return list<string>
     */
    public function currentScreens(\Symfony\Component\Security\Core\User\UserInterface $user): array
    {
        $current = (string) $this->tenants->getCurrentTenantCode();
        $site = $current === '' ? null : ($this->platformSites->sites(null, [$current])[0] ?? null);
        if ($site === null) {
            return [];
        }
        if ($user instanceof \App\Security\Entry\ExternalOperator) {
            $this->team = true;
            $this->platform = false;

            return $this->site($site, $current, self::AGENCY_FAMILIES, static fn (string $role): bool => $role === 'ROLE_ADMIN')['screens'];
        }
        $reachable = $this->hierarchy->getReachableRoleNames($user->getRoles());
        $hasRole = static fn (string $role): bool => in_array($role, $reachable, true);
        $this->team = array_intersect(ScreenRegistry::teamRoles(), $reachable) !== [];
        $this->platform = $hasRole('ROLE_SUPER_ADMIN');

        return $this->site($site, $current, $this->platform ? [...self::SITE_FAMILIES, ScreenRegistry::PLATEFORME] : self::SITE_FAMILIES, $hasRole)['screens'];
    }

    /**
     * @param array{code: string, name: string, host: ?string, badges: list<array<string, mixed>>} $site
     * @param list<string> $families
     * @param callable(string): bool $hasRole
     * @return array<string, mixed>
     */
    private function site(array $site, string $current, array $families, callable $hasRole): array
    {
        $snapshot = $this->snapshots->read($site['code']);
        $badges = $this->platform ? $site['badges'] : array_map(static fn (array $badge): array => array_diff_key($badge, ['requestId' => 1]), $site['badges']);

        return ['code' => $site['code'], 'name' => $site['name'], 'host' => $site['host'], 'current' => $site['code'] === $current, 'badges' => $this->team ? $badges : [],
            'state' => $snapshot['state'], 'scope' => $snapshot['scope'], 'modules' => $this->team ? array_keys(array_filter($snapshot['modules'])) : [],
            // la règle de la garde des modules (ModuleAccessSubscriber) : en mode « demo » de la plateforme, rien n'est refusé, un module compte
            // donc comme actif pour les écrans (la famille, construction pour l'agence, reste au-dessus) ; `modules` dit toujours l'état réel
            'screens' => ScreenRegistry::visible($families, ($this->platformMode?->isDemo() ?? false) ? array_map(static fn (): bool => true, $snapshot['modules']) : $snapshot['modules'], $hasRole)];
    }
}
