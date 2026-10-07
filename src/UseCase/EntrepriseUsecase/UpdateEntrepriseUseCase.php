<?php

namespace App\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EntrepriseService\EntrepriseService;

class UpdateEntrepriseUseCase
{
    private EntrepriseService $entrepriseService;

    public function __construct(EntrepriseService $entrepriseService, private readonly ContentAuditRecorder $audit)
    {
        $this->entrepriseService = $entrepriseService;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function execute(int $id, array $data, string $host, string $locale): ?EntrepriseDto
    {
        // journal des écritures : valeurs avant / après des seules clés envoyées
        $before = $this->entrepriseService->snapshot($id, array_keys($data), $locale);
        $dto = $this->entrepriseService->updateEntreprise($id, $data, $host, $locale);
        if ($dto !== null && $before !== null) {
            $after = $this->entrepriseService->snapshot($id, array_keys($before), $locale);
            $changed = array_keys(array_filter($after, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->audit->record('entreprise', $id, 'update', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $changed, $locale);
            }
        }

        return $dto;
    }
}
