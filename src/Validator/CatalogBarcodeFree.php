<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Le code-barres d'un article ou d'une variante est de forme permise, non réservé à la caisse et ne désigne rien d'autre sur le site
 * (lot CB-1, 15/11/2026, `docs/codes-barres.md`). Posée sur les deux entités : tout formulaire et toute écriture validée (fiches
 * d'EasyAdmin) rend le refus sous le champ, avant que la base ne le refuse elle-même.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CatalogBarcodeFree extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
