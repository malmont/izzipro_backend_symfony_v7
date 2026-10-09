<?php

namespace App\Services\LandingContentService;

use App\Entity\CheckoutSession;
use App\Entity\Color;
use App\Entity\ColorTranslation;
use App\Entity\Feature;
use App\Entity\InventoryMovements;
use App\Entity\OrderItems;
use App\Entity\Product;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\SizeTranslation;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\LandingContentUseCase\LandingContentException;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Créations, suppressions et ordre des données de la boutique depuis l'éditeur de la page (09/10/2026) : variantes,
 * couleurs, tailles, valeurs d'option, combinaisons de personnalisation, atouts de l'accueil. Les champs sont
 * contrôlés par les mêmes règles que leur PATCH (LandingContentSpec, LandingContentEditor::validate).
 */
final class BoutiqueCatalogEditor
{
    public const MAX_FEATURES = 50;
    private const COLOR_HEXA = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingContentEditor $editor,
        private readonly UpdateStockAndInventoryUseCase $stock,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws LandingContentException 404
     */
    public function find(string $class, int $id, string $label): object
    {
        return $this->emProvider->getEntityManager()->getRepository($class)->find($id) ?? throw new LandingContentException(404, $label . ' introuvable sur ce site.');
    }

    /** { colorId, sizeId, stockQuantity, price? } (ancienne forme acceptée : color.id, size.id) */
    public function createVariant(Product $product, object $body): ProductVariant
    {
        if (isset($body->color->id) && !isset($body->colorId)) {
            $body->colorId = $body->color->id;
        }
        if (isset($body->size->id) && !isset($body->sizeId)) {
            $body->sizeId = $body->size->id;
        }
        $fields = (object) array_intersect_key(get_object_vars($body), array_flip(['colorId', 'sizeId', 'stockQuantity', 'price']));
        $fields->stockQuantity ??= 0;
        $values = $this->validated('product-variants', $fields);
        $em = $this->emProvider->getEntityManager();
        LandingContentHooks::assertUniquePair($product->getVariants(), null, $values['colorId'] ?? null, $values['sizeId'] ?? null);

        $variant = (new ProductVariant())->setStockQuantity(0)->setPrice($values['price'] ?? null)
            ->setColor(isset($values['colorId']) ? $em->getRepository(Color::class)->find($values['colorId']) : null)
            ->setSize(isset($values['sizeId']) ? $em->getRepository(Size::class)->find($values['sizeId']) : null);
        $product->addVariant($variant);
        $em->persist($variant);
        if ($values['stockQuantity'] > 0) {
            $this->stock->execute($variant, (int) $values['stockQuantity'], true); // mouvement d'inventaire, comme l'administration
        }
        $em->flush();

        return $variant;
    }

