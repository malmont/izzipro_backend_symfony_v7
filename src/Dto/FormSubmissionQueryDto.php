<?php

namespace App\Dto;

use App\Entity\FormSubmission;

/**
 * Filtres de la liste et de l'export des demandes reçues : formId, status, from et to (AAAA-MM-JJ, inclus, dans le
 * fuseau du site), page (dès 1), limit (1 à 100, 25 par défaut).
 */
final class FormSubmissionQueryDto
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 100;

    public ?string $formId = null;
    public ?string $status = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public int $page = 1;
    public int $limit = self::DEFAULT_LIMIT;
    /** @var list<array{path: string, message: string}> */
    public array $errors = [];

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, \DateTimeZone $siteZone): self
    {
        $dto = new self();
        $formId = $query['formId'] ?? null;
        if ($formId !== null && $formId !== '') {
            is_string($formId) && mb_strlen($formId) <= 100 ? $dto->formId = $formId : $dto->errors[] = ['path' => 'formId', 'message' => 'identifiant de formulaire attendu (100 caractères au plus)'];
        }
        $status = $query['status'] ?? null;
        if ($status !== null && $status !== '') {
            in_array($status, FormSubmission::STATUSES, true) ? $dto->status = $status : $dto->errors[] = ['path' => 'status', 'message' => 'statut attendu : ' . implode(', ', FormSubmission::STATUSES)];
        }
        foreach (['from' => 0, 'to' => 1] as $key => $nextDay) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $siteZone) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                $dto->errors[] = ['path' => $key, 'message' => 'date attendue au format AAAA-MM-JJ'];
                continue;
            }
            // borne dans le fuseau du site, convertie dans celui du serveur (dates enregistrées)
            $dto->$key = $date->modify("+$nextDay day")->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }
        foreach (['page' => [1, 100000], 'limit' => [1, self::MAX_LIMIT]] as $key => [$min, $max]) {
            $value = $query[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) !== false
                ? $dto->$key = (int) $value
                : $dto->errors[] = ['path' => $key, 'message' => sprintf('entier de %d à %d attendu', $min, $max)];
        }

        return $dto;
    }

    /** @return array{formId: ?string, status: ?string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable} */
    public function filters(): array
    {
        return ['formId' => $this->formId, 'status' => $this->status, 'from' => $this->from, 'to' => $this->to];
    }
}
