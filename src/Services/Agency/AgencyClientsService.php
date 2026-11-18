<?php

namespace App\Services\Agency;

use App\Services\Agency\Assistance\AssistanceService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\Screens\SiteSnapshotReader;
use App\Services\Storefront\StorefrontService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * « TOUS MES CLIENTS » (lot AG-3d, 18/11/2026, plan validé : `docs/plan-entree-et-groupe.md` § 3, `docs/ecrans-et-contextes.md` § 10). Un tableau
 * TECHNIQUE des sites gérés par l'agence, lu de la base maître et de l'état de chaque site, SANS y entrer : état (construction ou ouvert), modules
 * actifs, nombres de la liste d'ouverture, invitation de l'administrateur (état et dates, jamais le destinataire), essais qui finissent, assistances
 * en cours, dernière entrée de l'agence. JAMAIS un chiffre d'affaires, un nombre de commandes, de clients ou d'avis, un montant, un solde.
 *
 * Chaque ligne lit une base de site : elle est gardée 5 minutes (étiquette du site, vidée par les gestes de l'agence et du commerçant qui la
 * changent : entrée, assistance, ouverture, modules).
 */
class AgencyClientsService
{
    public const TTL = 300;
    /** Les seules clés d'une ligne (liste blanche, sondée par l'essai) */
    public const KEYS = ['code', 'name', 'host', 'origin', 'attachedAt', 'state', 'scope', 'openedAt', 'modules', 'checklist', 'invitation', 'trials', 'assistance', 'lastEntry'];

