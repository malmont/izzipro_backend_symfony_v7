<?php

namespace App\MemoiresVivantes\UseCase\Pricing;

use App\MemoiresVivantes\Dto\PricingInputDto;
use App\MemoiresVivantes\Entity\PricingSetting;
use App\MemoiresVivantes\Services\PricingService;

/** PUT /api/memoires/admin/pricing : validation puis remplacement de la grille du site */
class UpdatePricingUseCase
{
    public function __construct(private readonly PricingService $pricing)
    {
    }

    /** @throws \InvalidArgumentException grille invalide (détail dans $dto->errors) */
    public function execute(PricingInputDto $dto): PricingSetting
    {
        if ($dto->errors !== []) {
            throw new \InvalidArgumentException($dto->errors[0]['path'] . ' : ' . $dto->errors[0]['message']);
        }

        return $this->pricing->save($dto->grid);
    }
}
