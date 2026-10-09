<?php

namespace App\Services\LandingContentService;

use App\Entity\ProductVariant;
use App\Entity\SubscriptionPlan;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\LandingContentUseCase\LandingContentException;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;

/**
 * Règles propres à certaines ressources éditées depuis la page (09/10/2026), appliquées par LandingContentEditor
 * avant et après l'écriture, y compris lors d'un retour en arrière du journal :
 * - variante : une seule par couple couleur + taille dans le produit (422) ; un nouveau stock passe par un mouvement
 *   d'inventaire (historique, comme l'administration), au lieu d'une écriture directe ;
 * - formule d'abonnement : une seule formule recommandée par produit.
 */
final class LandingContentHooks
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UpdateStockAndInventoryUseCase $stock
    ) {
    }

    /** @param array<string, mixed> $values valeurs à écrire (un champ traité ici en est retiré) */
    public function before(string $resource, object $entity, array &$values, string $locale): void
    {
        if ($resource !== 'product-variants' || !$entity instanceof ProductVariant) {
            return;
        }
        $colorId = array_key_exists('colorId', $values) ? $values['colorId'] : $entity->getColor()?->getId();
        $sizeId = array_key_exists('sizeId', $values) ? $values['sizeId'] : $entity->getSize()?->getId();
        if (array_key_exists('colorId', $values) || array_key_exists('sizeId', $values)) {
            self::assertUniquePair($entity->getProduct()?->getVariants() ?? [], $entity, $colorId, $sizeId);
        }
        if (array_key_exists('stockQuantity', $values)) {
            $delta = (int) $values['stockQuantity'] - (int) $entity->getStockQuantity();
            if ($delta !== 0) {
                $this->stock->execute($entity, abs($delta), $delta > 0);
            }
            unset($values['stockQuantity']);
        }
    }

    /** @param array<string, mixed> $values */
    public function after(string $resource, object $entity, array $values): void
    {
        if ($resource === 'subscription-plans' && $entity instanceof SubscriptionPlan && ($values['highlighted'] ?? false) === true) {
            $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->keepOnlyHighlighted($entity);
        }
    }

    /**
     * @param iterable<ProductVariant> $variants variantes du produit
     * @throws LandingContentException 422 si une autre variante a déjà ce couple couleur + taille
     */
    public static function assertUniquePair(iterable $variants, ?ProductVariant $self, ?int $colorId, ?int $sizeId): void
    {
        foreach ($variants as $other) {
            if ($other !== $self && $other->getColor()?->getId() === $colorId && $other->getSize()?->getId() === $sizeId) {
                throw new LandingContentException(422, 'Ce produit a déjà une variante avec cette couleur et cette taille (variante n° ' . $other->getId() . ').',
                    [['path' => $colorId !== null ? 'colorId' : 'sizeId', 'message' => 'couple couleur + taille déjà utilisé par la variante n° ' . $other->getId()]]);
            }
        }
    }
}
