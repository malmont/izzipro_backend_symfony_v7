<?php

namespace App\UseCase\LandingSiteModelUseCase;

use App\Dto\LandingSiteModelSummaryDto;
use App\Services\LandingSiteModelService\LandingSiteModelService;

/** POST, PUT et DELETE /api/landingpage-site-models : enregistrement, modification, suppression */
class SaveLandingSiteModelUseCase
{
    public function __construct(private readonly LandingSiteModelService $models)
    {
    }

    public function create(mixed $body, ?string $user): LandingSiteModelSummaryDto
    {
        return LandingSiteModelSummaryDto::fromEntity($this->models->create($body, $user));
    }

    public function update(int $id, mixed $body): LandingSiteModelSummaryDto
    {
        return LandingSiteModelSummaryDto::fromEntity($this->models->update($id, $body));
    }

    public function delete(int $id): void
    {
        $this->models->delete($id);
    }
}
