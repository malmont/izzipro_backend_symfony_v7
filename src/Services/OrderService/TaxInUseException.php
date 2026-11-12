<?php

namespace App\Services\OrderService;

/** Taxe déjà utilisée par une commande : taux, libellé, code, catégorie, pays, région et début de validité figés ; suppression refusée (PAYS-1) */
final class TaxInUseException extends \RuntimeException
{
    public const CODE = 'TAX_IN_USE';

    /** @param list<string> $fields */
    public function __construct(public readonly int $taxId, public readonly array $fields, public readonly bool $deletion = false)
    {
        parent::__construct($deletion
            ? sprintf('TAX_IN_USE : la taxe n° %d est utilisée par des commandes ; elle ne peut pas être supprimée (archivez-la).', $taxId)
            : sprintf('TAX_IN_USE : la taxe n° %d est utilisée par des commandes ; %s ne peut plus changer (créez une nouvelle taxe datée et bornez celle-ci, ou archivez-la).', $taxId, implode(', ', $fields)));
    }
}
