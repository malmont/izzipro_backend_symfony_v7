<?php

namespace App\Gestion\Services;

use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Articles sans coût d'achat (lot IA-SEQ, 13/11/2026, `docs/sequences-gestion.md` § 5) : LA règle, à un seul endroit. Un article standard,
 * hors location, n'a pas de coût quand il n'en porte ni sur sa fiche (`purchase_price` absent ou nul) ni sur une ligne d'achat chiffrée
 * (`commande_line.unit_cost_cents`). Le compteur du cheminement (`management.purchase_cost`), les étapes du guide des séquences et la liste
 * `GET /api/gestion/articles?filter=missing-cost` passent tous par les fragments ci-dessous : le nombre d'une étape et la liste qu'elle
 * ouvre disent toujours la même chose.
 *
 * Avec un créneau de jours du magasin, la règle ne garde que les articles vendus dans ce créneau (quantité nette positive, mêmes règles de
 * vente que la reprise d'historique : ventes et retours, hors commandes annulées ou en attente, hors location et abonnement).
 */
class MissingCostQuery
{
    /** Articles suivis par la gestion : standards, hors location (alias `p`) */
    public const ARTICLES = "p.product_type = 'standard' AND COALESCE(p.rental_enabled, false) = false";

    /** Sans coût : ni sur la fiche, ni sur une ligne d'achat chiffrée (alias `p`) */
    public const MISSING = '(p.purchase_price IS NULL OR p.purchase_price <= 0) AND NOT EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL)';

    /** Vendu dans le créneau [:soldFrom, :soldTo[ (UTC), quantité nette positive (alias `p`) */
    public const SOLD = 'p.id IN (SELECT pv.product_id FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id'
        . ' JOIN product_variant pv ON pv.id = oi.product_variant_id LEFT JOIN booking b ON b.order_item_id = oi.id'
        . ' WHERE o.order_date >= :soldFrom AND o.order_date < :soldTo AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND b.id IS NULL AND o.subscription_id IS NULL'
        . ' GROUP BY pv.product_id HAVING SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) > 0 AND MIN(o.order_date) FILTER (WHERE o.order_type_id = 1) IS NOT NULL)';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock)
    {
    }

    /**
     * Nombre d'articles sans coût et nombre d'articles suivis, sur tout le catalogue ou parmi les articles vendus du créneau.
     *
     * @return array{missing: int, total: int}
     */
    public function count(?string $from = null, ?string $to = null): array
    {
        [$scope, $params] = $this->scope($from, $to);
        $conn = $this->emProvider->getEntityManager()->getConnection();

        return [
            'missing' => (int) $conn->fetchOne('SELECT COUNT(*) FROM product p WHERE ' . $scope . ' AND ' . self::MISSING, $params),
            'total' => (int) $conn->fetchOne('SELECT COUNT(*) FROM product p WHERE ' . $scope, $params),
        ];
    }

    /**
     * Page de la liste des articles sans coût, par nom.
     *
     * @return array{total: int, items: list<array{id: int, name: ?string, code: ?string, image: ?string, price: ?int, stock: int, commandeId: ?int, costSource: ?string}>}
     */
    public function page(?string $q, ?string $from, ?string $to, ?int $limit, int $offset): array
    {
        [$scope, $params] = $this->scope($from, $to);
        $where = $scope . ' AND ' . self::MISSING;
        if ($q !== null && $q !== '') {
            $where .= " AND (LOWER(p.name) LIKE :q OR LOWER(COALESCE(p.code, '')) LIKE :q)";
            $params['q'] = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM product p WHERE ' . $where, $params);
        $rows = $conn->fetchAllAssociative(
            'SELECT p.id, p.name, p.code, p.image, p.price, p.quantity, p.commande_id FROM product p WHERE ' . $where . ' ORDER BY LOWER(p.name), p.id'
            . ($limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . $offset : ''),
            $params
        );

        return ['total' => $total, 'items' => array_map(fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'image' => $r['image'],
            'price' => $r['price'] !== null ? (int) round((float) $r['price']) : null, 'stock' => (int) ($r['quantity'] ?? 0),
            'commandeId' => $r['commande_id'] !== null ? (int) $r['commande_id'] : null,
            'costSource' => null, // sans coût par définition de ce filtre ; le champ sert aux filtres à venir
        ], $rows)];
    }

    /**
     * Condition et paramètres du périmètre : le catalogue suivi, restreint aux articles vendus dans le créneau quand il est donné.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function scope(?string $from, ?string $to): array
    {
        if ($from === null && $to === null) {
            return [self::ARTICLES, []];
        }
        foreach (['from' => $from, 'to' => $to] as $field => $day) {
            if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || \DateTimeImmutable::createFromFormat('!Y-m-d', $day)?->format('Y-m-d') !== $day) {
                throw new GestionValidationException([$field => 'INVALID_DATE'], 'from et to : deux jours AAAA-MM-JJ attendus ensemble');
            }
        }
        if ($to < $from) {
            throw new GestionValidationException(['to' => 'INVALID_RANGE'], 'to : antérieur à from');
        }
        [$start, $end] = $this->clock->range($from, $to);

        return [self::ARTICLES . ' AND ' . self::SOLD, ['soldFrom' => $start->format('Y-m-d H:i:s'), 'soldTo' => $end->format('Y-m-d H:i:s')]];
    }
}
