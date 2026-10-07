<?php

namespace App\Services\EntrepriseService;

/** Fiche entreprise refusée : erreurs par champ (path, message), rien n'est écrit */
final class EntrepriseValidationException extends \RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Fiche entreprise refusée : ' . $errors[0]['path'] . ' : ' . $errors[0]['message']);
    }
}
