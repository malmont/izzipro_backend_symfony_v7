<?php

namespace App\UseCase\ServiceFamilyUseCase;

use App\Dto\ServiceFamilyInputDto;
use App\Dto\ServiceFamilyOutputDto;
use App\Entity\ServiceFamily;
use App\Services\ServiceFamilyService\ServiceFamilyService;
use App\UseCase\FormSubmissionUseCase\FormSubmissionException;

/** /api/service-families : liste (publique), création, modification, suppression (administrateur) */
class ManageServiceFamiliesUseCase
{
    public function __construct(private readonly ServiceFamilyService $families)
    {
    }

    /** @return list<array> */
    public function list(): array
    {
        return array_map([ServiceFamilyOutputDto::class, 'of'], $this->families->all());
    }

    /** @throws FormSubmissionException 400, 422 */
    public function create(mixed $body): array
    {
        $dto = $this->dto($body, true);

        return ServiceFamilyOutputDto::of($this->families->save(new ServiceFamily(), $dto));
    }

    /** @throws FormSubmissionException 400, 404, 422 */
    public function update(int $id, mixed $body): array
    {
        $family = $this->find($id);

        return ServiceFamilyOutputDto::of($this->families->save($family, $this->dto($body, false)));
    }

    /** @throws FormSubmissionException 404 */
    public function delete(int $id): void
    {
        $this->families->delete($this->find($id));
    }

    private function dto(mixed $body, bool $nameRequired): ServiceFamilyInputDto
    {
        $dto = ServiceFamilyInputDto::fromBody($body, $nameRequired);
        if ($dto->errors) {
            throw new FormSubmissionException($dto->errors[0]['path'] === '' ? 400 : 422, 'Champs refusés : ' . ($dto->errors[0]['path'] ?: 'corps') . ' : ' . $dto->errors[0]['message'], $dto->errors);
        }

        return $dto;
    }

    private function find(int $id): ServiceFamily
    {
        return $this->families->find($id) ?? throw new FormSubmissionException(404, 'Famille introuvable.');
    }
}
