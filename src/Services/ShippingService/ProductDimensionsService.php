<?php

namespace App\Services\ShippingService;

use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ShippingClass;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Saisie rapide des poids et dimensions (livraison, lot 1 bis, 07/11/2026) : la grille des produits à expédier, et l'enregistrement en
 * un envoi (une fiche `product_shipping` par produit, créée ou mise à jour). Sert à la page « Poids et dimensions » du back-office, à
 * l'estimation par l'IA (ShippingDimensionsEstimator) et à l'assistant (action confirmée `apply_product_dimensions`).
 */
class ProductDimensionsService
{
    public const MAX_ROWS = 200;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ProductShippingResolver $resolver, private readonly ContentAuditRecorder $audit)
    {
    }

    /** @return list<array{id: int, name: string}> */
    public function classes(): array
    {
        return array_map(fn (ShippingClass $c) => ['id' => (int) $c->getId(), 'name' => (string) $c->getName()], $this->emProvider->getEntityManager()->getRepository(ShippingClass::class)->findBy([], ['name' => 'ASC']));
    }

    /**
     * Produits à expédier, avec leur fiche actuelle et la source des valeurs effectives.
     *
     * @return list<array{productId: int, name: string, description: string, categories: list<string>, imageUrl: ?string, weightKg: ?float, lengthCm: ?float, widthCm: ?float, heightCm: ?float, shippingClassId: ?int, source: string}>
     */
    public function rows(bool $onlyMissing, ?string $host = null, int $limit = self::MAX_ROWS): array
    {
        $em = $this->emProvider->getEntityManager();
        $rows = [];
        /** @var Product $product */
        foreach ($em->getRepository(Product::class)->findBy(['requiresShipping' => true], ['name' => 'ASC']) as $product) {
            $resolved = $this->resolver->resolve((int) $product->getId());
            if ($onlyMissing && $resolved['source'] === 'product') {
                continue;
            }
            /** @var ProductShipping|null $row */
            $row = $em->getRepository(ProductShipping::class)->findOneBy(['product' => $product]);
            $categories = [];
            foreach ($product->getCategory() as $category) {
                $categories[] = (string) $category->getName();
            }
            $rows[] = [
                'productId' => (int) $product->getId(),
                'name' => (string) $product->getName(),
                'description' => mb_substr(trim(strip_tags((string) $product->getDescription())), 0, 300),
                'categories' => $categories,
                'imageUrl' => $host !== null ? MediaUrlResolver::joinStored($product->getImage(), rtrim($host, '/') . '/assets/uploads/products') : null,
                'weightKg' => $row !== null && (float) $row->getWeightKg() > 0 ? (float) $row->getWeightKg() : null,
                'lengthCm' => $row !== null && (float) $row->getLengthCm() > 0 ? (float) $row->getLengthCm() : null,
                'widthCm' => $row?->getWidthCm(),
                'heightCm' => $row?->getHeightCm(),
                'shippingClassId' => $row?->getShippingClassEntity()?->getId(),
                'source' => $resolved['source'],
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Enregistre les lignes qui portent un poids ; une ligne sans poids est ignorée (rien n'est effacé). Dimensions absentes : valeurs
     * de la classe choisie, sinon dimensions types.
     *
     * @param list<array<string, mixed>> $rows productId, weightKg, lengthCm?, widthCm?, heightCm?, shippingClassId?
     * @return array{saved: int, skipped: int, errors: list<array{path: string, message: string}>}
     */
    public function save(array $rows, string $origin = 'grid'): array
    {
        $em = $this->emProvider->getEntityManager();
        $saved = 0;
        $skipped = 0;
        $errors = [];
        $changes = [];
        foreach (array_values($rows) as $i => $r) {
            $productId = (int) ($r['productId'] ?? 0);
            $product = $productId > 0 ? $em->getRepository(Product::class)->find($productId) : null;
            if ($product === null) {
                $errors[] = ['path' => "rows[$i].productId", 'message' => 'produit introuvable'];
                continue;
            }
            $weight = self::number($r['weightKg'] ?? null);
            if ($weight === null) {
                $skipped++;
                continue;
            }
            if ($weight <= 0 || $weight > 1000) {
                $errors[] = ['path' => "rows[$i].weightKg", 'message' => 'poids en kilogrammes attendu, entre 0,001 et 1000'];
                continue;
            }
            $dims = [];
            foreach (['lengthCm', 'widthCm', 'heightCm'] as $key) {
                $v = self::number($r[$key] ?? null);
                if ($v !== null && ($v <= 0 || $v > 500)) {
                    $errors[] = ['path' => "rows[$i].$key", 'message' => 'dimension en centimètres attendue, entre 0,1 et 500'];
                    continue 2;
                }
                $dims[$key] = $v;
            }
            $classId = isset($r['shippingClassId']) && $r['shippingClassId'] !== '' && $r['shippingClassId'] !== null ? (int) $r['shippingClassId'] : null;
            $class = $classId !== null ? $em->getRepository(ShippingClass::class)->find($classId) : null;
            if ($classId !== null && $class === null) {
                $errors[] = ['path' => "rows[$i].shippingClassId", 'message' => 'classe d\'expédition introuvable'];
                continue;
            }
            $defaults = $class !== null && (float) $class->getDefaultLengthCm() > 0 ? [(float) $class->getDefaultLengthCm(), (float) $class->getDefaultWidthCm(), (float) $class->getDefaultHeightCm()] : ProductShippingResolver::DEFAULT_DIMENSIONS_CM;
            /** @var ProductShipping|null $row */
            $row = $em->getRepository(ProductShipping::class)->findOneBy(['product' => $product]);
            $before = $row !== null ? ['weightKg' => $row->getWeightKg(), 'lengthCm' => $row->getLengthCm(), 'widthCm' => $row->getWidthCm(), 'heightCm' => $row->getHeightCm(), 'class' => $row->getShippingClassEntity()?->getId()] : null;
            if ($row === null) {
                $row = (new ProductShipping())->setProduct($product);
                $em->persist($row);
            }
            $row->setWeightKg($weight)->setLengthCm($dims['lengthCm'] ?? $defaults[0])->setWidthCm($dims['widthCm'] ?? $defaults[1])->setHeightCm($dims['heightCm'] ?? $defaults[2])
                ->setShippingClassEntity($class)->setShippingClass($class?->getName());
            $changes[] = ['productId' => $productId, 'before' => $before, 'after' => ['weightKg' => $weight, 'lengthCm' => $row->getLengthCm(), 'widthCm' => $row->getWidthCm(), 'heightCm' => $row->getHeightCm(), 'class' => $classId]];
            $saved++;
        }
        if ($errors !== []) {
            // tout ou rien : aucune écriture tant qu'une ligne est refusée
            $em->clear();

            return ['saved' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }
        if ($saved > 0) {
            $em->flush();
            $this->audit->record('product_shipping', null, 'bulk_' . $origin, null, $changes, ['weightKg', 'lengthCm', 'widthCm', 'heightCm', 'shippingClass']);
        }

        return ['saved' => $saved, 'skipped' => $skipped, 'errors' => []];
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', ' '], ['.', ''], trim($value));
        }

        return is_numeric($value) ? (float) $value : -1.0;
    }
}
