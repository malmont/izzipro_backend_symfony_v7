<?php

namespace App\Services\ShippingService;

/**
 * Lot CHECKOUT-LIVRAISON : EasyPost refuse la demande de tarifs (adresse ou colis invalides) : 422 SHIPPING_ADDRESS_INVALID, à
 * corriger par le client, et non « réessayez » (la panne reste ShippingRatesUnavailableException, 503).
 */
final class ShippingAddressRefusedException extends \RuntimeException
{
}
