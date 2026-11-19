<?php

namespace App\Services\Group\Transfer;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\Fournisseur;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\TypeFournisseur;
use App\Gestion\Services\PeriodCalendar;
use App\Services\CommandeService\CommandeLineService;
use App\Services\Group\GroupException;
use App\Services\TenantEntityManagerProvider;

/**
 * Lot G-5 : les écritures d'un transfert dans la base d'UNE boutique (le site COURANT : l'appelant a fait `switchTenant`). Chaque geste tient
 * dans UNE transaction de cette base et pose ses MARQUES (`group_transfer_marks`, unique par transfert, sorte et ligne) dans la même
 * transaction : un rejeu trouve la marque et n'écrit rien de plus ; un rejeu concurrent échoue sur l'index unique au lieu de doubler.
 * - `sendOut` (source) : verrou des variantes dans un ordre fixe, stock relu SOUS le verrou, un mouvement « Transfert » sortant par ligne,
 *   coût unitaire du jour (coût moyen des lignes d'achat, sinon prix d'achat) ; stock court : rien n'est écrit.
 * - `sendBack` (source) : le mouvement inverse des lignes sorties et pas encore rendues (compensation, annulation après l'envoi).
 * - `createOrder` (destination) : une commande fournisseur « Transfert depuis <source> » dans la période en cours, ses lignes au coût de la
 *   source, fournisseur « Boutique <source> » créé au besoin ; jamais deux fois (marque `order`).
 */
