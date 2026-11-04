<?php

namespace App\Services\EntrepriseService;

/**
 * Fiche entreprise : champs réservés au super administrateur envoyés par un administrateur de site avec une valeur différente
 * de la valeur actuelle (lot « console de pilotage » 1, 04/11/2026). Rien n'est écrit. Code stable : SUPER_ADMIN_REQUIRED.
 */
final class EntrepriseForbiddenException extends \RuntimeException
{
    public const CODE = 'SUPER_ADMIN_REQUIRED';

    /** @param list<string> $fields */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Modification réservée au super administrateur : ' . implode(', ', $fields));
    }
}
