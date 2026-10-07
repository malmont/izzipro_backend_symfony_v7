<?php

namespace App\UseCase\LandingSiteModelUseCase;

use App\Dto\LandingSiteModelSummaryDto;
use App\Services\LandingSiteModelService\LandingSiteModelService;

/** GET /api/landingpage-site-models/{id} : modèle complet, configuration restituée telle qu'enregistrée */
class GetLandingSiteModelUseCase
{
    public function __construct(private readonly LandingSiteModelService $models)
    {
    }

    /** @return string JSON */
    public function execute(int $id): string
    {
        return LandingSiteModelSummaryDto::fullJson($this->models->get($id));
    }
}
