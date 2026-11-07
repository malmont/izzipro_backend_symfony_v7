<?php

namespace App\Services\ShippingService;

/** Le tarif demandé (compte transporteur + service) n'est plus proposé par EasyPost pour cet envoi (lot 2 Livraison, 07/11/2026) : on ne substitue jamais un autre service */
class RateUnavailableException extends \RuntimeException
{
    /** @param list<string> $available services proposés (« Service (ca_…) ») */
    public function __construct(string $service, string $carrierAccountId, public readonly array $available = [])
    {
        parent::__construct(sprintf('Tarif introuvable pour %s (%s) ; proposés : %s', $service, $carrierAccountId, $available !== [] ? implode(', ', $available) : 'aucun'));
    }
}
