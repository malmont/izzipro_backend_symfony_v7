<?php

namespace App\Services\LandingSiteModelService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une opération sur un modèle de site : statut, message et erreurs par champ (path, message) */
final class LandingSiteModelException extends HttpException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($status, $message);
    }
}
