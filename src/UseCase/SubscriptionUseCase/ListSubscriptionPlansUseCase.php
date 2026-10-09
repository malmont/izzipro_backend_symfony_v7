<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Dto\SubscriptionPlanOutputDto;
use App\Services\SubscriptionService\SubscriptionService;

/** GET /api/subscription-plans?productId= : formules actives, nom dans la langue demandée */
class ListSubscriptionPlansUseCase
{
    public function __construct(private readonly SubscriptionService $service)
    {
    }

    /** @return list<SubscriptionPlanOutputDto> */
    public function execute(?int $productId, string $locale): array
    {
        return $this->service->planDtos($productId, $locale);
    }
}
