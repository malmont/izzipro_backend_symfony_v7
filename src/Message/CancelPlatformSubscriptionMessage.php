<?php

namespace App\Message;

/**
 * Lot MOD-1 (16/11/2026) : résilier, à la fin de la période, l'abonnement de la boutique de la plateforme qui paie un droit d'usage
 * retiré par le super administrateur (« Retirer et résilier »), pour que le module ne se rallume pas au renouvellement. File `async` :
 * le worker se place sur le site vendeur de l'abonnement (`subscription:<site>:<numéro>`), jamais la requête de la console.
 */
final class CancelPlatformSubscriptionMessage
{
    public function __construct(public readonly string $reference, public readonly ?string $by = null)
    {
    }
}
