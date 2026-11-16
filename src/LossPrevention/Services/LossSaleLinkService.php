<?php

namespace App\LossPrevention\Services;

use App\Entity\Order;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossSaleUnit;
use App\LossPrevention\Entity\LossSetting;
use App\LossPrevention\Repository\LossPieceRepository;
use App\LossPrevention\Repository\LossSaleUnitRepository;
use App\Services\OrderService\OrderPresenter;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

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
 * - **Les commandes web** (lot L4-b2, § 17.14) : une commande de la boutique en ligne ou de l'application, retirée en boutique OU
 *   expédiée, PAYÉE au sens de la boutique (`OrderPresenter::paymentStatus` = `paid`), est lue dans les commandes (elle n'est pas dans le
 *   journal de caisse) : chacun de ses articles suivis devient un article à relier d'origine `web`, à l'heure de la commande, sans poste (il ne compte
 *   pas dans la règle par poste). Le rattrapage lui relie une pièce de sa variante lue à la sortie OU à la porte arrière de la réserve
 *   (colis remis au transporteur) pendant la fenêtre (14 jours, réglable), parmi celles que la caisse ne peut plus expliquer. Annulée, ses articles sont dits revenus (`cancellation`) ;
 *   des articles rapportés par un retour de la boutique (`order_return`) le sont ligne par ligne (`return`) ; leurs pièces redeviennent
 *   vendables. Un remboursement sans retour de marchandise ne libère rien.
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
    /** Fenêtre d'une commande web, retirée ou expédiée (jours ; réglable) : une pièce lue à la sortie ou à la porte arrière pendant ce temps lui est reliée */
    public const WEB_DAYS = 14;
    public const WEB_BOUNDS = ['min' => 1, 'max' => 60];
    /** Une commande web est guettée (annulation, retours d'articles) jusqu'à ce nombre de jours après sa fenêtre */
    public const WEB_WATCH_DAYS = 30;
    /** Commandes web lues au plus par passage, pour chacune des deux lectures (nouvelles, guettées) */
    public const WEB_ORDERS = 500;
    /** Articles d'une même ligne de vente reliés au plus (une ligne de 5 000 unités n'écrit pas 5 000 lignes) */
    public const MAX_UNITS_PER_ITEM = 200;

    /** Le site a-t-il une vraie pièce à puce ? Lu une fois par passage : sans cela, une vente ne coûte aucune lecture */
    private ?bool $tagged = null;

    /** Le tapis a-t-il déjà été retenté dans ce passage ? (une fois suffit : voir `catchUp`) */
    private bool $counterRetried = false;

    /** @var array{counter: int, catchUp: int, presenceDays: int, webDays: int}|null les fenêtres du passage en cours, lues une fois */
    private ?array $pass = null;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ?OrderPresenter $orders = null, private readonly ?LoggerInterface $logger = null)
    {
    }

    /** Début d'un passage de l'analyse : ce qui se lit une fois par passage est oublié (le service sert plusieurs sites, et plusieurs passages) */
    public function begin(): void
    {
        $this->tagged = null;
        $this->pass = null;
        $this->counterRetried = false;
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
     * `$readsUntil` (lot L4-b2) : l'heure jusqu'à laquelle le journal de caisse a été lu. Un article de RETRAIT ne prend que les lectures
     * que la caisse ne peut plus expliquer (lue avant cette heure moins la fenêtre du rattrapage), sans quoi la pièce d'une vente au
     * comptoir pas encore lue lui serait donnée ; nul = maintenant.
     *
     * @return int nombre d'articles reliés
     */
    public function catchUp(int $now, ?int $readsUntil = null): int
    {
        $units = $this->units();
        $windows = $this->passWindows();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $linked = 0;
        // 1. Le tapis, de nouveau, pour les articles écrits depuis peu. Une fois par passage : un article écrit dans ce passage vient
        // d'être essayé, et rien de neuf n'arrive du boîtier dans la transaction de l'analyse (une reprise d'historique ne refait pas
        // des centaines de recherches avant chaque clôture)
        foreach ($this->counterRetried ? [] : $units->unitsToRetryOnCounter(gmdate('Y-m-d H:i:s', $now - self::COUNTER_RETRY), self::CATCH_UP_BATCH) as $unit) {
            $sold = self::stamp((string) $unit['sold_at']);
            // la requête écarte d'elle-même une pièce reliée plus tôt dans ce passage (elle n'est plus « reliée à aucun article »)
            $read = $units->piecesReadOnCounter((int) $unit['cash_register_id'], (int) $unit['variant_id'], gmdate('Y-m-d H:i:s', $sold - $windows['counter']),
                gmdate('Y-m-d H:i:s', $sold + LossBoxChannelService::CLOCK_TOLERANCE), false);
            if ($read !== [] && $units->link((int) $unit['id'], $read[0], LossSaleUnit::BY_COUNTER, $stamp)) {
                $this->sold($read[0], $unit, LossSaleUnit::BY_COUNTER, $stamp, []);
                ++$linked;
            }
        }
        $this->counterRetried = true;
        // 2. La sortie, pour les ventes au comptoir qui attendent encore (sur la fenêtre du rattrapage) ; 3. puis pour les articles de
        // commande web (lot L4-b2), sur la fenêtre des commandes web. Une pièce reliée à une vente du comptoir dans ce passage ne l'est pas à un retrait
        $used = [];
        $horizon = gmdate('Y-m-d H:i:s', $now - self::CATCH_UP_HORIZON);
        $linked += $this->catchUpAtExit($units->unitsToCatchUp($horizon, self::CATCH_UP_BATCH, LossSaleUnit::ORIGIN_SALE), $horizon, $windows['catchUp'], LossSaleUnit::ORIGIN_SALE, $used, $stamp);
        $webHorizon = gmdate('Y-m-d H:i:s', $now - $windows['webDays'] * 86400);

        $settled = ($readsUntil ?? $now) - $windows['catchUp'];

        return $linked + $this->catchUpAtExit($units->unitsToCatchUp($webHorizon, self::CATCH_UP_BATCH, LossSaleUnit::ORIGIN_WEB), $webHorizon,
            $windows['webDays'] * 86400, LossSaleUnit::ORIGIN_WEB, $used, $stamp, $settled);
    }

    /**
     * Le rattrapage à la sortie pour des articles d'une origine : une pièce non vendue de leur variante lue à la sortie entre
     * `CATCH_UP_BEFORE` avant et `$window` après l'heure de l'article. L'article le plus ancien d'abord, la première lecture d'abord,
     * une pièce par article. Une seule requête pour toutes les variantes.
     *
     * @param list<array<string, mixed>> $units articles en attente, dans l'ordre de leur heure
     * @param array<int, true>           $used    pièces déjà reliées dans ce passage
     * @param int|null                   $settled lectures prises jusqu'à cet instant seulement (commandes web : ce que la caisse ne peut plus expliquer)
     */
    private function catchUpAtExit(array $units, string $horizon, int $window, string $origin, array &$used, string $stamp, ?int $settled = null): int
    {
        if ($units === []) {
            return 0;
        }
        $waiting = array_map(static fn (array $u): array => $u + ['sold' => self::stamp((string) $u['sold_at'])], $units);
        $repository = $this->units();
        $until = max(array_column($waiting, 'sold')) + $window;
        $until = $settled !== null ? min($until, $settled) : $until;
        $web = $origin === LossSaleUnit::ORIGIN_WEB;
        $reads = $repository->exitReads(array_values(array_unique(array_map(static fn (array $u): int => (int) $u['variant_id'], $waiting))),
            gmdate('Y-m-d H:i:s', min(array_column($waiting, 'sold')) - self::CATCH_UP_BEFORE), gmdate('Y-m-d H:i:s', $until),
            $horizon, self::CATCH_UP_BEFORE, $window, self::EXIT_READS, $origin, $web);
        foreach ($reads as $variant => $list) {
            $reads[$variant] = array_map(static fn (array $r): array => $r + ['stamp' => self::stamp($r['at'])], $list);
        }
        $linked = 0;
        foreach ($waiting as $unit) { // l'article le plus ancien d'abord
            foreach ($reads[(int) $unit['variant_id']] ?? [] as $read) { // la première lecture d'abord
                if ($read['stamp'] > $unit['sold'] + $window) {
                    break; // les lectures sont dans l'ordre du temps : les suivantes sont plus tardives encore
                }
                if (isset($used[$read['piece']]) || $read['stamp'] < $unit['sold'] - self::CATCH_UP_BEFORE) {
                    continue;
                }
                if ($repository->link((int) $unit['id'], $read['piece'], LossSaleUnit::BY_CATCH_UP, $stamp)) {
                    $this->sold($read['piece'], $unit, LossSaleUnit::BY_CATCH_UP, $stamp, ['readEventId' => $read['event']] + ($web ? ['origin' => LossSaleUnit::ORIGIN_WEB] : []));
                    $used[$read['piece']] = true;
                    ++$linked;
                }
                break;
            }
        }

        return $linked;
    }

    /**
     * Lot L4-b2, à chaque passage, dans la transaction de l'analyse : les commandes WEB (boutique en ligne ou application), retirées ou expédiées.
     * 1. **Guettées** : une commande web dont un article n'est pas revenu. **Annulée** (statut annulé) : tous ses articles sont dits
     *    revenus (`cancellation`) et leurs pièces redeviennent vendables. **Articles rapportés** par un retour de la boutique
     *    (`order_return`, la caisse ne reprend pas une commande web) : autant d'articles de la ligne sont dits revenus (`return`), un
     *    article sans pièce d'abord. Un remboursement sans retour de marchandise (geste commercial) ne libère rien.
     * 2. **Nouvelles** : une commande web PAYÉE (`OrderPresenter::paymentStatus` = `paid`), passée pendant la fenêtre des commandes web,
     *    dont aucun article n'est encore écrit : chacun de ses articles suivis à l'heure de la commande devient un article à relier
     *    d'origine `web`, sans poste. Une commande pas encore payée est relue au passage suivant (les plus récentes d'abord).
     * Chaque commande sous son point de reprise : une commande en échec est sautée et dite, elle n'arrête pas les autres. Rien n'est écrit
     * dans les commandes ; leurs entités sont détachées après lecture (une commande relue au passage suivant l'est de la base).
     *
     * @return int articles de commandes web écrits
     */
    public function webOrders(int $now): int
    {
        if ($this->orders === null || !($this->tagged ??= $this->units()->hasTaggedPiece())) {
            return 0;
        }
        $units = $this->units();
        $days = $this->passWindows()['webDays'];
        $stamp = gmdate('Y-m-d H:i:s', $now);
        foreach ($units->openWebOrders(gmdate('Y-m-d H:i:s', $now - ($days + self::WEB_WATCH_DAYS) * 86400), self::WEB_ORDERS) as $orderId) {
            $this->eachOrder($orderId, function (?Order $order) use ($units, $orderId, $stamp): int {
                if ($order === null || $this->orders->paymentStatus($order) === OrderPresenter::PAYMENT_CANCELED) {
                    foreach ($units->openUnitsOfOrder($orderId) as $unit) {
                        $this->unitReturned($unit, $stamp, 0, LossSaleUnit::CANCELLATION, false);
                    }

                    return 0;
                }
                foreach ($units->webReturnsOf($orderId) as $itemId => $returned) {
                    $missing = $returned['quantity'] - $units->returnedUnitsOfItem($itemId);
                    $open = $units->openUnitsOfItem($itemId);
                    // un article sans pièce (pas encore retiré) revient d'abord
                    usort($open, static fn (array $a, array $b): int => [$a['piece_id'] !== null, (int) $a['id']] <=> [$b['piece_id'] !== null, (int) $b['id']]);
                    foreach (array_slice($open, 0, max(0, min($missing, self::MAX_UNITS_PER_ITEM))) as $unit) {
                        $this->unitReturned($unit, $returned['at'], 0, LossSaleUnit::RETURN, false);
                    }
                }

                return 0;
            });
        }
        $written = 0;
        foreach ($units->webOrdersToRead(gmdate('Y-m-d H:i:s', $now - $days * 86400), self::WEB_ORDERS) as $orderId) {
            $written += $this->eachOrder($orderId, function (?Order $order) use ($units, $orderId, $stamp): int {
                $row = $units->order($orderId);
                if ($order === null || $row === null || $this->orders->paymentStatus($order) !== OrderPresenter::PAYMENT_PAID) {
                    return 0;
                }
                $items = $units->itemsOf($orderId);
                $tracked = array_flip($units->trackedAt(array_values(array_unique(array_column($items, 'variant'))), $row['sold_at']));
                $written = 0;
                foreach ($items as $item) {
                    if (!isset($tracked[$item['variant']])) {
                        continue;
                    }
                    for ($n = 1; $n <= min($item['quantity'], self::MAX_UNITS_PER_ITEM); ++$n) {
                        if ($units->insert($orderId, $item['id'], $n, $item['variant'], null, $row['sold_at'], 0, $stamp, LossSaleUnit::ORIGIN_WEB) !== null) {
                            ++$written;
                        }
                    }
                }

                return $written;
            });
        }

        return $written;
    }

    /**
     * Une commande web sous son point de reprise : l'entité est lue de la base, l'étape jouée, puis l'entité détachée. Un échec
     * revient au point de reprise, est écrit au journal du serveur, et rend 0.
     *
     * @param callable(?Order): int $step
     */
    private function eachOrder(int $orderId, callable $step): int
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conn->executeStatement('SAVEPOINT loss_web_order');
        $order = null;
        try {
            $order = $em->find(Order::class, $orderId);
            $done = $step($order);
            $conn->executeStatement('RELEASE SAVEPOINT loss_web_order');
        } catch (\Throwable $e) {
            $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_web_order');
            $this->logger?->error(sprintf('Prévention des pertes : commande web %d en échec, sautée : %s', $orderId, $e->getMessage()), ['exception' => $e]);
            $done = 0;
        } finally {
            if ($order !== null && $em->contains($order)) {
                $em->detach($order);
            }
        }

        return $done;
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
     * @return array{counter: int, catchUp: int, presenceDays: int, webDays: int}
     */
    public function windows(): array
    {
        $row = $this->emProvider->getEntityManager()->getRepository(LossSetting::class)->row();

        return ['counter' => (int) ($row['counter_window_seconds'] ?? self::COUNTER_WINDOW), 'catchUp' => (int) ($row['catch_up_seconds'] ?? self::CATCH_UP_WINDOW),
            'presenceDays' => (int) ($row['presence_retention_days'] ?? self::PRESENCE_DAYS), 'webDays' => (int) ($row['web_window_days'] ?? self::WEB_DAYS)];
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
