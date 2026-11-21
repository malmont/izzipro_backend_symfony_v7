<?php

namespace App\Services\ShippingService;

/**
 * Lot CHECKOUT-LIVRAISON : EasyPost ne rend pas de tarifs (panne, refus de l'API). Le résumé des tarifs répond 503
 * SHIPPING_RATES_UNAVAILABLE, jamais un prix à 0 ; toute autre erreur reste une erreur du serveur.
 */
final class ShippingRatesUnavailableException extends \RuntimeException
{
}
