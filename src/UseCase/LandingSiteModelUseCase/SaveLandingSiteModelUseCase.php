<?php

namespace App\UseCase\LandingSiteModelUseCase;

use App\Dto\LandingSiteModelSummaryDto;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingSiteModelService\LandingSiteModelService;

/**
 * POST, PUT et DELETE /api/{app}-site-models : enregistrement, modification, suppression, inscrites au journal sous
 * la ressource « landingpage-site-models » ou « boutique-site-models ».
 */
class SaveLandingSiteModelUseCase
{
    public function __construct(private readonly LandingSiteModelService $models, private readonly ContentAuditRecorder $audit)
    {
    }

    public function create(string $app, mixed $body, ?string $user): LandingSiteModelSummaryDto
    {
        $model = $this->models->create($app, $body, $user);
        $this->audit->record("$app-site-models", $model->getId(), 'create', null, LandingSiteModelSummaryDto::fullJson($model), ['name', 'description', 'configuration']);

        return LandingSiteModelSummaryDto::fromEntity($model);
    }

    public function update(int $id, string $app, mixed $body): LandingSiteModelSummaryDto
    {
        $before = LandingSiteModelSummaryDto::fullJson($this->models->get($id, $app));
        $model = $this->models->update($id, $app, $body);
        $this->audit->record("$app-site-models", $id, 'update', $before, LandingSiteModelSummaryDto::fullJson($model),
            is_object($body) ? array_values(array_intersect(array_keys(get_object_vars($body)), LandingSiteModelService::FIELDS)) : []);

        return LandingSiteModelSummaryDto::fromEntity($model);
    }

    public function delete(int $id, string $app): void
    {
        $before = LandingSiteModelSummaryDto::fullJson($this->models->get($id, $app));
        $this->models->delete($id, $app);
        $this->audit->record("$app-site-models", $id, 'delete', $before, null);
    }
}
