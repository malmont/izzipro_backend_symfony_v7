<?php

namespace App\Gestion\UseCase;

use App\Gestion\Loan\GestionLoanService;
use App\Gestion\Services\GestionValidationException;

/** Prêts de la gestion (lot FIS-1, 19/11/2026, `docs/prets-gestion.md`) : la fiche, l'échéancier calculé, l'aperçu, l'exercice */
class GestionLoanUseCase
{
    public function __construct(private readonly GestionLoanService $loans)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->loans->list();
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->loans->get($id);
    }

    /** @return array<string, mixed> */
    public function preview(array $body): array
    {
        return $this->loans->preview($body);
    }

    /** @return array<string, mixed> */
    public function create(array $body, ?string $by): array
    {
        return $this->loans->create($body, $by);
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $body): array
    {
        return $this->loans->update($id, $body);
    }

    public function delete(int $id): void
    {
        $this->loans->delete($id);
    }

    /** @return array<string, mixed> */
    public function addPrepayment(int $id, array $body): array
    {
        return $this->loans->addPrepayment($id, $body);
    }

    /** @return array<string, mixed> */
    public function removePrepayment(int $id, int $index): array
    {
        return $this->loans->removePrepayment($id, $index);
    }

    /** @return array{from: string, to: string, loans: list<array<string, mixed>>} */
    public function financialYear(?string $from, ?string $to): array
    {
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            // date réelle : le 30 février ou le mois 13 sont refusés, jamais glissés au mois suivant
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') !== $v) {
                throw new GestionValidationException([$k => 'INVALID_DATE']);
            }
        }
        if ($from > $to) {
            throw new GestionValidationException(['to' => 'INVALID_RANGE']);
        }

        return ['from' => $from, 'to' => $to, 'loans' => $this->loans->financialYear($from, $to)];
    }
}
