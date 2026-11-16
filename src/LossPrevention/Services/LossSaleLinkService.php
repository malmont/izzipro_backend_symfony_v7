<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossSaleUnit;
use App\LossPrevention\Entity\LossSetting;
use App\LossPrevention\Repository\LossPieceRepository;
use App\LossPrevention\Repository\LossSaleUnitRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * La vente reliée aux pièces, APRÈS COUP (lot L3-b2a, 16/11/2026, `docs/prevention-pertes.md` § 16.18). Le module ne touche pas à
 * la vente et n'est jamais appelé pendant elle : c'est son analyse à curseur qui, en lisant le journal de caisse, écrit ici.
 *
 * - **Une vente** (`SALE`) : pour chaque article SUIVI vendu (sa variante avait, à l'heure de la vente, une vraie pièce portant une
 *   puce), une ligne `loss_sale_unit`, puis une pièce de cette variante LUE sur le tapis de CE poste dans la fenêtre qui précède la
 *   vente : fait `PIECE_SOLD`, pièce `sold`. Une pièce par article ; la base le garantit.
 * - **Un retour** (`REFUND`, `kind: return`) ou **une vente annulée** (`kind: cancellation`) : l'article est dit revenu, et sa
 *   pièce redevient vendable (fait `PIECE_RETURNED`, pièce `returned`). Pour un retour, la pièce relue sur le tapis du poste est
 *   prise d'abord.
 * - **Le rattrapage à la sortie** (`catchUp`, à chaque passage) : une pièce non vendue lue à la sortie peu avant ou peu après une
 *   vente de SA variante encore sans pièce lui est reliée, la vente la plus ancienne d'abord. Dans les deux sens : que la vente
 *   soit lue après la lecture, ou la lecture après la vente. Aucun constat ici.
 * - L'heure d'une vente est son heure RÉELLE : celle de la commande (`"order".order_date`, en UTC pour une vente en ligne), ou,
 *   pour une vente hors ligne synchronisée plus tard, l'instant que la caisse a écrit dans l'entrée du journal
 *   (`data.offline.soldAt`, avec son décalage horaire : la colonne de la commande garde alors l'heure du magasin, pas l'UTC).
 * - Les faits du tapis peuvent arriver APRÈS la vente (boîtier revenu d'une coupure) : le lien par le tapis est retenté à chaque
 *   passage pour les articles écrits depuis peu.
 * - Une lecture ne vaut que si elle est POSTÉRIEURE au dernier retour de la pièce : une pièce rendue n'est pas redite vendue par la
 *   lecture de la sortie du client qui l'avait achetée, ni parce qu'elle est restée sur le tapis après son retour.
 * - Une ligne de location n'est pas une vente : elle n'est pas lue.
 * - Tous les postes du site sont regardés par le rattrapage (la sortie est commune) ; rien ne sort du site : la base est la sienne.
 *
 * Rien n'est écrit dans une table de la caisse. Aucun nom de personne n'est lu ni écrit.
 */
class LossSaleLinkService
{
    public const TYPES = ['SALE', 'REFUND'];
    /** Fenêtre du tapis : une pièce posée, ou partie depuis moins longtemps, compte pour la vente (secondes ; réglable) */
    public const COUNTER_WINDOW = 600;
    public const COUNTER_BOUNDS = ['min' => 60, 'max' => 3600];
    /** Fenêtre du rattrapage : une lecture à la sortie jusqu'à ce temps-là après la vente lui est reliée (secondes ; réglable) */
    public const CATCH_UP_WINDOW = 1800;
    public const CATCH_UP_BOUNDS = ['min' => 300, 'max' => 7200];
    /** Une lecture à la sortie un peu AVANT l'heure de la vente compte aussi (horloges, ticket validé en marchant) */
    public const CATCH_UP_BEFORE = 120;
    /** Le rattrapage ne regarde que les ventes de ces dernières heures (une vente hors ligne synchronisée tard est encore vue) */
    public const CATCH_UP_HORIZON = 172800;
    public const CATCH_UP_BATCH = 500;
    /** Lectures à la sortie regardées au plus par passage (toutes variantes) */
    public const EXIT_READS = 5000;
    /** Le lien par le tapis est retenté pour un article écrit depuis moins longtemps (secondes) : le temps que le boîtier rejoue ses faits */
    public const COUNTER_RETRY = 7200;
    /** Conservation des présences fermées (jours ; réglable) : au-delà, elles s'effacent. Les faits, eux, restent */
    public const PRESENCE_DAYS = 90;
    public const PRESENCE_BOUNDS = ['min' => 30, 'max' => 730];
    public const PURGE_BATCH = 5000;
    /** Articles d'une même ligne de vente reliés au plus (une ligne de 5 000 unités n'écrit pas 5 000 lignes) */
    public const MAX_UNITS_PER_ITEM = 200;

    /** Le site a-t-il une vraie pièce à puce ? Lu une fois par passage : sans cela, une vente ne coûte aucune lecture */
    private ?bool $tagged = null;
    /** @var array{counter: int, catchUp: int, presenceDays: int}|null les fenêtres du passage en cours, lues une fois */
    private ?array $pass = null;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** Début d'un passage de l'analyse : ce qui se lit une fois par passage est oublié (le service sert plusieurs sites, et plusieurs passages) */
    public function begin(): void
    {
        $this->tagged = null;
        $this->pass = null;
    }

    /**
     * Une entrée `SALE` ou `REFUND` du journal de caisse, dans la transaction de l'analyse.
     *
     * @param array<string, mixed> $entry entrée lue par `LossAuditEntry::fromRow`
     */
    public function handle(array $entry): void
    {
        $subject = $entry['subject'] ?? null;
        if (!is_array($subject) || ($subject['type'] ?? null) !== 'order' || !isset($subject['id'])) {
            return;
        }
        $kind = $entry['data']['kind'] ?? null;
        if ($entry['type'] === 'SALE') {
            if ($this->tagged ??= $this->units()->hasTaggedPiece()) {
                $this->sale($entry, (int) $subject['id']);
            }
        } elseif ($kind === 'cancellation') {
            $this->cancellation($entry, (int) $subject['id']);
        } elseif ($kind === 'return' && is_array($entry['data']['lines'] ?? null)) {
            $this->return($entry, $entry['data']['lines']);
        }
        // un retour de l'ancien chemin (sans ligne d'origine) ne dit pas quel article revient : rien n'est écrit
    }

    /**
     * Le rattrapage, dans la transaction de l'analyse, pour les articles suivis vendus qui n'ont pas encore de pièce :
     * 1. **le tapis, de nouveau**, pour ceux écrits depuis peu : les faits du boîtier ont pu arriver après la vente ;
     * 2. **la sortie** : une pièce non vendue lue à la sortie peu avant ou peu après la vente. La vente la plus ancienne d'abord, la
     *    première lecture d'abord, une pièce par article. Une seule requête pour toutes les variantes du passage.
     *
     * @return int nombre d'articles reliés
     */
    public function catchUp(int $now): int
    {
        $units = $this->units();
        $windows = $this->passWindows();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $linked = 0;
        // 1. Le tapis, de nouveau, pour les articles écrits depuis peu
        foreach ($units->unitsToRetryOnCounter(gmdate('Y-m-d H:i:s', $now - self::COUNTER_RETRY), self::CATCH_UP_BATCH) as $unit) {
            $sold = self::stamp((string) $unit['sold_at']);
            // la requête écarte d'elle-même une pièce reliée plus tôt dans ce passage (elle n'est plus « reliée à aucun article »)
            $read = $units->piecesReadOnCounter((int) $unit['cash_register_id'], (int) $unit['variant_id'], gmdate('Y-m-d H:i:s', $sold - $windows['counter']),
                gmdate('Y-m-d H:i:s', $sold + LossBoxChannelService::CLOCK_TOLERANCE), false);
            if ($read !== [] && $units->link((int) $unit['id'], $read[0], LossSaleUnit::BY_COUNTER, $stamp)) {
                $this->sold($read[0], $unit, LossSaleUnit::BY_COUNTER, $stamp, []);
                ++$linked;
            }
        }
        // 2. La sortie, pour ceux qui attendent encore
        $horizon = gmdate('Y-m-d H:i:s', $now - self::CATCH_UP_HORIZON);
        $waiting = array_map(static fn (array $u): array => $u + ['sold' => self::stamp((string) $u['sold_at'])], $units->unitsToCatchUp($horizon, self::CATCH_UP_BATCH));
        if ($waiting === []) {
            return $linked;
        }
        $reads = $units->exitReads(array_values(array_unique(array_map(static fn (array $u): int => (int) $u['variant_id'], $waiting))),
            gmdate('Y-m-d H:i:s', min(array_column($waiting, 'sold')) - self::CATCH_UP_BEFORE), gmdate('Y-m-d H:i:s', max(array_column($waiting, 'sold')) + $windows['catchUp']),
            $horizon, self::CATCH_UP_BEFORE, $windows['catchUp'], self::EXIT_READS);
        foreach ($reads as $variant => $list) {
            $reads[$variant] = array_map(static fn (array $r): array => $r + ['stamp' => self::stamp($r['at'])], $list);
        }
        $used = [];
        foreach ($waiting as $unit) { // la vente la plus ancienne d'abord
            foreach ($reads[(int) $unit['variant_id']] ?? [] as $read) { // la première lecture d'abord
                if ($read['stamp'] > $unit['sold'] + $windows['catchUp']) {
                    break; // les lectures sont dans l'ordre du temps : les suivantes sont plus tardives encore
                }
                if (isset($used[$read['piece']]) || $read['stamp'] < $unit['sold'] - self::CATCH_UP_BEFORE) {
                    continue;
                }
                if ($units->link((int) $unit['id'], $read['piece'], LossSaleUnit::BY_CATCH_UP, $stamp)) {
                    $this->sold($read['piece'], $unit, LossSaleUnit::BY_CATCH_UP, $stamp, ['readEventId' => $read['event']]);
                    $used[$read['piece']] = true;
                    ++$linked;
                }
                break;
            }
        }

        return $linked;
    }

    /**
     * La conservation : les présences sur le tapis FERMÉES depuis plus longtemps que la durée du site s'effacent (les faits restent).
     *
     * @return int nombre de présences effacées
     */
    public function purge(int $now): int
    {
        return $this->units()->purgePresences(gmdate('Y-m-d H:i:s', $now - $this->passWindows()['presenceDays'] * 86400), self::PURGE_BATCH);
    }

    /**
     * Les fenêtres en vigueur : celles du site, sinon celles du code.
     *
     * @return array{counter: int, catchUp: int, presenceDays: int}
     */
    public function windows(): array
    {
        $row = $this->emProvider->getEntityManager()->getRepository(LossSetting::class)->row();

        return ['counter' => (int) ($row['counter_window_seconds'] ?? self::COUNTER_WINDOW), 'catchUp' => (int) ($row['catch_up_seconds'] ?? self::CATCH_UP_WINDOW),
            'presenceDays' => (int) ($row['presence_retention_days'] ?? self::PRESENCE_DAYS)];
    }

    /** @param array<string, mixed> $entry */
    private function sale(array $entry, int $orderId): void
    {
        $units = $this->units();
        $order = $units->order($orderId);
        if ($order === null) {
            return;
        }
        $items = $units->itemsOf($orderId);
        $soldAt = self::saleTime($entry, $order['sold_at']);
        $tracked = array_flip($units->trackedAt(array_values(array_unique(array_column($items, 'variant'))), $soldAt));
        $register = $entry['cashRegisterId'];
        $now = gmdate('Y-m-d H:i:s');
        $sold = self::stamp($soldAt);
        $window = $this->passWindows()['counter'];
        // une pièce posée jusqu'à une minute « après » la vente compte encore : l'heure de la vente et celle du boîtier ne sont pas la même horloge
        [$from, $until] = [gmdate('Y-m-d H:i:s', $sold - $window), gmdate('Y-m-d H:i:s', $sold + LossBoxChannelService::CLOCK_TOLERANCE)];
        $read = [];
        foreach ($items as $item) {
            if (!isset($tracked[$item['variant']])) {
                continue; // article non suivi à l'heure de la vente : rien à relier
            }
            // une vente sans poste (ancienne entrée) n'a pas de tapis : ses articles sont écrits, sans pièce
            $read[$item['variant']] ??= $register !== null ? $units->piecesReadOnCounter($register, $item['variant'], $from, $until, false) : [];
            for ($n = 1; $n <= min($item['quantity'], self::MAX_UNITS_PER_ITEM); ++$n) {
                $unitId = $units->insert($orderId, $item['id'], $n, $item['variant'], $register, $soldAt, $entry['sequence'], $now);
                if ($unitId === null) {
                    continue; // déjà écrit : la même vente relue
                }
                $pieceId = array_shift($read[$item['variant']]);
                if ($pieceId !== null && $units->link($unitId, $pieceId, LossSaleUnit::BY_COUNTER, $now)) {
                    $this->sold($pieceId, ['order_id' => $orderId, 'order_item_id' => $item['id'], 'cash_register_id' => $register, 'sold_at' => $soldAt], LossSaleUnit::BY_COUNTER, $now, []);
                }
            }
        }
    }

    /** Une vente annulée : tous ses articles reviennent, leurs pièces redeviennent vendables */
    private function cancellation(array $entry, int $orderId): void
    {
        $at = $entry['occurredAt']->format('Y-m-d H:i:s');
        foreach ($this->units()->openUnitsOfOrder($orderId) as $unit) {
            $this->unitReturned($unit, $at, $entry['sequence'], LossSaleUnit::CANCELLATION, false);
        }
    }

    /**
     * Un retour d'articles : chaque ligne désigne la ligne de la vente d'origine et une quantité. L'article dont la pièce est relue
     * sur le tapis du poste revient d'abord ; puis un article sans pièce ; puis, faute de mieux, le premier article de la ligne.
     *
     * @param array<string, mixed> $entry
     * @param list<mixed>          $lines
     */
    private function return(array $entry, array $lines): void
    {
        $units = $this->units();
        $at = $entry['occurredAt']->format('Y-m-d H:i:s');
        $stamp = $entry['occurredAt']->getTimestamp();
        $window = $this->passWindows()['counter'];
        foreach ($lines as $line) {
            $itemId = is_array($line) && is_int($line['orderItemId'] ?? null) ? $line['orderItemId'] : null;
            $quantity = is_array($line) && is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
            if ($itemId === null || $quantity < 1) {
                continue;
            }
            $open = $units->openUnitsOfItem($itemId);
            if ($open === []) {
                continue; // la ligne d'origine n'était pas un article suivi
            }
            $onCounter = $entry['cashRegisterId'] !== null
                ? array_flip($units->piecesReadOnCounter($entry['cashRegisterId'], (int) $open[0]['variant_id'], gmdate('Y-m-d H:i:s', $stamp - $window), gmdate('Y-m-d H:i:s', $stamp + LossBoxChannelService::CLOCK_TOLERANCE), true))
                : [];
            // l'ordre des articles qui reviennent : pièce relue sur le tapis, puis sans pièce, puis les autres
            usort($open, static fn (array $a, array $b): int => self::rank($a, $onCounter) <=> self::rank($b, $onCounter));
            foreach (array_slice($open, 0, min($quantity, self::MAX_UNITS_PER_ITEM)) as $unit) {
                $this->unitReturned($unit, $at, $entry['sequence'], LossSaleUnit::RETURN, $unit['piece_id'] !== null && isset($onCounter[(int) $unit['piece_id']]));
            }
        }
    }

    /**
     * @param array<string, mixed> $unit
     * @param array<int, int>      $onCounter pièces relues sur le tapis, par identifiant
     */
    private static function rank(array $unit, array $onCounter): int
    {
        return $unit['piece_id'] === null ? 1 : (isset($onCounter[(int) $unit['piece_id']]) ? 0 : 2);
    }

    /** @param array<string, mixed> $unit */
    private function unitReturned(array $unit, string $at, int $sequence, string $by, bool $read): void
    {
        if (!$this->units()->returned((int) $unit['id'], $at, $sequence, $by) || $unit['piece_id'] === null) {
            return;
        }
        $pieceId = (int) $unit['piece_id'];
        $this->units()->pieceStatus($pieceId, LossPiece::RETURNED, $at);
        $this->pieces()->event(LossPieceEvent::PIECE_RETURNED, null, $pieceId, null, false, ['by' => $by, 'readOnCounter' => $read, 'orderItemId' => (int) $unit['order_item_id'], 'sequence' => $sequence], $at);
    }

    /**
     * Une pièce est reliée à une vente : elle est vendue, et le fait le dit.
     *
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $more
     */
    private function sold(int $pieceId, array $unit, string $by, string $now, array $more): void
    {
        $this->units()->pieceStatus($pieceId, LossPiece::SOLD, $now);
        // le fait est daté de l'heure de la VENTE (pas de celle où l'analyse la lit) : un retour lu au même passage vient bien après
        $this->pieces()->event(LossPieceEvent::PIECE_SOLD, null, $pieceId, null, false, ['by' => $by, 'orderId' => (int) $unit['order_id'], 'orderItemId' => (int) $unit['order_item_id'],
            'cashRegisterId' => $unit['cash_register_id'] !== null ? (int) $unit['cash_register_id'] : null, 'soldAt' => gmdate('Y-m-d\TH:i:s\+00:00', self::stamp((string) $unit['sold_at']))] + $more,
            (string) $unit['sold_at']);
    }

    /**
     * L'heure réelle d'une vente, en UTC : pour une vente hors ligne, l'instant écrit par la caisse dans l'entrée du journal (avec son
     * décalage horaire) ; sinon l'heure de la commande.
     *
     * @param array<string, mixed> $entry
     */
    private static function saleTime(array $entry, string $orderDate): string
    {
        $offline = $entry['data']['offline']['soldAt'] ?? null;
        $at = is_string($offline) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $offline) : false;

        return $at instanceof \DateTimeImmutable ? $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : $orderDate;
    }

    /** Les fenêtres du passage en cours : lues une fois par passage (`begin()` les oublie) */
    private function passWindows(): array
    {
        return $this->pass ??= $this->windows();
    }

    private static function stamp(string $stored): int
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function units(): LossSaleUnitRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossSaleUnit::class);
    }

    private function pieces(): LossPieceRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossPiece::class);
    }
}
