<?php

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Un abonnement de la boutique a changé de statut ou de période (console de pilotage, lot 3 bis, 04/11/2026), émis après le flush
 * par SubscriptionLifecycleSubscriber pour tout abonnement, branché ou non : les écouteurs décident (PlatformOfferFulfillmentSubscriber
 * ne traite que `platform_offer`). Ne porte que des identifiants et des états, jamais le client.
 */
final class SubscriptionStatusChangedEvent extends Event
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly ?string $tenantCode,
        public readonly string $fulfillmentType,
        public readonly ?string $fulfillmentRef,
        public readonly ?string $fulfillmentTarget,
        public readonly string $from,
        public readonly string $to,
        public readonly ?\DateTimeImmutable $currentPeriodEnd,
        public readonly ?\DateTimeImmutable $previousPeriodEnd,
    ) {
    }

    public function statusChanged(): bool
    {
        return $this->from !== $this->to;
    }

    public function periodChanged(): bool
    {
        return ($this->previousPeriodEnd?->getTimestamp()) !== ($this->currentPeriodEnd?->getTimestamp());
    }
}
