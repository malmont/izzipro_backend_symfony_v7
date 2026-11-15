<?php

namespace App\Import;

use App\Entity\Categories;
use App\Entity\Color;
use App\Entity\InventoryMovements;
use App\Entity\MovementType;
use App\Entity\Product;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductPicture;
use App\Entity\ProductTranslation;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Lot IMP-1 : écrit UN article du plan d'import sur le site courant, dans sa propre transaction, comme le fait la création d'une fiche
 * (`ProductSheetCreator`) : même forme d'article, de traduction, de taille et de couleur, mêmes drapeaux par défaut.
 *
 * Propre à l'import : un prix, un stock et un code-barres PAR variante ; le stock de départ entre par un mouvement de type « Import »
 * (ni une réception ni un achat : il n'entre pas dans les entrées et réceptions des statistiques et ne crée aucune dépense) ; le
 * coût d'achat ne sert qu'à la valeur du stock ; tout ce qui est créé est noté dans `import_item` (rejeu sans doublon, annulation).
 */
class CatalogImportWriter
{
    public const MOVEMENT_TYPE = 'Import';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ImportJobStore $store,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $item un article du plan (`CatalogImportPlanner::plan`), état `ok`
     * @param array{visibility: string, pos: bool} $options choix du gérant à la confirmation
     * @param float $priceFactor 1.0, ou le facteur qui amène les prix du fichier au mode du site (taxes comprises ou hors taxes)
     * @return array{productId: int, variants: int, stock: int, barcodes: int, categoryCreated: bool}
     */
    public function write(int $jobId, string $source, array $item, array $options, float $priceFactor, string $language, ?string $by): array
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $categoryCreated = false;
            $category = null;
            if ($item['category'] !== null) {
                $category = $em->getRepository(Categories::class)->createQueryBuilder('c')->where('LOWER(c.name) = :n')->setParameter('n', mb_strtolower($item['category']))->setMaxResults(1)->getQuery()->getOneOrNullResult();
                if (!$category instanceof Categories) {
                    $category = (new Categories())->setName($item['category']);
                    $em->persist($category);
                    $categoryCreated = true;
                }
            }
            $price = static fn (int $cents): int => max(1, (int) round($cents * $priceFactor));
            $first = $item['variants'][0];
            $description = ImportHtml::clean((string) $item['body']);
            $slug = $this->uniqueSlug($em, (string) $item['name']);
            $code = $first['sku'] !== '' ? $first['sku'] : 'IMP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $product = (new Product())->setName($item['name'])->setDescription($description)->setMoreinformations(null)
                ->setPrice((float) $price($first['priceCents']))->setCode($code)->setSlug($slug)->setTags($item['tags'] !== '' ? $item['tags'] : null)->setQuantity(0)
                ->setIsWeb($options['visibility'] === 'as_source' && $item['visible'])->setIsPos($options['pos'])->setIsLandingPage(false)
                ->setSaleEnabled(true)->setRentalEnabled(false)->setSubscriptionEnabled(false)
                ->setIsbestseller(false)->setIsnewarrival(false)->setIsfeatured(false)->setIsspecialoffer(false)->setIsPreOrder(false)->setIsAccessory(false)->setCustomizable(false)
                ->setRequiresShipping(true)->setCreatedAt(new \DateTimeImmutable());
            if ($first['costCents'] !== null && $first['costCents'] > 0) {
                $product->setPurchasePrice((float) $first['costCents']); // sans coefficient : le prix de vente n'est jamais recalculé
            }
            if ($category !== null) {
                $product->addCategory($category);
            }
            $product->addTranslation((new ProductTranslation())->setLocale($language)->setName($item['name'])->setDescription($description)->setMoreinformations(null)->setTags($item['tags'] !== '' ? $item['tags'] : null)->setSlug($slug));
            $em->persist($product);
            $movementType = $em->getRepository(MovementType::class)->findOneBy(['name' => self::MOVEMENT_TYPE])
                ?? throw new \RuntimeException('Type de mouvement « Import » absent : la migration du lot IMP-1 n\'est pas appliquée à ce site');
            $variants = [];
            $sizes = [];
            $colors = [];
            $stock = 0;
            $barcodes = 0;
            foreach ($item['variants'] as $spec) {
                $variant = (new ProductVariant())->setStockQuantity((int) $spec['stock'])->setPrice($price($spec['priceCents']));
                if ($spec['size'] !== null) {
                    $variant->setSize($sizes[mb_strtolower($spec['size'])] ??= $this->size($em, $spec['size']));
                }
                if ($spec['color'] !== null) {
                    $variant->setColor($colors[mb_strtolower($spec['color'])] ??= $this->color($em, $spec['color']));
                }
                foreach ($spec['others'] as $other) {
                    $variant->addOptionValue($this->optionValue($em, $other['name'], $other['value']));
                }
                if ($spec['barcode'] !== null) {
                    $variant->setBarcode($spec['barcode']); // forme et liberté vérifiées par le plan ; la base refuse de toute façon un code déjà porté
                    ++$barcodes;
                }
                $product->addVariant($variant);
                $em->persist($variant);
                if ($spec['stock'] > 0) {
                    $em->persist((new InventoryMovements())->setProductVariant($variant)->setMovementType($movementType)->setQuantity((int) $spec['stock'])
                        ->setStockBeforeMovement(0)->setStockAfterMovement((int) $spec['stock'])->setMovementDate(new \DateTime()));
                    $stock += (int) $spec['stock'];
                }
                $variants[] = [$variant, $spec['ref']];
            }
            $em->flush();
            $productId = (int) $product->getId();
            $this->store->addItem($jobId, $source, ImportJobStore::ITEM_PRODUCT, $productId, (string) $item['ref']);
            foreach ($variants as [$variant, $ref]) {
                $this->store->addItem($jobId, $source, ImportJobStore::ITEM_VARIANT, (int) $variant->getId(), (string) $ref);
            }
            if ($categoryCreated) {
                $this->store->addItem($jobId, $source, ImportJobStore::ITEM_CATEGORY, (int) $category->getId(), mb_strtolower((string) $item['category']));
            }
            $this->audit->record('product', $productId, 'create', [], ['name' => $item['name'], 'priceCents' => $price($first['priceCents']), 'source' => 'import', 'importJob' => $jobId, 'importSource' => $source,
                'handle' => $item['handle'], 'variants' => count($variants), 'stock' => $stock, 'by' => $by], ['name', 'price', 'variants']);
            $conn->commit();

            return ['productId' => $productId, 'variants' => count($variants), 'stock' => $stock, 'barcodes' => $barcodes, 'categoryCreated' => $categoryCreated];
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    /** Range une image téléchargée dans la galerie de l'article ; la première devient aussi sa photo principale s'il n'en a pas */
    public function addPicture(int $jobId, string $source, int $productId, string $file): void
    {
        $em = $this->emProvider->getEntityManager();
        $product = $em->getRepository(Product::class)->find($productId);
        if (!$product instanceof Product) {
            return;
        }
        if (trim((string) $product->getImage()) === '') {
            $product->setImage($file);
        }
        $product->addPicture((new ProductPicture())->setImageUrl($file)); // comme la création d'une fiche : la photo principale est aussi dans la galerie
        $em->flush();
        $this->store->addItem($jobId, $source, ImportJobStore::ITEM_PICTURE, $productId, $file);
    }

    private function optionValue(EntityManagerInterface $em, string $name, string $value): ProductOptionValue
    {
        $option = $em->getRepository(ProductOption::class)->createQueryBuilder('o')->where('LOWER(o.name) = :n')->setParameter('n', mb_strtolower($name))->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (!$option instanceof ProductOption) {
            $option = (new ProductOption())->setName($name)->setCode($this->attributeCode($name));
            $em->persist($option);
            $em->flush();
        }
        $found = $em->getRepository(ProductOptionValue::class)->createQueryBuilder('v')->where('v.productOption = :o AND LOWER(v.value) = :v')
            ->setParameter('o', $option)->setParameter('v', mb_strtolower($value))->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($found instanceof ProductOptionValue) {
            return $found;
        }
        $created = (new ProductOptionValue())->setValue($value)->setCode($this->attributeCode($value))->setProductOption($option);
        $em->persist($created);
        $em->flush();

        return $created;
    }

    private function size(EntityManagerInterface $em, string $name): Size
    {
        $found = $em->getRepository(Size::class)->createQueryBuilder('s')->where('LOWER(s.name) = :n')->setParameter('n', mb_strtolower($name))->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($found instanceof Size) {
            return $found;
        }
        $size = (new Size())->setName($name)->setCode($this->attributeCode($name));
        $em->persist($size);
        $em->flush();

        return $size;
    }

    private function color(EntityManagerInterface $em, string $name): Color
    {
        $found = $em->getRepository(Color::class)->createQueryBuilder('c')->where('LOWER(c.name) = :n')->setParameter('n', mb_strtolower($name))->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($found instanceof Color) {
            return $found;
        }
        $color = (new Color())->setName($name)->setCode($this->attributeCode($name));
        $em->persist($color);
        $em->flush();

        return $color;
    }

    private function attributeCode(string $name): string
    {
        return mb_substr(strtoupper((new AsciiSlugger('fr'))->slug($name, '_')->toString() ?: 'X'), 0, 30);
    }

    private function uniqueSlug(EntityManagerInterface $em, string $name): string
    {
        $base = (new AsciiSlugger('fr'))->slug($name)->lower()->toString() ?: 'produit';
        $slug = $base;
        $repo = $em->getRepository(Product::class);
        for ($i = 2; $repo->findOneBy(['slug' => $slug]) !== null && $i < 1000; ++$i) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
