<?php

namespace App\UseCase\LandingSiteModelUseCase;

use App\Dto\LandingSiteModelSummaryDto;
use App\Services\LandingSiteModelService\LandingSiteModelService;

/** GET /api/{app}-site-models : résumés des modèles de l'application, du plus récent au plus ancien */
class ListLandingSiteModelsUseCase
{
    public function __construct(private readonly LandingSiteModelService $models)
    {
    }

    /** @return list<LandingSiteModelSummaryDto> */
    public function execute(string $app): array
    {
        return array_map(fn ($model) => LandingSiteModelSummaryDto::fromEntity($model), $this->models->all($app));
    }
}
