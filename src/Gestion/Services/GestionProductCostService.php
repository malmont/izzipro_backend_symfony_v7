<?php

namespace App\Gestion\Services;

use App\Entity\Product;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Coût d'achat de la fiche d'un article (lot IA-ACT-3, 13/11/2026, `docs/conversation-gestion.md` § 11) : le poser SANS toucher au prix
 * de vente. `Product::setPurchasePrice()` recalcule le prix (coût × coefficient) : c'est la règle de la création d'un article, pas celle
 * d'une correction de coût. Ici le prix de vente reste une décision du gérant, jamais un effet de bord. Écriture par Doctrine (cache du
 * site invalidé) et journal d'audit (`products`, champ `purchasePrice`), à la pose comme à l'annulation.
 */
class GestionProductCostService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit)
    {
    }

    /** Coût de la fiche en cents, ou null (lecture seule) */
    public function current(int $productId): ?int
    {
        $v = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT purchase_price FROM product WHERE id = ?', [$productId]);

        return $v === false || $v === null ? null : (int) round((float) $v);
    }

    /**
     * @param ?int $cents null retire le coût de la fiche (annulation d'une première saisie)
     *
     * @return array{before: ?int, after: ?int}
     */
    public function set(int $productId, ?int $cents): array
    {
        $em = $this->emProvider->getEntityManager();
        $product = $em->find(Product::class, $productId);
        if ($product === null) {
            throw new GestionConflictException('PRODUCT_NOT_FOUND', 'Article introuvable.', 404, ['productId' => $productId]);
        }
        $before = $product->getPurchasePrice() === null ? null : (int) round((float) $product->getPurchasePrice());
        $price = $product->getPrice();
        $product->setPurchasePrice($cents === null ? null : (float) $cents);
        $em->getClassMetadata(Product::class)->setFieldValue($product, 'price', $price); // le prix de vente d'avant, quel qu'il soit (même absent)
        $em->flush();
        $this->audit->record('products', $productId, 'update', ['purchasePrice' => $before], ['purchasePrice' => $cents], ['purchasePrice'], 'fr');

        return ['before' => $before, 'after' => $cents];
    }
}
