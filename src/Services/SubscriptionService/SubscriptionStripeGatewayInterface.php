<?php

namespace App\Services\SubscriptionService;

use App\Entity\Adress;
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

    /**
     * @param array<string, string> $metadata
     * @param list<string> $taxRateIds
     */
    public function create(string $customerId, string $priceId, int $quantity, int $trialDays, array $metadata, array $taxRateIds, bool $automaticTax): array;

    public function retrieve(string $subscriptionId): array;

    /** Annulation à la fin de la période (true) ou immédiate (false) */
    public function cancel(string $subscriptionId, bool $atPeriodEnd): array;

    /** Reprise : annulation programmée levée et collecte reprise */
    public function resume(string $subscriptionId): array;

    public function pause(string $subscriptionId): array;

    public function changePlan(string $subscriptionId, string $itemId, string $priceId, int $quantity): array;

    public function portalUrl(string $customerId, string $returnUrl): string;
}
