<?php

namespace App\UseCase\LandingContentUseCase;

use App\Dto\ColorOutputDTO;
use App\Dto\SizeDTO;
use App\Entity\Feature;
use App\Entity\Product;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingContentService\BoutiqueCatalogEditor;
use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingContentService\LandingContentOutput;
use App\Services\LandingContentService\LandingContentSpec;
use App\Services\TenantCacheService;

/**
 * Créations, suppressions et ordre des données de la boutique depuis l'éditeur de la page (09/10/2026). Chaque
 * écriture est journalisée (add, delete, reorder ; l'ordre des atouts se rétablit), les caches de lecture vidés, et la
 * réponse est l'objet tel que sa lecture publique le renvoie (configuration de la variante pour la personnalisation).
 */
final class ManageBoutiqueCatalogUseCase
{
    private const LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly BoutiqueCatalogEditor $catalog,
        private readonly LandingContentEditor $editor,
        private readonly LandingContentOutput $outputs,
        private readonly ContentAuditRecorder $audit,
        private readonly TenantCacheService $cache
    ) {
    }

    public function createVariant(int $productId, mixed $body, string $locale): ?array
    {
        $variant = $this->catalog->createVariant($this->catalog->find(Product::class, $productId, 'Produit'), self::object($body));
        $this->recordAdd('product-variants', $variant, ['stockQuantity', 'price', 'colorId', 'sizeId'], $locale);

        return $this->outputs->variant($variant, self::locale($locale));
    }

    public function deleteVariant(int $id, string $locale): void
    {
        $variant = $this->catalog->find(ProductVariant::class, $id, 'Variante');
        $before = $this->editor->snapshot('product-variants', $variant, ['stockQuantity', 'price', 'colorId', 'sizeId'], self::locale($locale)) + ['productId' => $variant->getProduct()?->getId()];
        $this->catalog->deleteVariant($variant);
        $this->audit->record('product-variants', $id, 'delete', $before, null, [], $locale);
        $this->invalidate('product-variants');
    }

    public function createColor(mixed $body, string $locale): ColorOutputDTO
    {
        $color = $this->catalog->createColor(self::object($body), self::locale($locale));
        $this->audit->record('colors', $color->getId(), 'add', null, ['name' => $color->getName(), 'codeHexa' => $color->getCodeHexa()], ['name'], $locale);
        $this->forget('colors_all_');

        return new ColorOutputDTO($color, $locale);
    }

    public function createSize(mixed $body, string $locale): SizeDTO
    {
        $size = $this->catalog->createSize(self::object($body), self::locale($locale));
        $this->audit->record('sizes', $size->getId(), 'add', null, ['name' => $size->getName()], ['name'], $locale);
        $this->forget('sizes_all_');

        return SizeDTO::fromEntity($size, $locale);
    }

    public function addValue(int $optionId, mixed $body, string $locale, string $host, ?int $variantId): mixed
    {
        $value = $this->catalog->addValue($this->catalog->find(ProductOption::class, $optionId, 'Option'), self::object($body), self::locale($locale));
        $this->recordAdd('customization-values', $value, ['name', 'priceDelta', 'icon'], $locale);

        return $this->outputs->output('customization-values', $value, $locale, $host, $variantId);
    }

    public function deleteValue(int $id, string $locale, string $host, ?int $variantId): mixed
    {
        $value = $this->catalog->find(ProductOptionValue::class, $id, 'Valeur d\'option');
        $before = $this->editor->snapshot('customization-values', $value, ['name', 'priceDelta', 'icon'], self::locale($locale)) + ['optionId' => $value->getProductOption()?->getId()];
        $this->catalog->deleteValue($value);
        $this->audit->record('customization-values', $id, 'delete', $before, null, [], $locale);
        $this->invalidate('customization-values');

        return $this->outputs->config($variantId);
    }

    public function addCombination(int $variantId, mixed $body, string $locale): object
    {
        $variant = $this->catalog->find(ProductVariant::class, $variantId, 'Variante');
        $combination = $this->catalog->addCombination($variant, self::object($body));
        $this->recordAdd('customization-combinations', $combination, ['image', 'stock'], $locale, ['variantId' => $variantId,
            'optionIds' => array_map(fn ($v) => $v->getId(), $combination->getOptionValues()->toArray())]);

        return $this->outputs->config($variantId);
    }

    public function deleteCombination(int $id, string $locale): ?object
    {
        $combination = $this->catalog->find(ProductCustomizationImage::class, $id, 'Combinaison');
        $before = $this->editor->snapshot('customization-combinations', $combination, ['image', 'stock'], self::locale($locale))
            + ['variantId' => $combination->getProductVariant()?->getId(), 'optionIds' => array_map(fn ($v) => $v->getId(), $combination->getOptionValues()->toArray())];
        $variant = $this->catalog->deleteCombination($combination);
        $this->audit->record('customization-combinations', $id, 'delete', $before, null, [], $locale);
        $this->invalidate('customization-combinations');

        return $this->outputs->config($variant?->getId());
    }

    public function createFeature(mixed $body, string $locale, string $host): mixed
    {
        $feature = $this->catalog->createFeature(self::object($body), self::locale($locale));
        $this->recordAdd('features', $feature, ['title', 'icon'], $locale);
        $this->forget('features_all_');

        return $this->outputs->output('features', $feature, $locale, $host);
    }

    public function deleteFeature(int $id, string $locale): void
    {
        $feature = $this->catalog->find(Feature::class, $id, 'Atout');
        $before = $this->editor->snapshot('features', $feature, ['title', 'icon'], self::locale($locale));
        $this->catalog->deleteFeature($feature);
        $this->audit->record('features', $id, 'delete', $before, null, [], $locale);
        $this->invalidate('features');
        $this->forget('features_all_');
    }

    /** @return list<mixed> les atouts dans leur nouvel ordre, comme GET /api/features */
    public function reorderFeatures(mixed $body, string $locale, string $host): array
    {
        $before = $this->catalog->featureOrder();
        $this->catalog->reorderFeatures($body);
        $this->audit->record('features', null, 'reorder', ['order' => $before], ['order' => $this->catalog->featureOrder()], ['order'], $locale);
        $this->invalidate('features');
        $this->forget('features_all_');

        return array_map(fn (int $id) => $this->outputs->output('features', $this->catalog->find(Feature::class, $id, 'Atout'), $locale, $host), $this->catalog->featureOrder());
    }

    /** @param list<string> $fields @param array<string, mixed> $extra */
    private function recordAdd(string $resource, object $entity, array $fields, string $locale, array $extra = []): void
    {
        $this->audit->record($resource, $entity->getId(), 'add', null, $this->editor->snapshot($resource, $entity, $fields, self::locale($locale)) + $extra, $fields, $locale);
        $this->invalidate($resource);
    }

    private function invalidate(string $resource): void
    {
        $this->cache->invalidateTags(LandingContentSpec::RESOURCES[$resource]['tags']);
    }

    /** Listes mises en cache par clé (sans étiquette du site) : supprimées une à une */
    private function forget(string $prefix): void
    {
        foreach (self::LOCALES as $locale) {
            $this->cache->delete($prefix . $locale);
        }
    }

    private static function object(mixed $body): object
    {
        return is_object($body) ? $body : throw new LandingContentException(400, 'Objet JSON attendu.');
    }

    private static function locale(string $locale): string
    {
        return preg_match('/^[a-z]{2}$/', $locale) ? $locale : throw new LandingContentException(400, 'Langue invalide (?locale=fr, en…).');
    }
}
