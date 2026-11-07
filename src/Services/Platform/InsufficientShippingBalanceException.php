<?php

namespace App\Services\Platform;

/** Le solde prépayé d'expédition du site ne couvre pas l'étiquette (lot 2 Livraison, 07/11/2026) */
class InsufficientShippingBalanceException extends \RuntimeException
{
    public function __construct(public readonly int $balanceCents, public readonly int $neededCents, public readonly string $currency)
    {
        parent::__construct(sprintf('Solde d\'expédition insuffisant : %s %s disponibles, %s %s nécessaires', number_format($balanceCents / 100, 2, ',', ' '), $currency, number_format($neededCents / 100, 2, ',', ' '), $currency));
    }
}
