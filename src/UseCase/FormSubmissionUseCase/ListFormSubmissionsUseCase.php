<?php

namespace App\UseCase\FormSubmissionUseCase;

use App\Dto\FormSubmissionOutputDto;
use App\Dto\FormSubmissionQueryDto;
use App\Services\FormSubmissionService\FormSubmissionService;

/** GET /api/form-submissions : demandes reçues filtrées et paginées ; GET /api/form-submissions/forms : formulaires */
class ListFormSubmissionsUseCase
{
    public function __construct(private readonly FormSubmissionService $submissions)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items: list<array>, total: int, page: int, limit: int}
     * @throws FormSubmissionException 400
     */
    public function execute(array $query): array
    {
        $dto = FormSubmissionQueryDto::fromQuery($query, $this->submissions->timezone());
        if ($dto->errors) {
            throw new FormSubmissionException(400, 'Paramètre invalide : ' . $dto->errors[0]['path'] . ' : ' . $dto->errors[0]['message'], $dto->errors);
        }
        [$items, $total] = $this->submissions->search($dto->filters(), $dto->page, $dto->limit);

        return ['items' => array_map([FormSubmissionOutputDto::class, 'summary'], $items), 'total' => $total, 'page' => $dto->page, 'limit' => $dto->limit];
    }

    /** @return list<array{formId: string, formName: string, count: int, lastAt: string}> */
    public function forms(): array
    {
        return $this->submissions->forms();
    }
}
