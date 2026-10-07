<?php

namespace App\UseCase\LandingSiteModelUseCase;

use App\Dto\LandingSiteModelSummaryDto;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingSiteModelService\LandingSiteModelService;

/** POST, PUT et DELETE /api/landingpage-site-models : enregistrement, modification, suppression */
class SaveLandingSiteModelUseCase
{
    public function __construct(private readonly LandingSiteModelService $models, private readonly ContentAuditRecorder $audit)
    {
    }

    public function create(mixed $body, ?string $user): LandingSiteModelSummaryDto
    {
        $model = $this->models->create($body, $user);
        $this->audit->record('landingpage-site-models', $model->getId(), 'create', null, LandingSiteModelSummaryDto::fullJson($model), ['name', 'description', 'configuration']);

        return LandingSiteModelSummaryDto::fromEntity($model);
    }

    public function update(int $id, mixed $body): LandingSiteModelSummaryDto
    {
        $before = LandingSiteModelSummaryDto::fullJson($this->models->get($id));
        $model = $this->models->update($id, $body);
        $this->audit->record('landingpage-site-models', $id, 'update', $before, LandingSiteModelSummaryDto::fullJson($model),
            is_object($body) ? array_values(array_intersect(array_keys(get_object_vars($body)), LandingSiteModelService::FIELDS)) : []);

        return LandingSiteModelSummaryDto::fromEntity($model);
    }

    public function delete(int $id): void
    {
        $before = LandingSiteModelSummaryDto::fullJson($this->models->get($id));
        $this->models->delete($id);
        $this->audit->record('landingpage-site-models', $id, 'delete', $before, null);
    }
}
