<?php

namespace App\Services\SubscriptionService;

/** Lot PROVISION-3 : l'abonnement est tenu par un autre traitement au-delà du délai (le webhook répond 409, Stripe livre de nouveau) */
final class SubscriptionLockTimeout extends \RuntimeException
{
}
