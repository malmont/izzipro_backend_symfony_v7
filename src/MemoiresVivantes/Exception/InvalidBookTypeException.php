<?php

namespace App\MemoiresVivantes\Exception;

/** Type de livre demandé inconnu ou désactivé (route mobile : 422 TYPE_INVALID, là où le web crée un livre individuel) */
class InvalidBookTypeException extends \InvalidArgumentException
{
    public function __construct(public readonly string $requestedType)
    {
        parent::__construct(sprintf('Type de livre inconnu ou désactivé : « %s ». Choisissez un type de GET /api/memoires/book-types.', $requestedType));
    }
}
