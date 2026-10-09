<?php

namespace App\Dto;

use App\Entity\SubscriptionPlan;

/** Formule d'abonnement telle que le site la lit (GET /api/subscription-plans) ; prix en cents, nom dans la langue demandée */
final class SubscriptionPlanOutputDto
{
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $name,
        public readonly string $interval,
        public readonly int $intervalCount,
        public readonly int $price,
        public readonly string $currency,
        public readonly int $trialDays,
        public readonly int $minimumTerms,
        public readonly bool $active
    ) {
    }

    public static function fromEntity(SubscriptionPlan $plan, string $locale): self
    {
        return new self((int) $plan->getId(), (int) $plan->getProduct()?->getId(), $plan->getName($locale), $plan->getInterval(), $plan->getIntervalCount(),
            $plan->getPrice(), $plan->getCurrency(), $plan->getTrialDays(), $plan->getMinimumTerms(), $plan->isActive());
    }
}
