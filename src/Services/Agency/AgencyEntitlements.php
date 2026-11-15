<?php

namespace App\Services\Agency;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementRepository;
use App\Services\Platform\EntitlementService;
use Psr\Log\LoggerInterface;

/**
 * Droits d'usage de source « agence » (lot AG-2, 15/11/2026, `docs/agences-et-import.md` § 8). Ils ne se posent que sur un site CRÉÉ
 * par l'agence : un site rattaché à la main par la plateforme garde ses droits tels quels.
 *
 * - À la création : les modules et les applications (`FEATURES`). L'IA n'en fait pas partie : elle suit le réglage de la plateforme
 *   (l'essai « Aide IA » automatique de tout site neuf), en attendant les prix (lot AG-5).
 * - L'agence ferme ou rouvre un module d'un de ses sites. Elle ne touche jamais à un droit qu'elle n'a pas posé : un droit vivant
 *   d'une autre source (essai, abonnement, accord de la plateforme) n'est ni remplacé ni fermé par elle.
 * - Suspension en cascade : suspendre l'agence suspend ses droits, sauf la page publique du site, gardée `PUBLIC_PAGE_DAYS` jours
 *   (en « grâce », puis suspendue par le balayage des droits). Lever la suspension rend ce que la cascade a pris, rien d'autre : la
 *   cascade marque ses droits par leur référence (`agency:<id>:suspended`). Aucun courriel au site au moment de la cascade.
 */
class AgencyEntitlements
{
    public const LANDING = 'module.landing';
    public const PUBLIC_PAGE_DAYS = 30;

    public function __construct(private readonly EntitlementService $entitlements, private readonly EntitlementRepository $rights, private readonly LoggerInterface $logger)
    {
    }

    /** @return list<string> ce qu'une agence peut ouvrir sur un site qu'elle a créé : les modules et les applications */
    public static function features(): array
    {
        return array_values(array_filter(array_keys(FeatureCatalog::GOVERNED), static fn (string $key): bool => str_starts_with($key, 'module.') || str_starts_with($key, 'app.')));
    }

    public static function reference(int $agencyId, bool $suspended = false): string
    {
        return 'agency:' . $agencyId . ($suspended ? ':suspended' : '');
    }

    /**
     * Ouvre des modules sur un site que l'agence vient de créer. Un module qui ne s'ouvre pas est signalé, jamais bloquant.
     *
     * @param list<string> $features
     * @param array<string, mixed> $agency
     * @return array{opened: list<string>, failed: list<string>}
     */
    public function open(string $tenantCode, array $features, array $agency, ?string $by): array
    {
        $out = ['opened' => [], 'failed' => []];
        foreach (array_values(array_intersect(self::features(), $features)) as $feature) {
            try {
                $this->grant($tenantCode, $feature, $agency, $by);
                $out['opened'][] = $feature;
            } catch (\Throwable $e) {
                $out['failed'][] = $feature;
                $this->logger->error(sprintf('[Agences] %s : %s non ouvert (%s)', $tenantCode, $feature, $e::class));
            }
        }

        return $out;
    }

    /**
     * Les modules d'un site créé par l'agence, tels que l'agence les voit.
     *
     * @param array<string, mixed> $agency
     * @return list<array{key: string, label: string, enabled: bool, byAgency: bool, status: ?string, graceUntil: ?string}>
     */
    public function modules(string $tenantCode, array $agency): array
    {
        $effective = $this->entitlements->effective($tenantCode);
        $suspended = [];
        foreach ($this->rights->bySource($tenantCode, EntitlementRepository::SOURCE_AGENCY, ['suspended']) as $row) {
            $suspended[(string) $row['feature_key']] = true;
        }
        $out = [];
        foreach (self::features() as $key) {
            $right = $effective[$key] ?? ['enabled' => false, 'source' => null, 'status' => null, 'graceUntil' => null];
            $out[] = [
                'key' => $key, 'label' => FeatureCatalog::label($key), 'enabled' => (bool) $right['enabled'],
                // le droit vivant vient-il de l'agence ? Sinon elle ne peut ni le fermer ni le remplacer
                'byAgency' => $right['enabled'] ? $right['source'] === EntitlementRepository::SOURCE_AGENCY : isset($suspended[$key]),
                'status' => $right['enabled'] ? (string) $right['status'] : (isset($suspended[$key]) ? 'suspended' : null),
                'graceUntil' => $right['enabled'] && $right['graceUntil'] !== null ? EntitlementService::toIso((string) $right['graceUntil']) : null,
            ];
        }

        return $out;
    }

