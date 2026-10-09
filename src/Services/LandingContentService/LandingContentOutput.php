<?php

namespace App\Services\LandingContentService;

use App\Dto\FeatureDTO;
use App\Dto\ProductCommerceDto;
use App\Dto\SubscriptionPlanOutputDto;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\MediaUrlResolver;
use App\Services\ProductVariantService\CustomizationFactoryService;
use App\Services\ProductVariantService\CustomizationMediaResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Réponse d'une écriture de l'éditeur : l'objet tel que sa lecture publique le renvoie dans cette langue. Pour la
 * personnalisation (09/10/2026) : la configuration à jour de la variante (GET /api/customization/config/{variantId}),
 * pour que la page se relise d'un coup ; une option ou une valeur partagée la renvoie pour ?variantId=, sinon
 * l'option ou la valeur seule.
 */
final class LandingContentOutput
{
    public function __construct(
        private readonly MediaUrlResolver $urls,
        private readonly TenantCurrencyProvider $currency,
        private readonly CustomizationFactoryService $configs,
        private readonly CustomizationMediaResolver $media,
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    public function output(string $resource, object $entity, string $locale, string $host, ?int $variantId = null): mixed
    {
        if (!in_array($resource, LandingContentSpec::SERVICE_OUTPUT, true)) {
            return LandingContentSpec::output($resource, $entity, $locale, $this->urls, $host, $this->currency->code());
        }

        return match ($resource) {
            'product-variants' => $this->variant($entity, $locale),
            'customization-combinations' => $this->configs->createConfigForVariant($entity->getProductVariant()),
            'customization-options' => $this->config($variantId) ?? $this->option($entity, $locale),
            'customization-values' => $this->config($variantId) ?? $this->value($entity, $locale, $host),
            'subscription-plans' => SubscriptionPlanOutputDto::fromEntity($entity, $locale),
            'features' => FeatureDTO::fromEntity($entity, $this->urls->getPublicHost($host), $locale),
        };
    }

    /** Variante telle que variants[] du produit la donne (prix en cents, couleur, taille, stock, options) */
    public function variant(ProductVariant $variant, string $locale): ?array
    {
        foreach (ProductCommerceDto::variants($variant->getProduct(), $locale) as $item) {
            if ($item['id'] === $variant->getId()) {
                return $item;
            }
        }

        return null;
    }

    public function config(?int $variantId): ?object
    {
        $variant = $variantId !== null ? $this->emProvider->getEntityManager()->getRepository(ProductVariant::class)->find($variantId) : null;

        return $variant instanceof ProductVariant ? $this->configs->createConfigForVariant($variant) : null;
    }

    private function option(ProductOption $option, string $locale): array
    {
        return ['id' => $option->getId(), 'code' => $option->getCode(), 'name' => $option->getTranslation($locale)?->getName() ?? $option->getName()];
    }

    private function value(ProductOptionValue $value, string $locale, string $host): array
    {
        return [
            'id' => $value->getId(), 'optionId' => $value->getProductOption()?->getId(),
            'name' => $value->getTranslation($locale)?->getValue() ?? $value->getValue(),
            'iconUrl' => $this->media->optionIconUrl($value->getImagePreview(), $host),
            'priceDelta' => (int) round(((float) $value->getPriceDelta()) * 100),
        ];
    }
}