class GroupTransferSiteWriter
{
    public const MOVEMENT_TYPE = 'Transfert';
    public const OUT = 'out';
    public const BACK = 'back';
    public const ORDER = 'order';
    /** Attente maximale du verrou de stock (secondes) : au-delà, 409 TRANSFER_STOCK_BUSY, rien n'est écrit */
    public const LOCK_TIMEOUT = '5s';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CommandeLineService $commandeLines,
        private readonly ?PeriodCalendar $periods = null,
        private readonly ?\Doctrine\Persistence\ManagerRegistry $doctrine = null,
    ) {
    }

    /**
     * @param list<array{line_no: int, source_variant_id: int, quantity: int}> $lines
     * @return array{replayed: bool, costs: array<int, ?int>} le coût unitaire du jour par ligne (centimes ; vide si tout était déjà sorti)
     * @throws GroupException TRANSFER_STOCK_SHORT, TRANSFER_STOCK_BUSY, TRANSFER_NOT_AVAILABLE
     */
    public function sendOut(string $uid, array $lines): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $type = $this->movementType();
        $conn->beginTransaction();
        try {
            $conn->executeStatement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            // le verrou des variantes D'ABORD (toutes les lignes, ordre fixe), les marques ENSUITE : deux gestes concurrents du même transfert
            // se suivent, et le second voit les marques du premier
            $ids = array_values(array_unique(array_map(static fn (array $l): int => $l['source_variant_id'], $lines)));
            sort($ids);
            try {
                // verrou dans un ORDRE FIXE (jamais d'interblocage entre deux envois) ; tout autre geste de stock attend la fin de l'envoi
                $rows = $conn->fetchAllAssociative('SELECT id, product_id, stock_quantity FROM product_variant WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE');
            } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException $e) {
                throw new GroupException('TRANSFER_STOCK_BUSY', 409, 'Le stock de la boutique source est occupé par un autre geste : réessayez dans un instant.', [], $e);
            }
            $stock = array_column($rows, null, 'id');
            $done = $this->marks($uid, self::OUT);
            $todo = array_values(array_filter($lines, static fn (array $l): bool => !isset($done[$l['line_no']])));
            // le coût du jour de CHAQUE ligne (aussi à la reprise : il est écrit là où il manque encore)
            $costs = [];
            foreach ($lines as $l) {
                $costs[$l['line_no']] = isset($stock[$l['source_variant_id']]) ? $this->unitCost((int) $stock[$l['source_variant_id']]['product_id']) : null;
            }
            if ($todo === []) {
                $conn->commit();

                return ['replayed' => true, 'costs' => $costs];
            }
            $need = [];
            foreach ($todo as $l) {
                $need[$l['source_variant_id']] = ($need[$l['source_variant_id']] ?? 0) + $l['quantity'];
            }
            $short = [];
            foreach ($need as $variantId => $quantity) {
                $available = isset($stock[$variantId]) ? (int) $stock[$variantId]['stock_quantity'] : 0;
                if ($available < $quantity) {
                    $short[] = ['variantId' => $variantId, 'requested' => $quantity, 'available' => max(0, $available)];
                }
            }
            if ($short !== []) {
                throw new GroupException('TRANSFER_STOCK_SHORT', 409, 'Le stock de la boutique source ne suffit pas : rien n\'est sorti.', ['lines' => $short]);
            }
            foreach ($todo as $l) {
                $variantId = $l['source_variant_id'];
                $before = (int) $stock[$variantId]['stock_quantity'];
                $after = $before - $l['quantity'];
                $conn->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$after, $variantId]);
                $stock[$variantId]['stock_quantity'] = $after;
                $movement = $this->movement($variantId, -$l['quantity'], $before, $after, $type);
                $this->mark($uid, self::OUT, $l['line_no'], $movement);
            }
            $conn->commit();

            return ['replayed' => false, 'costs' => $costs];
        } catch (\Throwable $e) {
            $conn->isTransactionActive() && $conn->rollBack();

            throw $e;
        }
    }

    /**
     * Rend à la source ce qui est sorti et pas encore rendu (compensation d'un envoi en échec, annulation après l'envoi).
     *
     * @param list<array{line_no: int, source_variant_id: int, quantity: int}> $lines
     * @return int le nombre de lignes rendues maintenant
     */
    public function sendBack(string $uid, array $lines): int
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $type = $this->movementType();
        $conn->beginTransaction();
        try {
            $conn->executeStatement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            $ids = array_values(array_unique(array_map(static fn (array $l): int => $l['source_variant_id'], $lines)));
            sort($ids);
            $stock = $ids === [] ? [] : array_column($conn->fetchAllAssociative('SELECT id, stock_quantity FROM product_variant WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE'), null, 'id');
            // les marques APRÈS le verrou : un retour concurrent du même transfert attend, puis voit les marques du premier
            $out = $this->marks($uid, self::OUT);
            $back = $this->marks($uid, self::BACK);
            $todo = array_values(array_filter($lines, static fn (array $l): bool => isset($out[$l['line_no']]) && !isset($back[$l['line_no']])));
            if ($todo !== []) {
                foreach ($todo as $l) {
                    $variantId = $l['source_variant_id'];
                    $before = (int) ($stock[$variantId]['stock_quantity'] ?? 0);
                    $after = $before + $l['quantity'];
                    $conn->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$after, $variantId]);
                    $stock[$variantId]['stock_quantity'] = $after;
                    $this->mark($uid, self::BACK, $l['line_no'], $this->movement($variantId, $l['quantity'], $before, $after, $type));
                }
            }
            $conn->commit();

            return count($todo);
        } catch (\Throwable $e) {
            $conn->isTransactionActive() && $conn->rollBack();

            throw $e;
        }
    }

    /**
     * La commande fournisseur « en route » de la DESTINATION ; jamais deux fois (marque `order`).
     *
     * @param list<array{line_no: int, target_product_id: int, target_variant_id: ?int, quantity: int, unit_cost_cents: ?int}> $lines
     * @return int l'identifiant de la commande
     */
    public function createOrder(string $uid, string $sourceName, array $lines, ?string $by): int
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $existing = $this->marks($uid, self::ORDER);
        if (isset($existing[0]) && $existing[0] !== null) {
            return (int) $existing[0];
        }
        $conn->beginTransaction();
        try {
            $conn->executeStatement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            // un nom à part (« [Groupe] … ») : jamais un vrai fournisseur du même nom
            $supplierName = mb_substr('[Groupe] Boutique ' . $sourceName, 0, 255);
            $supplier = $em->getRepository(Fournisseur::class)->findOneBy(['name' => $supplierName]);
            if ($supplier === null) {
                $type = $em->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $em->getRepository(TypeFournisseur::class)->findOneBy([], ['id' => 'ASC']);
                $supplier = (new Fournisseur())->setName($supplierName)->setTypeFournisseur($type);
                $em->persist($supplier);
            }
            $period = ($this->periods ?? throw new GroupException('TRANSFER_NOT_AVAILABLE', 409, 'La gestion de la boutique de destination n\'a pas de période.'))->current();
            $commande = (new Commande())->setName(mb_substr('Transfert depuis ' . $sourceName, 0, 255))->setDate(new \DateTime())->setCollections($period)->setFournisseur($supplier)->setBudget(0);
            $em->persist($commande);
            $em->flush();
            foreach ($lines as $l) {
                $product = $em->find(Product::class, $l['target_product_id']);
                $variant = $l['target_variant_id'] !== null ? $em->find(ProductVariant::class, $l['target_variant_id']) : null;
                if ($product === null) {
                    throw new GroupException('TRANSFER_ITEM_NOT_IN_TARGET', 422, 'Un article du transfert n\'existe plus dans la boutique de destination.', ['lines' => [$l['line_no']]]);
                }
                $this->commandeLines->add($commande, $product, $variant, $l['quantity'], $l['unit_cost_cents'], CommandeLine::SOURCE_TRANSFER, $by);
            }
            $this->mark($uid, self::ORDER, 0, (int) $commande->getId());
            $conn->commit();

            return (int) $commande->getId();
        } catch (\Throwable $e) {
            $conn->isTransactionActive() && $conn->rollBack();
            // un flush en échec FERME l'EntityManager (unique pour la requête) : il est rouvert, sinon tout geste suivant échouerait
            if (!$em->isOpen()) {
                $this->doctrine?->resetManager();
            } else {
                $em->clear();
            }

            throw $e;
        }
    }

    /** @return array{lines: int, received: int, partly: bool, all: bool} l'état de réception de la commande de destination (lu, jamais écrit) */
    public function reception(int $commandeId): array
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS lines, COUNT(*) FILTER (WHERE quantity_received >= quantity_ordered AND quantity_ordered > 0) AS done, COALESCE(SUM(quantity_received), 0) AS got FROM commande_line WHERE commande_id = ?', [$commandeId]) ?: [];
        $lines = (int) ($row['lines'] ?? 0);

        return ['lines' => $lines, 'received' => (int) ($row['done'] ?? 0), 'partly' => (int) ($row['got'] ?? 0) > 0, 'all' => $lines > 0 && (int) ($row['done'] ?? 0) === $lines];
    }

    /**
     * Annule la commande de destination d'un transfert annulé APRÈS l'envoi : elle est fermée, ses lignes remises à zéro ; refusé si une
     * réception a commencé. Rend faux si rien à annuler (aucune commande).
     *
     * @throws GroupException TRANSFER_PARTLY_RECEIVED
     */
    public function cancelOrder(int $commandeId, int $expectedLines): bool
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            // relecture G-5, passe 2 : une commande disparue ou changée n'est jamais « rien à annuler » (la marchandise reçue serait rendue deux fois)
            if ($conn->fetchOne('SELECT id FROM commande WHERE id = ? FOR UPDATE', [$commandeId]) === false
                || (int) $conn->fetchOne("SELECT COUNT(*) FROM commande_line WHERE commande_id = ? AND source = ?", [$commandeId, CommandeLine::SOURCE_TRANSFER]) < min(1, $expectedLines)) {
                throw new GroupException('TRANSFER_ORDER_CHANGED', 409, 'La commande du transfert a été modifiée ou supprimée chez la boutique de destination : l\'annulation est refusée ; voyez avec elle.');
            }
            // les lignes VERROUILLÉES avant de lire ce qui est reçu : une réception en cours attend ou est attendue, jamais croisée
            $products = array_values(array_unique(array_map('intval', $conn->fetchFirstColumn('SELECT product_id FROM commande_line WHERE commande_id = ? ORDER BY id FOR UPDATE', [$commandeId]))));
            if ((int) $conn->fetchOne('SELECT COALESCE(SUM(quantity_received), 0) FROM commande_line WHERE commande_id = ?', [$commandeId]) > 0) {
                throw new GroupException('TRANSFER_PARTLY_RECEIVED', 409, 'Une partie du transfert est déjà reçue : il ne s\'annule plus.');
            }
            $conn->executeStatement('UPDATE commande_line SET quantity_ordered = 0, updated_at = NOW() WHERE commande_id = ?', [$commandeId]);
            $conn->executeStatement("UPDATE commande SET is_closed = TRUE, name = CASE WHEN name LIKE '[annulé]%' THEN name ELSE LEFT('[annulé] ' || COALESCE(name, ''), 255) END WHERE id = ?", [$commandeId]);
            $conn->commit();
            // les raccourcis d'achat des articles (`product.commande_*`) ne pointent plus vers la commande annulée
            $em = $this->emProvider->getEntityManager();
            foreach ($products as $productId) {
                $product = $em->find(Product::class, $productId);
                if ($product !== null) {
                    $this->commandeLines->refreshShortcuts($product);
                }
            }

            return true;
        } catch (\Throwable $e) {
            $conn->isTransactionActive() && $conn->rollBack();

            throw $e;
        }
    }

    /** @return array<int, ?int> line_no => ref_id des marques de ce transfert pour cette sorte */
    public function marks(string $uid, string $kind): array
    {
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT line_no, ref_id FROM group_transfer_marks WHERE transfer_uid = ? AND kind = ?', [$uid, $kind]) as $row) {
            $out[(int) $row['line_no']] = $row['ref_id'] === null ? null : (int) $row['ref_id'];
        }

        return $out;
    }

    private function mark(string $uid, string $kind, int $lineNo, ?int $ref): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeStatement('INSERT INTO group_transfer_marks (transfer_uid, kind, line_no, ref_id) VALUES (?, ?, ?, ?)', [$uid, $kind, $lineNo, $ref]);
    }

    private function movement(int $variantId, int $quantity, int $before, int $after, int $type): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            // l'identifiant vient de la séquence Doctrine (la colonne n'a pas de valeur par défaut)
            "INSERT INTO inventory_movements (id, product_variant_id, movement_type_id, quantity, movement_date, stock_before_movement, stock_after_movement) VALUES (nextval('inventory_movements_id_seq'), ?, ?, ?, NOW(), ?, ?) RETURNING id",
            [$variantId, $type, $quantity, $before, $after]);
    }

    private function movementType(): int
    {
        $id = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT id FROM movement_type WHERE name = ?', [self::MOVEMENT_TYPE]);
        if ($id === false) {
            throw new GroupException('TRANSFER_NOT_AVAILABLE', 409, 'Le transfert n\'est pas encore installé sur cette boutique.');
        }

        return (int) $id;
    }

    /** Coût unitaire du jour : coût moyen pondéré des lignes d'achat datées au plus tard aujourd'hui, sinon prix d'achat de la fiche (centimes) */
    private function unitCost(int $productId): ?int
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $avg = $conn->fetchOne('SELECT SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(c.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0) FROM commande_line l JOIN commande c ON c.id = l.commande_id
            WHERE l.product_id = ? AND l.unit_cost_cents IS NOT NULL AND l.quantity_ordered > 0 AND c.date <= NOW()', [$productId]);
        if ($avg !== false && $avg !== null) {
            return (int) round((float) $avg);
        }
        $fiche = $conn->fetchOne('SELECT purchase_price FROM product WHERE id = ?', [$productId]);

        return $fiche !== false && $fiche !== null ? (int) round((float) $fiche) : null;
    }
}