    public function __construct(
        private readonly AgencyService $agencies,
        private readonly AgencyRepository $repository,
        private readonly AgencySiteRequestRepository $requests,
        private readonly SiteSnapshotReader $snapshots,
        private readonly StorefrontService $storefront,
        private readonly TenantDatabaseConnector $databases,
        private readonly \App\Services\TenantConnectionManager $tenants,
        private readonly AssistanceService $assistance,
        #[Autowire(service: 'cache.app')] private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** L'étiquette de cache d'un site : à vider quand son état change */
    public static function tag(string $code): string
    {
        return 'agency_client_' . preg_replace('/[^a-z0-9_-]/i', '_', $code);
    }

    /** Oublier la ligne d'un site (un geste qui la change vient d'avoir lieu) ; ne fait jamais échouer le geste */
    public function forget(string $code): void
    {
        self::forgetIn($this->cache, $code);
    }

    /**
     * Le SEUL endroit qui vide la ligne d'un site (convention des étiquettes : `CacheTagConventionTest`). Les services que ce service lit
     * (état de la boutique, entrées) l'appellent sans dépendre de lui. Ne fait jamais échouer le geste.
     */
    public static function forgetIn(?object $cache, string $code): void
    {
        if (!$cache instanceof \Symfony\Contracts\Cache\TagAwareCacheInterface) {
            return;
        }
        try {
            $cache->invalidateTags([self::tag($code)]);
        } catch (\Throwable) {
            // la ligne se relira à son échéance (5 minutes)
        }
    }

    /**
     * @param array<string, mixed> $agency
     * @return list<array<string, mixed>>
     */
    public function rows(array $agency): array
    {
        $agencyId = (int) $agency['id'];
        $assistance = [];
        foreach ($this->assistance->forAgency($agencyId) as $grant) {
            $assistance[(string) $grant['site']] = ($assistance[(string) $grant['site']] ?? 0) + 1;
        }
        $rows = [];
        foreach ($this->agencies->sitesOf($agency) as $site) {
            $code = (string) $site['code'];
            $row = $this->cache->get('agency_client_' . $agencyId . '_' . md5($code), function (ItemInterface $item) use ($agencyId, $site, $code): array {
                $item->expiresAfter(self::TTL);
                $item->tag([self::tag($code)]);

                return $this->row($agencyId, $site);
            });
            $rows[] = ['assistance' => $assistance[$code] ?? 0] + $row;
        }

        return array_map(static fn (array $r): array => array_intersect_key($r, array_flip(self::KEYS)), $rows);
    }

    /** @param array<string, mixed> $site @return array<string, mixed> */
    private function row(int $agencyId, array $site): array
    {
        $code = (string) $site['code'];
        $row = ['code' => $code, 'name' => $site['name'], 'host' => $site['host'], 'origin' => $site['origin'], 'attachedAt' => $site['attachedAt'],
            'state' => null, 'scope' => null, 'openedAt' => null, 'modules' => [], 'checklist' => null, 'invitation' => null, 'trials' => [], 'lastEntry' => null];
        $tenant = $this->repository->tenant($code);
        try {
            if ($tenant !== null) {
                $list = $this->storefront->checklist(['code' => $tenant['code'], 'dbname' => $tenant['dbname']]);
                $row['state'] = $list['state'];
                $row['scope'] = $list['scope'];
                $row['openedAt'] = $list['openedAt'];
                $row['checklist'] = ['blocking' => count(array_filter($list['items'], static fn (array $i): bool => !$i['ok'] && $i['blocking'])),
                    'improvements' => count(array_filter($list['items'], static fn (array $i): bool => !$i['ok'] && !$i['blocking'])), 'canOpen' => $list['canOpen']];
                $row['modules'] = array_keys(array_filter($this->snapshots->read($code)['modules']));
                $row['invitation'] = $this->invitation($agencyId, $code, $tenant['dbname']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Agence] client %s illisible (%s) : ligne partielle', $code, $e::class));
        }
        $row['trials'] = $this->trials($code);
        $row['lastEntry'] = $this->lastEntry($agencyId, $code);

        return $row;
    }

    /** L'invitation de l'administrateur d'un site créé par l'agence : son état et ses dates, JAMAIS son destinataire @return array<string, mixed>|null */
    private function invitation(int $agencyId, string $code, string $dbname): ?array
    {
        $request = $this->requests->readyFor($agencyId, $code);
        $invitation = is_array($request['result']['invitation'] ?? null) ? $request['result']['invitation'] : null;
        if ($request === null || $invitation === null) {
            return null;
        }
        $stmt = $this->databases->pdo($dbname)->prepare('SELECT COUNT(*) FROM "user" WHERE lower(email) = lower(?) AND is_verified = TRUE AND password IS NOT NULL AND password <> \'\'');
        $stmt->execute([(string) $request['admin_email']]);
        $accepted = (int) $stmt->fetchColumn() > 0;
        $expires = $invitation['expiresAt'] ?? null;

        return ['state' => $accepted ? 'accepted' : (!($invitation['sent'] ?? false) ? 'not_sent' : ($expires !== null && strtotime((string) $expires) < time() ? 'expired' : 'pending')),
            'sentAt' => $request['result']['invitationAskedAt'] ?? $request['finished_at'] ?? null, 'expiresAt' => $accepted ? null : $expires];
    }

    /** @return list<array{feature: string, endsAt: string}> les essais du site qui finissent dans les 30 jours */
    private function trials(string $code): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare("SELECT feature_key, ends_at FROM tenant_entitlements WHERE tenant_code = ? AND source = 'trial' AND status = 'active' AND ends_at IS NOT NULL AND ends_at BETWEEN NOW() AND NOW() + INTERVAL '30 days' ORDER BY ends_at");
        $stmt->execute([$code]);

        return array_map(static fn (array $r): array => ['feature' => (string) $r['feature_key'], 'endsAt' => (new \DateTimeImmutable((string) $r['ends_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM)], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array{at: string, firstName: string}|null la dernière entrée de CETTE agence dans le site */
    private function lastEntry(int $agencyId, string $code): ?array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare("SELECT created_at, first_name FROM site_entries WHERE target_tenant_code = ? AND agency_id = ? AND mode = 'agency' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$code, $agencyId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $r ? ['at' => (new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM), 'firstName' => (string) $r['first_name']] : null;
    }
}