    /**
     * Suppression réelle (il n'y a pas d'état « désactivé » sur une variante ; pour la retirer de la vente sans la
     * supprimer : stockQuantity 0). Refusée (409) si une commande la contient (historique gardé) ou si un paiement en
     * cours la contient ; son historique de stock et ses combinaisons partent avec elle.
     */
    public function deleteVariant(ProductVariant $variant): void
    {
        $em = $this->emProvider->getEntityManager();
        if ($em->getRepository(OrderItems::class)->count(['productVariant' => $variant]) > 0) {
            throw new LandingContentException(409, 'Cette variante figure dans des commandes : elle ne peut pas être supprimée. Pour la retirer de la vente, mettez son stock à 0.');
        }
        $inCart = (int) $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM checkout_session WHERE status IN (?, ?) AND cart::text ~ ?",
            [CheckoutSession::STATUS_OPEN, CheckoutSession::STATUS_PROCESSING, '"productVariantId"\s*:\s*"?' . (int) $variant->getId() . '\b']
        );
        if ($inCart > 0) {
            throw new LandingContentException(409, 'Cette variante est dans un paiement en cours : réessayez plus tard, ou mettez son stock à 0.');
        }
        $em->createQuery('DELETE FROM ' . InventoryMovements::class . ' m WHERE m.productVariant = :v')->setParameter('v', $variant)->execute();
        $variant->getProduct()?->removeVariant($variant);
        $em->remove($variant);
        $em->flush();
    }

    /** { name*, codeHexa } ; le nom est aussi écrit dans la langue demandée */
    public function createColor(object $body, string $locale): Color
    {
        $name = $this->name($body);
        $hexa = $body->codeHexa ?? null;
        if ($hexa !== null && (!is_string($hexa) || !preg_match(self::COLOR_HEXA, $hexa))) {
            throw new LandingContentException(422, 'codeHexa : couleur #rrggbb attendue', [['path' => 'codeHexa', 'message' => 'couleur #rrggbb ou #rgb attendue']]);
        }
        $color = (new Color())->setName($name)->setCodeHexa($hexa)->setCode(self::code($name));
        $color->addTranslation((new ColorTranslation())->setLanguage($locale)->setName($name)->setColor($color));
        $em = $this->emProvider->getEntityManager();
        $em->persist($color);
        $em->flush();

        return $color;
    }

    /** { name* } */
    public function createSize(object $body, string $locale): Size
    {
        $name = $this->name($body);
        $size = (new Size())->setName($name)->setCode(self::code($name));
        $size->addTranslation((new SizeTranslation())->setLanguage($locale)->setName($name)->setSize($size));
        $em = $this->emProvider->getEntityManager();
        $em->persist($size);
        $em->flush();

        return $size;
    }

    /** { name*, priceDelta (cents), icon } */
    public function addValue(ProductOption $option, object $body, string $locale): ProductOptionValue
    {
        $values = $this->validated('customization-values', $body, ['name']);
        $value = (new ProductOptionValue())->setProductOption($option)->setValue((string) $values['name'])->setCode(self::code((string) $values['name']));
        $em = $this->emProvider->getEntityManager();
        $em->persist($value);
        $em->flush();
        $this->editor->apply('customization-values', $value, $values, $locale);

        return $value;
    }

    /** Refusée (409) si une combinaison ou une variante l'utilise : la retirer d'abord (aucun retrait silencieux) */
    public function deleteValue(ProductOptionValue $value): void
    {
        $combinations = array_map(fn ($c) => $c->getId(), $value->getProductCustomizationImages()->toArray());
        $variants = array_map(fn ($v) => $v->getId(), $value->getProductVariants()->toArray());
        if ($combinations !== [] || $variants !== []) {
            throw new LandingContentException(409, sprintf('Cette valeur est utilisée (%s) : supprimez d\'abord ces combinaisons ou retirez-la de ces variantes.',
                implode(' ; ', array_filter([$combinations ? 'combinaisons n° ' . implode(', ', $combinations) : null, $variants ? 'variantes n° ' . implode(', ', $variants) : null]))));
        }
        $em = $this->emProvider->getEntityManager();
        $em->remove($value);
        $em->flush();
    }

    /** { optionIds* (identifiants de valeurs d'option), image*, stock } ; un ensemble d'options déjà présent : 422 */
    public function addCombination(ProductVariant $variant, object $body): ProductCustomizationImage
    {
        $ids = $body->optionIds ?? null;
        if (!is_array($ids) || $ids === [] || array_filter($ids, fn ($id) => !is_int($id)) || count(array_unique($ids)) !== count($ids)) {
            throw new LandingContentException(422, 'optionIds : liste d\'identifiants de valeurs d\'option, distincts, au moins un', [['path' => 'optionIds', 'message' => 'liste d\'entiers distincts attendue']]);
        }
        $em = $this->emProvider->getEntityManager();
        $optionValues = [];
        foreach ($ids as $i => $id) {
            $optionValues[] = $em->getRepository(ProductOptionValue::class)->find($id)
                ?? throw new LandingContentException(422, "optionIds[$i] : valeur d'option inconnue sur ce site", [['path' => "optionIds[$i]", 'message' => 'valeur d\'option inconnue']]);
        }
        $fields = (object) array_intersect_key(get_object_vars($body), array_flip(['image', 'stock']));
        $fields->stock ??= 0;
        $values = $this->validated('customization-combinations', $fields, ['image']);

        $combination = (new ProductCustomizationImage())->setImagePath((string) $values['image'])->setNumberOfPieces((int) $values['stock']);
        foreach ($optionValues as $optionValue) {
            $combination->addOptionValue($optionValue);
        }
        $variant->addProductCustomizationImage($combination);
        $violations = $this->validator->validate($combination);
        if (count($violations) > 0) {
            $variant->removeProductCustomizationImage($combination);
            throw new LandingContentException(422, (string) $violations[0]->getMessage(), [['path' => 'optionIds', 'message' => (string) $violations[0]->getMessage()]]);
        }
        $em->persist($combination);
        $em->flush();

        return $combination;
    }

    public function deleteCombination(ProductCustomizationImage $combination): ProductVariant
    {
        $variant = $combination->getProductVariant();
        $variant?->removeProductCustomizationImage($combination);
        $em = $this->emProvider->getEntityManager();
        $em->remove($combination);
        $em->flush();

        return $variant;
    }

    /** { title*, icon } : ajouté en dernière position */
    public function createFeature(object $body, string $locale): Feature
    {
        $values = $this->validated('features', $body, ['title']);
        $em = $this->emProvider->getEntityManager();
        $features = $em->getRepository(Feature::class)->findAll();
        if (count($features) >= self::MAX_FEATURES) {
            throw new LandingContentException(422, sprintf('%d atouts au plus.', self::MAX_FEATURES), [['path' => '', 'message' => 'liste complète']]);
        }
        $position = $features === [] ? 0 : max(array_map(fn (Feature $f) => $f->getPosition(), $features)) + 1;
        $feature = (new Feature())->setTitle((string) $values['title'])->setPosition($position);
        $em->persist($feature);
        $em->flush();
        $this->editor->apply('features', $feature, $values, $locale);

        return $feature;
    }

    public function deleteFeature(Feature $feature): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->remove($feature);
        $em->flush();
    }

    /** @return list<int> ordre actuel des atouts */
    public function featureOrder(): array
    {
        return array_map(fn (Feature $f) => (int) $f->getId(), $this->emProvider->getEntityManager()->getRepository(Feature::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']));
    }

    /** { order: [identifiants] } : chaque atout du site, une fois */
    public function reorderFeatures(mixed $body): void
    {
        $ids = is_object($body) ? ($body->order ?? null) : null;
        if (!is_array($ids) || array_filter($ids, fn ($id) => !is_int($id))) {
            throw new LandingContentException(400, 'Objet JSON attendu : { "order": [identifiants des atouts] }.');
        }
        $current = $this->featureOrder();
        $sorted = $ids;
        sort($sorted);
        $expected = $current;
        sort($expected);
        if ($sorted !== $expected) {
            throw new LandingContentException(422, 'order : chaque atout, une seule fois', [['path' => 'order', 'message' => 'identifiants attendus : ' . implode(', ', $current)]]);
        }
        $em = $this->emProvider->getEntityManager();
        foreach ($ids as $position => $id) {
            $em->getRepository(Feature::class)->find($id)?->setPosition($position);
        }
        $em->flush();
    }

    /**
     * @param list<string> $required champs obligatoires à la création
     * @return array<string, mixed>
     */
    private function validated(string $resource, object $body, array $required = []): array
    {
        foreach ($required as $field) {
            if (!isset($body->{$field}) || $body->{$field} === '') {
                throw new LandingContentException(422, "Champ obligatoire manquant : $field", [['path' => $field, 'message' => 'champ obligatoire']]);
            }
        }
        ['values' => $values, 'errors' => $errors] = $this->editor->validate($resource, $body);
        if ($errors) {
            throw new LandingContentException(422, 'Champs refusés : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }

        return $values;
    }

    /** Nom d'une couleur ou d'une taille : une ligne, sans balise, 255 caractères au plus */
    private function name(object $body): string
    {
        $name = is_string($body->name ?? null) ? trim($body->name) : '';
        if ($name === '' || $name !== strip_tags($name) || mb_strlen($name) > 255) {
            throw new LandingContentException(422, 'name : texte sans balise de 1 à 255 caractères', [['path' => 'name', 'message' => 'champ obligatoire, une ligne sans balise, 255 caractères au plus']]);
        }

        return $name;
    }

    private static function code(string $name): string
    {
        return mb_substr(strtolower((string) (new AsciiSlugger('fr'))->slug($name, '_')), 0, 60) . '_' . bin2hex(random_bytes(2));
    }
}
