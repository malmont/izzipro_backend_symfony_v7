<?php

namespace App\UseCase\FormSubmissionUseCase;

use App\Dto\FormSubmissionQueryDto;
use App\Services\FormSubmissionService\FormSubmissionCsvWriter;
use App\Services\FormSubmissionService\FormSubmissionService;

/** GET /api/form-submissions/export : mêmes filtres que la liste, MAX_ROWS demandes au plus (les plus récentes) */
class ExportFormSubmissionsUseCase
{
    public const MAX_ROWS = 5000;

    public function __construct(private readonly FormSubmissionService $submissions)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{csv: string, filename: string, truncated: bool, count: int}
     * @throws FormSubmissionException 400
     */
    public function execute(array $query): array
    {
        $zone = $this->submissions->timezone();
        $dto = FormSubmissionQueryDto::fromQuery($query, $zone);
        if ($dto->errors) {
            throw new FormSubmissionException(400, 'Paramètre invalide : ' . $dto->errors[0]['path'] . ' : ' . $dto->errors[0]['message'], $dto->errors);
        }
        [$items, $truncated] = $this->submissions->forExport($dto->filters(), self::MAX_ROWS);

        return [
            'csv' => FormSubmissionCsvWriter::write($items, $zone),
            'filename' => sprintf('demandes-recues-%s.csv', (new \DateTimeImmutable('now', $zone))->format('Ymd')),
            'truncated' => $truncated,
            'count' => count($items),
        ];
    }
}
