<?php

namespace App\MemoiresVivantes\UseCase\Quota;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Quotas IA : plafonds réservés au super administrateur envoyés par un administrateur de site avec une valeur différente de la
 * valeur actuelle (lot « console de pilotage » 1, 04/11/2026). Rien n'est écrit. Code stable : SUPER_ADMIN_REQUIRED. Hérite de
 * AccessDeniedException : le formulaire EasyAdmin répond 403 sans traitement particulier.
 */
final class QuotaCeilingForbiddenException extends AccessDeniedException
{
    public const CODE = 'SUPER_ADMIN_REQUIRED';

    /** @param list<string> $fields */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Modification des plafonds IA réservée au super administrateur : ' . implode(', ', $fields));
    }
}
