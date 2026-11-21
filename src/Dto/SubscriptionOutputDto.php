<?php

namespace App\Dto;

use App\Entity\Order;
use App\Entity\Subscription;
use App\Services\CaisseService\StoreClock;

/**
 * Abonnement d'un client (GET /api/subscriptions, …/{id}) : statut, période, adresse, transporteur, livraison par échéance
 * (cents), commandes des échéances ; latestInvoice : échéance impayée si le statut est past_due (13/10/2026), sinon null
 */
final class SubscriptionOutputDto
{
    /**
     * @param list<array{id: int, reference: string, date: string, total: int, status: ?string}> $orders
     * @param array{amountDue: int, currency: string, hostedInvoiceUrl: ?string, nextPaymentAttempt: ?string, attemptCount: int, maxAttempts: int}|null $latestInvoice
     */
    public function __construct(
        public readonly int $id,
        public readonly SubscriptionPlanOutputDto $plan,
        public readonly int $quantity,
        public readonly string $status,
        public readonly ?string $currentPeriodEnd,
        public readonly bool $cancelAtPeriodEnd,
        public readonly ?AdressOutputDTO $address,
        public readonly ?array $carrier,
        public readonly int $shippingAmount,
        public readonly string $createdAt,
        public readonly ?string $canceledAt,
        public readonly array $orders,
        public readonly ?array $latestInvoice = null,
        /** Carte enregistrée chez Stripe pour l'abonnement (25/10/2026) ; null : inconnu, abonnement pas encore relu */
        public readonly ?bool $hasPaymentMethod = null,
        /** @var list<array<string, mixed>> lot ABO-1 : solde par prestation (nombres et échéances seulement, jamais de motif ni de note de l'équipe) */
        public readonly array $balance = [],
        /** @var array{allowed: bool, cancellableFrom: ?string, scheduledAt: ?string}|null lot ABO-1 : résiliation simple (fin d'engagement, date programmée) */
        public readonly ?array $cancellation = null,
        /** @var array{price: int, currency: string, interval: string, intervalCount: int}|null lot ABO-CONF : le prix que paie l'abonné (son époque), `plan.price` étant celui du jour */
        public readonly ?array $billedPrice = null,
        /** @var list<array<string, mixed>> lot ABO-2 : les 10 prochaines dates (prestation, début, fin, lieu, annulable jusqu'à), jamais l'intervenant */
        public readonly array $upcoming = [],
        /** @var list<array<string, mixed>> lot ABO-3a : les prestations faites ou manquées (90 jours) : note, photo, avis et jusqu'à quand le donner */
        public readonly array $delivered = [],
        /** Lot ABO-3a : l'abonné reçoit le courriel « prestation faite » (il le coupe par PATCH …/courriels) */
        public readonly bool $doneEmails = true,
        /** Lot ABO-3b : messages de la boutique pas encore lus dans le fil (`GET /api/subscriptions/{id}/fil` les marque lus) */
        public readonly int $unreadMessages = 0,
    ) {
    }

    /** @param array{price: int, currency: string, interval: string, intervalCount: int} $billed */
    public function withBilledPrice(array $billed): self
    {
        $args = get_object_vars($this);
        $args['billedPrice'] = $billed;

        return new self(...$args);
    }

    /** @param array{allowed: bool, cancellableFrom: ?string, scheduledAt: ?string} $cancellation */
    public function withCancellation(array $cancellation): self
    {
        $args = get_object_vars($this);
        $args['cancellation'] = $cancellation;

        return new self(...$args);
    }

    /** @param list<array<string, mixed>> $balance */
    public function withBalance(array $balance): self
    {
        $args = get_object_vars($this);
        $args['balance'] = array_map(fn (array $b) => array_intersect_key($b, array_flip(['benefitId', 'name', 'kind', 'available', 'scheduled', 'used', 'carried', 'unlimited', 'nextExpiry'])), $balance);

        return new self(...$args);
    }

    /** @param list<array<string, mixed>> $upcoming lot ABO-2 : la vue du client (sans intervenant) */
    public function withUpcoming(array $upcoming): self
    {
        $args = get_object_vars($this);
        $args['upcoming'] = array_map(fn (array $u) => array_intersect_key($u, array_flip(['occurrenceId', 'benefitId', 'name', 'startsAt', 'endsAt', 'location', 'slotId', 'cancellableUntil'])), $upcoming);

        return new self(...$args);
    }

    /** @param list<array<string, mixed>> $delivered lot ABO-3a : la vue du client (jamais « par qui » ni la décision interne) */
    public function withDelivered(array $delivered, bool $doneEmails): self
    {
        $args = get_object_vars($this);
        $args['delivered'] = array_map(fn (array $d) => array_intersect_key($d, array_flip(['occurrenceId', 'benefitId', 'name', 'status', 'startsAt', 'doneAt', 'note', 'hasProof', 'customerAck', 'ackUntil'])), $delivered);
        $args['doneEmails'] = $doneEmails;

        return new self(...$args);
    }

    public function withUnreadMessages(int $unread): self
    {
        $args = get_object_vars($this);
        $args['unreadMessages'] = max(0, $unread);

        return new self(...$args);
    }

    /**
     * @param list<Order> $orders
     * @param \DateTimeZone|null $timezone lot ABO-DATES (20/11/2026) : toutes les dates à l'heure du magasin, avec le décalage (UTC sans fuseau)
     */
    public static function fromEntity(Subscription $subscription, array $orders, string $locale, ?\DateTimeZone $timezone = null): self
    {
        $carrier = $subscription->getCarrier();
        $tz = $timezone ?? new \DateTimeZone('UTC');
        $issue = $subscription->getStatus() === Subscription::STATUS_PAST_DUE ? $subscription->getPaymentIssue() : null;
        if (is_array($issue) && array_key_exists('nextPaymentAttempt', $issue)) {
            $issue['nextPaymentAttempt'] = StoreClock::atomIn($tz, $issue['nextPaymentAttempt']);
        }

        return new self(
            (int) $subscription->getId(),
            SubscriptionPlanOutputDto::fromEntity($subscription->getPlan(), $locale),
            $subscription->getQuantity(),
            $subscription->getStatus(),
            StoreClock::atomIn($tz, $subscription->getCurrentPeriodEnd()),
            $subscription->isCancelAtPeriodEnd(),
            $subscription->getAddress() ? new AdressOutputDTO($subscription->getAddress()) : null,
            $carrier ? ['id' => (int) $carrier->getId(), 'name' => $carrier->getTranslation($locale)?->getName() ?? $carrier->getName(), 'kind' => $carrier->getKind(), 'isPickup' => $carrier->isPickup()] : null, // kind, isPickup : B20 (08/11/2026)
            $subscription->getShippingAmount(),
            (string) StoreClock::atomIn($tz, $subscription->getCreatedAt()),
            StoreClock::atomIn($tz, $subscription->getCanceledAt()),
            array_map(fn (Order $o) => [
                'id' => (int) $o->getId(), 'reference' => (string) $o->getReference(), 'date' => (string) StoreClock::atomIn($tz, $o->getOrderDate()),
                'total' => (int) round((float) $o->getTotalAmount()), 'status' => $o->getStatus()?->getTranslation($locale)?->getName() ?? $o->getStatus()?->getName(),
            ], $orders),
            $issue,
            $subscription->hasPaymentMethod()
        );
    }
}
