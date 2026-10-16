<?php

namespace App\MemoiresVivantes\Exception;

/** Type de livre demandé inconnu ou désactivé (route mobile : 422 TYPE_INVALID, là où le web crée un livre individuel) */
class InvalidBookTypeException extends \InvalidArgumentException
{
    public function __construct(public readonly string $requestedType)
    {
        // Message destiné aux utilisateurs (l'application l'affiche tel quel) ; le code demandé est rendu à part (requestedType)
        parent::__construct('Ce type de livre n\'est plus proposé. Choisissez-en un autre.');
    }
}
