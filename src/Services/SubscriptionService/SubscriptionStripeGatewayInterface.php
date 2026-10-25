<?php

namespace App\Services\SubscriptionService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\SubscriptionPlan;
use App\Entity\User;

/**
 * Appels Stripe Billing d'un site (compte connecté), derrière une interface pour les tests (FakeSubscriptionStripeGateway).
 * Un abonnement Stripe est rendu sous forme de tableau normalisé :
 * { id, status, currentPeriodEnd: ?int (horodatage), cancelAtPeriodEnd: bool, paused: bool, clientSecret: ?string,
 *   intentType: 'payment' | 'setup' | null (nature du clientSecret : paiement de la première facture, ou enregistrement
 *   de la carte quand il n'y a rien à payer, période d'essai), hasPaymentMethod: ?bool (carte par défaut de
 *   l'abonnement chez Stripe ; null : inconnu), itemId: ?string }.
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

    /**
     * Rembourse en entier le dernier paiement encaissé de l'abonnement (dernière facture payée).
     *
     * @return array{refundId: string, amount: int, currency: string}|null null : aucune facture payée à rembourser
     */
    public function refundLatestPayment(string $subscriptionId): ?array;

    /**
     * Carte par défaut de l'abonnement (25/10/2026) : si l'abonnement n'en a pas, pose celle qui vient de payer la
     * facture, pour que les échéances suivantes soient débitées seules (cas d'un essai gratuit dont la carte n'a pas
     * été enregistrée, payé ensuite depuis le courriel d'impayé).
     *
     * @return string|null identifiant du moyen de paiement posé ; null si l'abonnement en avait déjà un ou si la facture
     *                     n'en donne pas
     */
    public function ensureDefaultPaymentMethod(string $subscriptionId, string $invoiceId): ?string;
}
