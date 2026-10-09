<?php

namespace App\Services\SubscriptionService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\SubscriptionPlan;
use App\Entity\User;

/**
 * Appels Stripe Billing d'un site (compte connecté), derrière une interface pour les tests (FakeSubscriptionStripeGateway).
 * Un abonnement Stripe est rendu sous forme de tableau normalisé :
 * { id, status, currentPeriodEnd: ?int (horodatage), cancelAtPeriodEnd: bool, paused: bool, clientSecret: ?string, itemId: ?string }.
 */
interface SubscriptionStripeGatewayInterface
{
    /** Client Stripe du compte connecté pour cet utilisateur (créé au besoin, adresse posée) */
    public function ensureCustomer(User $user, ?Adress $address): string;

    /** Produit et prix Stripe de la formule (créés au besoin) ; renvoie [stripeProductId, stripePriceId] */
    public function ensurePrice(SubscriptionPlan $plan): array;

    /**
     * Identifiants des taux de taxe Stripe (créés au besoin, par libellé et pourcentage) pour les taxes de la table.
     *
     * @param list<array{label: string, rate: float}> $taxes
     * @return list<string>
     */
    public function taxRateIds(array $taxes): array;

    /** Prix Stripe récurrent de la livraison d'un transporteur à prix fixe (créé au besoin), au rythme de la formule */
    public function ensureShippingPrice(Carrier $carrier, int $amount, string $currency, string $interval, int $intervalCount): string;

    /**
     * @param array<string, string> $metadata
     * @param list<string> $taxRateIds
     * @param ?string $shippingPriceId seconde ligne récurrente (livraison), facturée à chaque échéance
     */
    public function create(string $customerId, string $priceId, int $quantity, int $trialDays, array $metadata, array $taxRateIds, bool $automaticTax, ?string $shippingPriceId = null): array;

    public function retrieve(string $subscriptionId): array;

    /** Annulation à la fin de la période (true) ou immédiate (false) */
    public function cancel(string $subscriptionId, bool $atPeriodEnd): array;

    /** Reprise : annulation programmée levée et collecte reprise */
    public function resume(string $subscriptionId): array;

    public function pause(string $subscriptionId): array;

    public function changePlan(string $subscriptionId, string $itemId, string $priceId, int $quantity): array;

    public function portalUrl(string $customerId, string $returnUrl): string;
}
