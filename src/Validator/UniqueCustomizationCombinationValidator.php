<?php

namespace App\Validator;

use App\Entity\ProductCustomizationImage;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Compare la combinaison aux autres combinaisons de sa variante (collection en mémoire : combinaisons enregistrées et
 * nouvelles du même formulaire), par ensemble de valeurs d'options.
 */
final class UniqueCustomizationCombinationValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof ProductCustomizationImage || !$constraint instanceof UniqueCustomizationCombination || $value->getProductVariant() === null) {
            return;
        }
        $key = self::key($value);
        if ($key === '') {
            return;
        }
        foreach ($value->getProductVariant()->getProductCustomizationImages() as $other) {
            if ($other !== $value && self::key($other) === $key) {
                $this->context->buildViolation($constraint->message)->setParameter('{{ id }}', (string) ($other->getId() ?? 'nouvelle'))
                    ->atPath('optionValues')->addViolation();

                return;
            }
        }
    }

    private static function key(ProductCustomizationImage $combination): string
    {
        $ids = array_map(fn ($v) => (string) ($v->getId() ?? spl_object_id($v)), $combination->getOptionValues()->toArray());
        sort($ids);

        return implode(',', $ids);
    }
}
