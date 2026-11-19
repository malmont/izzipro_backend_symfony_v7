<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Abo\Services\BenefitService;
use App\Dto\SubscriptionPlanOutputDto;
use App\Services\SubscriptionService\SubscriptionService;

/**
 * GET /api/subscription-plans?productId= : formules actives, nom dans la langue demandée ; lot ABO-0 (18/11/2026) : chacune avec les
 * prestations qu'elle livre (`benefits[]`, jamais l'intervenant ni un coût).
 */
class ListSubscriptionPlansUseCase
{
    public function __construct(private readonly SubscriptionService $service, private readonly ?BenefitService $benefits = null)
    {
    }

    /** @return list<SubscriptionPlanOutputDto> */
    public function execute(?int $productId, string $locale): array
    {
        $dtos = $this->service->planDtos($productId, $locale);
        if ($this->benefits === null || $dtos === []) {
            return $dtos;
        }
        $byPlan = $this->benefits->planBenefits()->forPlans(array_map(fn (SubscriptionPlanOutputDto $d) => $d->id, $dtos));

        return array_map(fn (SubscriptionPlanOutputDto $d) => $d->withBenefits(array_map(fn ($pb) => $this->benefits->view($pb, $locale), $byPlan[$d->id] ?? [])), $dtos);
    }
}
