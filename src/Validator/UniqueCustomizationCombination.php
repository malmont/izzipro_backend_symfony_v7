<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** Une variante n'a qu'une combinaison par ensemble de valeurs d'options (09/10/2026) */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueCustomizationCombination extends Constraint
{
    public string $message = 'Cette variante a déjà une combinaison pour ces options (combinaison n° {{ id }}) : modifiez-la plutôt que d\'en créer une seconde.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
