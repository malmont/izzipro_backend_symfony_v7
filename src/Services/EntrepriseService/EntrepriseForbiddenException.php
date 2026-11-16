<?php

namespace App\Services\EntrepriseService;

/**
 * Fiche entreprise : champs réservés au super administrateur envoyés par un administrateur de site avec une valeur différente
 * de la valeur actuelle (lot « console de pilotage » 1, 04/11/2026). Rien n'est écrit. Code stable : SUPER_ADMIN_REQUIRED.
 */
final class EntrepriseForbiddenException extends \RuntimeException
{
    public const CODE = 'SUPER_ADMIN_REQUIRED';
    /** Lot MOD-1 : un module ne s'allume ni ne s'éteint par la fiche, pour personne : le droit se gère dans la console de pilotage, le
     *  commerçant allume ou éteint un module détenu par POST /api/site/features/{clé}/enable|disable */
    public const MODULE_VIA_PLATFORM = 'MODULE_VIA_PLATFORM';

    /** @param list<string> $fields */
    public function __construct(public readonly array $fields, public readonly string $errorCode = self::CODE)
    {
        parent::__construct(($errorCode === self::MODULE_VIA_PLATFORM ? 'Un module ne se règle pas sur la fiche : droit dans la console de pilotage, allumage par le commerçant dans « Modules ». ' : 'Modification réservée au super administrateur : ') . implode(', ', $fields));
    }
}
