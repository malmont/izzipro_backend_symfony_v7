<?php

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Lot PROVISION-3 (18/11/2026) : une facture d'abonnement payée, émise UNE SEULE FOIS par facture après son traitement par le webhook
 * (registre `subscription_period_paid` de la base maître). Le lot ABO-1 y fera naître les droits de la période (`docs/plan-abo-universel.md`).
 * Ne porte que des identifiants, des dates (horodatages UTC) et un montant en centimes, jamais le client. Émis aussi pour une facture à 0
 * (ouverture d'un essai), une facture de prorata ou d'un abonnement résilié : `billingReason` et l'état de l'abonnement permettent de trier.
 * Au plus une fois : une période notée mais non traitée (`dispatched_at` vide, échec d'un écouteur) est à réémettre par ABO-1.
 */
final class SubscriptionPeriodPaidEvent extends Event
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $tenantCode,
        public readonly string $invoiceId,
        public readonly ?int $periodStart,
        public readonly ?int $periodEnd,
        public readonly int $amountPaid,
        public readonly ?string $billingReason = null, // subscription_create, subscription_cycle, subscription_update (prorata)… : l'écouteur choisit
    ) {
    }
}
