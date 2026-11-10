<?php

namespace App\Services\CommandeService;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\CommandeLineRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Seule écriture des lignes d'achat (lot L0 de la gestion, 10/11/2026, `docs/lignes-achat-gestion.md`) : ajout ou mise à jour
 * idempotente (même commande, même article, même variante), suppression, et raccourcis du produit (`commande_id`,
 * `commande_cost_cents`, `commande_quantity`, `commande_attached_at`, `commande_cost_estimated`) recalculés sur sa dernière ligne,
 * ou remis à NULL quand il n'en reste aucune. Jamais le stock, le prix de vente, le prix d'achat de la fiche ni la visibilité.
 */
class CommandeLineService
{
    /** Reprise des rattachements existants en lignes : texte identique à `Version20261202100000::BACKFILL_SQL` (vérifié par CommandeLinesApiTest) */
    public const BACKFILL_SQL = "INSERT INTO commande_line (commande_id, product_id, product_variant_id, quantity_ordered, quantity_received, unit_cost_cents, estimated, attached_at, source, created_by, created_at, updated_at) SELECT p.commande_id, p.id, NULL, COALESCE(p.commande_quantity, p.quantity, 0), COALESCE(p.commande_quantity, p.quantity, 0), CASE WHEN p.commande_cost_cents IS NULL THEN CASE WHEN p.purchase_price IS NOT NULL THEN ROUND(p.purchase_price)::int END WHEN p.purchase_price IS NULL AND p.commande_cost_cents = ROUND(p.price) THEN NULL ELSE p.commande_cost_cents END, COALESCE(p.commande_cost_estimated, FALSE), COALESCE(p.commande_attached_at, c.date, NOW()), 'migration', 'migration L0', NOW(), NOW() FROM product p JOIN commande c ON c.id = p.commande_id WHERE NOT EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = p.id AND l.commande_id = p.commande_id)";

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ?\App\Cache\CacheInvalidationQueue $cacheQueue = null)
    {
    }

    /**
     * @return array{line: CommandeLine, created: bool}
     */
    public function upsert(Commande $commande, Product $product, ?ProductVariant $variant, int $quantityOrdered, ?int $unitCostCents, bool $estimated, string $source, ?\DateTimeImmutable $attachedAt = null, ?int $quantityReceived = null, ?string $by = null): array
    {
        $em = $this->emProvider->getEntityManager();
        /** @var CommandeLineRepository $repo */
        $repo = $em->getRepository(CommandeLine::class);
        $now = new \DateTimeImmutable();
        $line = $repo->findOne($commande, $product, $variant);
        $created = $line === null;
        if ($created) {
            $line = (new CommandeLine())->setCommande($commande)->setProduct($product)->setVariant($variant)->setSource($source)->setCreatedBy($by)->setCreatedAt($now)
                ->setAttachedAt($attachedAt ?? $now);
            $em->persist($line);
        } elseif ($attachedAt !== null) {
            $line->setAttachedAt($attachedAt);
        }
        $line->setQuantityOrdered($quantityOrdered)->setQuantityReceived($quantityReceived ?? $quantityOrdered)->setUnitCostCents($unitCostCents)->setEstimated($estimated)->setUpdatedAt($now);
        $em->flush();
        $this->refreshShortcuts($product);

        return ['line' => $line, 'created' => $created];
    }

    /** @param array{quantity?: int, unitCost?: ?int, variant?: ?ProductVariant} $fields */
    public function update(CommandeLine $line, array $fields): CommandeLine
    {
        if (array_key_exists('quantity', $fields)) {
            $received = $line->getQuantityReceived() === $line->getQuantityOrdered() ? $fields['quantity'] : min($line->getQuantityReceived(), $fields['quantity']);
            $line->setQuantityOrdered($fields['quantity'])->setQuantityReceived($received);
        }
        if (array_key_exists('unitCost', $fields)) {
            $line->setUnitCostCents($fields['unitCost'])->setEstimated(false);
        }
        if (array_key_exists('variant', $fields)) {
            $line->setVariant($fields['variant']);
        }
        $line->setUpdatedAt(new \DateTimeImmutable());
        $this->emProvider->getEntityManager()->flush();
        $this->refreshShortcuts($line->getProduct());

        return $line;
    }

    public function delete(CommandeLine $line): void
    {
        $em = $this->emProvider->getEntityManager();
        $product = $line->getProduct();
        $em->remove($line);
        $em->flush();
        $this->refreshShortcuts($product);
    }

    /** Lignes de l'article dans la commande supprimées ; nombre supprimé */
    public function deleteProductLines(Commande $commande, Product $product): int
    {
        $em = $this->emProvider->getEntityManager();
        /** @var CommandeLineRepository $repo */
        $repo = $em->getRepository(CommandeLine::class);
        $lines = $repo->forCommandeAndProduct($commande, $product);
        foreach ($lines as $line) {
            $em->remove($line);
        }
        $em->flush();
        $this->refreshShortcuts($product);

        return count($lines);
    }

    /** Raccourcis du produit sur sa dernière ligne (ou NULL) ; écrits en SQL : rien d'autre du produit ne bouge */
    public function refreshShortcuts(Product $product): void
    {
        $em = $this->emProvider->getEntityManager();
        /** @var CommandeLineRepository $repo */
        $repo = $em->getRepository(CommandeLine::class);
        $last = $repo->latestForProduct($product);
        $values = $last === null
            ? ['c' => null, 'cost' => null, 'qty' => null, 'at' => null, 'est' => false]
            : ['c' => $last->getCommande()->getId(), 'cost' => $last->getUnitCostCents(), 'qty' => $last->getQuantityOrdered(), 'at' => $last->getAttachedAt()->format('Y-m-d H:i:s'), 'est' => $last->isEstimated()];
        $em->getConnection()->executeStatement(
            'UPDATE product SET commande_id = :c, commande_cost_cents = :cost, commande_quantity = :qty, commande_attached_at = :at, commande_cost_estimated = :est WHERE id = :id',
            $values + ['id' => $product->getId()],
            ['est' => \Doctrine\DBAL\ParameterType::BOOLEAN]
        );
        // écriture SQL directe (aucun événement Doctrine) : fiche et listes du produit invalidées explicitement
        $dbname = $em->getConnection()->getParams()['dbname'] ?? '';
        if ($this->cacheQueue !== null && is_string($dbname) && $dbname !== '') {
            $this->cacheQueue->add($dbname, [
                \App\Services\TenantCacheService::componentTag($dbname, '', \App\Cache\CacheTags::PRODUCTS . '.list'),
                \App\Services\TenantCacheService::componentTag($dbname, '', \App\Cache\CacheTags::PRODUCTS . '.item.' . $product->getId()),
            ]);
            $this->cacheQueue->commit($dbname);
        }
        $em->refresh($product);
    }

    /** Reprise des rattachements existants (lignes `migration`) ; nombre de lignes créées */
    public function backfill(): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->executeStatement(self::BACKFILL_SQL);
    }
}
