<?php

namespace App\UseCase\LandingSiteModelUseCase;

use App\Dto\LandingSiteModelSummaryDto;
use App\Services\LandingSiteModelService\LandingSiteModelService;

/** GET /api/landingpage-site-models : résumés, du plus récent au plus ancien */
class ListLandingSiteModelsUseCase
{
    public function __construct(private readonly LandingSiteModelService $models)
    {
    }

    /** @return list<LandingSiteModelSummaryDto> */
    public function execute(): array
    {
        return array_map(fn ($model) => LandingSiteModelSummaryDto::fromEntity($model), $this->models->all());
    }
}
