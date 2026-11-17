<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossSaleUnit;
use App\LossPrevention\Repository\LossSaleUnitRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Les sorties PERMISES servies au boîtier (lot L5-d, 17/11/2026, `docs/prevention-pertes.md` § 18.2 « C » et § 18.11) : avec elles, le
 * boîtier décide SUR PLACE si le portique sonne, sans attendre la plateforme. La puce n'est jamais désactivée (§ 16) : la liste dit quelles
 * pièces peuvent sortir.
 *
 * - `sold` : les puces des pièces VENDUES et non revenues, quelle que soit la date de la vente (chef de projet : un client revient le
 *   lendemain avec la veste qu'il a achetée sur le dos). Sans `since`, la liste complète ; avec `since`, les seules entrées et sorties de
 *   « vendue » depuis (`released` : revenues ou retirées), avec un recouvrement de `OVERLAP_SECONDS` pour une écriture validée en retard.
 *   Le boîtier refait une liste complète toutes les `FULL_REFRESH_SECONDS`.
 * - `waiting` : par variante, les articles VENDUS qui attendent encore leur pièce (caisse dans la fenêtre de rattrapage, web dans sa
 *   fenêtre ; lus dans les commandes, l'analyse n'étant peut-être pas passée), et les puces de ses pièces en service : une pièce de la
 *   variante peut sortir par vente sans pièce.
 * - `rentals` : par variante, les unités louées du jour (règle de L4-b2), et les puces de ses pièces en service.
 * Rien d'une personne ; le boîtier garde la liste en mémoire et sur son disque.
 */
class LossExitPermitService
{
    /** Recouvrement des deltas : une écriture d'un passage d'analyse peut être validée plusieurs minutes après son heure (relecture du 17/11/2026) */
    public const OVERLAP_SECONDS = 1800;
    public const FULL_REFRESH_SECONDS = 3600;
    /** Une pièce lue sur le tapis du comptoir depuis moins longtemps ne sonne pas : le boîtier le sait seul */
    public const COUNTER_GRACE_SECONDS = 600;
    /** Puces servies au plus par variante (en attente ou louée) */
    public const MAX_PER_VARIANT = 500;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossSaleLinkService $sales, private readonly LossSite $site)
    {
    }

    /**
     * @param array<string, mixed> $box le boîtier authentifié
     *
     * @return array<string, mixed> { serverTime, full, sold, released, waiting, rentals }
     *
     * @throws LossBoxException 422 `since` invalide
     */
    public function permits(array $box, mixed $since): array
    {
        $sinceTs = null;
        if ($since !== null && $since !== '') {
            $sinceTs = is_string($since) ? strtotime($since) : false;
            if ($sinceTs === false || $sinceTs > time() + 300) {
                throw new LossBoxException('VALIDATION_FAILED', 'Paramètre since invalide.', 422, ['fields' => ['since' => 'INVALID_DATE']]);
            }
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $now = time();
        $tags = 'JOIN loss_tag t ON t.piece_id = p.id AND t.status = \'associated\' AND NOT t.exercise';
        if ($sinceTs === null) {
            $sold = $conn->fetchFirstColumn("SELECT t.identifier FROM loss_piece p $tags WHERE NOT p.exercise AND p.status = 'sold' ORDER BY t.identifier");
            $released = [];
        } else {
            $from = gmdate('Y-m-d H:i:s', $sinceTs - self::OVERLAP_SECONDS);
            $sold = $conn->fetchFirstColumn("SELECT t.identifier FROM loss_piece p $tags WHERE NOT p.exercise AND p.status = 'sold' AND p.sold_changed_at >= ? ORDER BY t.identifier", [$from]);
            $released = $conn->fetchFirstColumn("SELECT t.identifier FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id AND NOT t.exercise WHERE NOT p.exercise AND p.status <> 'sold' AND p.sold_changed_at >= ? ORDER BY t.identifier", [$from]);
        }
        $windows = $this->sales->windows();
        $units = $this->emProvider->getEntityManager()->getRepository(LossSaleUnit::class);
        \assert($units instanceof LossSaleUnitRepository);
        $waiting = $units->waitingSales(gmdate('Y-m-d H:i:s', $now - $windows['catchUp']), gmdate('Y-m-d H:i:s', $now - $windows['webDays'] * 86400));
        $clock = $this->site->clock();
        $today = $clock->day(new \DateTimeImmutable());
        [$dayStart, $dayEnd] = $clock->range($today, $today);
        $rented = $units->rentedBetween($dayStart->modify('-1 day')->format('Y-m-d H:i:s'), $dayEnd->modify('+1 day')->format('Y-m-d H:i:s'));

        return ['serverTime' => gmdate(\DateTimeInterface::ATOM, $now), 'full' => $sinceTs === null, 'sold' => array_values($sold), 'released' => array_values($released),
            'waiting' => $this->groups($waiting), 'rentals' => $this->groups($rented)];
    }

    /**
     * @param array<int, int> $counts variante => nombre
     *
     * @return list<array{variantId: int, count: int, identifiers: list<string>}>
     */
    private function groups(array $counts): array
    {
        $out = [];
        foreach ($counts as $variant => $count) {
            $identifiers = $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn(
                "SELECT t.identifier FROM loss_piece p JOIN loss_tag t ON t.piece_id = p.id AND t.status = 'associated' AND NOT t.exercise"
                . " WHERE p.variant_id = ? AND NOT p.exercise AND p.status NOT IN ('sold', 'retired') ORDER BY t.identifier LIMIT " . self::MAX_PER_VARIANT, [$variant]);
            if ($identifiers !== []) {
                $out[] = ['variantId' => (int) $variant, 'count' => (int) $count, 'identifiers' => array_values($identifiers)];
            }
        }

        return $out;
    }
}
