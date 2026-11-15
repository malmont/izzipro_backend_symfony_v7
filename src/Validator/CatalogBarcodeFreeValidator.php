<?php

namespace App\Validator;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\BarcodeService\CatalogBarcodeException;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Ne juge qu'un code qui change (ou une fiche nouvelle). Passe le code de la fiche par `CatalogBarcodeService::check` (forme, formes réservées, code libre en base), puis le compare aux
 * autres fiches du MÊME formulaire, que la base ne connaît pas encore : l'article et ses variantes en mémoire (deux variantes
 * nouvelles qui portent le même code, une variante qui prend le code qu'on vient de saisir sur son article).
 */
final class CatalogBarcodeFreeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CatalogBarcodeService $barcodes,
        private readonly TenantEntityManagerProvider $emProvider,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CatalogBarcodeFree || (!$value instanceof Product && !$value instanceof ProductVariant)) {
            return;
        }
        $code = $value->getBarcode();
        if ($code === null) {
            return;
        }
        // Code inchangé depuis la lecture de la fiche : rien à redire (un code ancien, d'une forme refusée aujourd'hui, ne bloque pas la
        // modification du reste de la fiche ; la base, elle, tient toujours l'unicité)
        $original = $this->emProvider->getEntityManager()->getUnitOfWork()->getOriginalEntityData($value);
        if ($value->getId() !== null && ($original['barcode'] ?? null) === $code) {
            return;
        }
        try {
            $this->barcodes->check($code, $value);
        } catch (CatalogBarcodeException $e) {
            $this->context->buildViolation($e->getMessage())->setCode($e->errorCode)->atPath('barcode')->addViolation();

            return;
        }
        $product = $value instanceof Product ? $value : $value->getProduct();
        if ($product === null) {
            return;
        }
        if ($value instanceof ProductVariant && $product->getBarcode() === $code) {
            $this->context->buildViolation('Ce code-barres est déjà celui de l\'article : une variante porte son propre code.')
                ->setCode(CatalogBarcodeService::ALREADY_USED)->atPath('barcode')->addViolation();

            return;
        }
        foreach ($product->getVariants() as $variant) {
            if ($variant !== $value && $variant->getBarcode() === $code) {
                $this->context->buildViolation($value instanceof Product
                    ? 'Ce code-barres est déjà celui d\'une variante de cet article : l\'article et ses variantes portent des codes différents.'
                    : 'Ce code-barres est déjà celui d\'une autre variante de cet article.')
                    ->setCode(CatalogBarcodeService::ALREADY_USED)->atPath('barcode')->addViolation();

                return;
            }
        }
    }
}
