<?php

namespace App\Dto;

use App\Entity\Order;
use App\Entity\Subscription;

/** Abonnement d'un client (GET /api/subscriptions, …/{id}) : statut, période, adresse, transporteur, commandes des échéances */
final class SubscriptionOutputDto
{
    /**
     * @param list<array{id: int, reference: string, date: string, total: int, status: ?string}> $orders
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
        public readonly string $createdAt,
        public readonly ?string $canceledAt,
        public readonly array $orders
    ) {
    }

    /** @param list<Order> $orders */
    public static function fromEntity(Subscription $subscription, array $orders, string $locale): self
    {
        $carrier = $subscription->getCarrier();

        return new self(
            (int) $subscription->getId(),
            SubscriptionPlanOutputDto::fromEntity($subscription->getPlan(), $locale),
            $subscription->getQuantity(),
            $subscription->getStatus(),
            $subscription->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            $subscription->isCancelAtPeriodEnd(),
            $subscription->getAddress() ? new AdressOutputDTO($subscription->getAddress()) : null,
            $carrier ? ['id' => (int) $carrier->getId(), 'name' => $carrier->getTranslation($locale)?->getName() ?? $carrier->getName()] : null,
            $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $subscription->getCanceledAt()?->format(\DateTimeInterface::ATOM),
            array_map(fn (Order $o) => [
                'id' => (int) $o->getId(), 'reference' => (string) $o->getReference(), 'date' => $o->getOrderDate()->format(\DateTimeInterface::ATOM),
                'total' => (int) round((float) $o->getTotalAmount()), 'status' => $o->getStatus()?->getTranslation($locale)?->getName() ?? $o->getStatus()?->getName(),
            ], $orders)
        );
    }
}