    /**
     * Ouvre ou ferme un module d'un site créé par l'agence. Rend vrai si quelque chose a changé.
     *
     * @param array<string, mixed> $agency
     */
    public function set(string $tenantCode, string $feature, bool $enabled, array $agency, ?string $by): bool
    {
        if (!in_array($feature, self::features(), true)) {
            throw new AgencyException('AGENCY_MODULE_UNKNOWN', 404, ['feature' => $feature]);
        }
        $live = $this->rights->liveFor($tenantCode, $feature);
        foreach ($live as $row) {
            if ($row['source'] !== EntitlementRepository::SOURCE_AGENCY) {
                // le site tient ce droit d'une autre source : l'agence n'y touche pas
                throw new AgencyException('AGENCY_MODULE_HELD', 409, ['feature' => $feature, 'source' => (string) $row['source']]);
            }
        }
        if ($enabled) {
            if ($live !== []) {
                return false;
            }
            $this->grant($tenantCode, $feature, $agency, $by);

            return true;
        }
        if ($live === []) {
            return false;
        }
        foreach ($live as $row) {
            $this->entitlements->cancel((int) $row['id'], $by, 'fermé par l\'agence ' . $agency['code']);
        }

        return true;
    }

    /**
     * Cascade d'une suspension d'agence sur les sites qu'elle a créés.
     *
     * @param array<string, mixed> $agency
     * @param list<string> $tenantCodes les sites créés par l'agence
     * @return int le nombre de droits pris par la cascade
     */
    public function suspendAll(array $agency, array $tenantCodes, ?string $by): int
    {
        $taken = 0;
        $until = (new \DateTimeImmutable())->modify('+' . self::PUBLIC_PAGE_DAYS . ' days');
        foreach ($tenantCodes as $code) {
            foreach ($this->rights->bySource($code, EntitlementRepository::SOURCE_AGENCY) as $row) {
                try {
                    $id = (int) $row['id'];
                    if ($row['feature_key'] === self::LANDING) {
                        if ($row['status'] !== 'active') {
                            continue; // déjà en grâce : sa fin reste celle qui était posée
                        }
                        $this->entitlements->grace($id, $by, 'agence suspendue : page publique gardée ' . self::PUBLIC_PAGE_DAYS . ' jours', $until, false);
                    } else {
                        $this->entitlements->suspend($id, $by, 'agence suspendue', false);
                    }
                    $this->rights->update($id, ['reference' => self::reference((int) $agency['id'], true)]);
                    ++$taken;
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('[Agences] cascade de suspension : %s / %s non pris (%s)', $code, $row['feature_key'], $e::class));
                }
            }
        }

        return $taken;
    }

    /**
     * Lève la cascade : rend aux sites créés par l'agence les droits que SA suspension avait pris (ceux qui portent sa marque).
     *
     * @param array<string, mixed> $agency
     * @param list<string> $tenantCodes
     * @return int le nombre de droits rendus
     */
    public function resumeAll(array $agency, array $tenantCodes, ?string $by): int
    {
        $given = 0;
        $mark = self::reference((int) $agency['id'], true);
        foreach ($tenantCodes as $code) {
            foreach ($this->rights->bySource($code, EntitlementRepository::SOURCE_AGENCY, ['suspended', 'grace']) as $row) {
                if ($row['reference'] !== $mark) {
                    continue; // suspendu par la plateforme pour une autre raison : la reprise de l'agence ne le rend pas
                }
                try {
                    $this->entitlements->resume((int) $row['id'], $by, 'suspension de l\'agence levée', false);
                    $this->rights->update((int) $row['id'], ['reference' => self::reference((int) $agency['id'])]);
                    ++$given;
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('[Agences] reprise : %s / %s non rendu (%s)', $code, $row['feature_key'], $e::class));
                }
            }
        }

        return $given;
    }

    /** Une fabrication abandonnée : aucun droit ne reste au nom d'un site qui n'existe pas (un autre site prendrait ce code plus tard) */
    public function cancelAll(string $tenantCode): void
    {
        foreach ($this->rights->listForTenant($tenantCode, true) as $row) {
            try {
                $this->rights->update((int) $row['id'], ['status' => 'cancelled', 'note' => trim(($row['note'] ?? '') . ' | fabrication du site abandonnée')]);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Agences] droit n° %d de %s non annulé (%s)', (int) $row['id'], $tenantCode, $e::class));
            }
        }
    }

    /** @param array<string, mixed> $agency */
    private function grant(string $tenantCode, string $feature, array $agency, ?string $by): void
    {
        $this->entitlements->grant($tenantCode, $feature, EntitlementRepository::SOURCE_AGENCY, null, $by, 'agence ' . $agency['code'], null, self::reference((int) $agency['id']), false);
    }
}
