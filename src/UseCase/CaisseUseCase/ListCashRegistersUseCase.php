<?php

namespace App\UseCase\CaisseUseCase;

use App\Dto\CashRegisterOutputDto;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\CaisseService\CashRegisterService;

/** GET /api/cash-registers : postes (actifs, ou tous pour un administrateur) avec leur session ouverte */
class ListCashRegistersUseCase
{
    public function __construct(private readonly CashRegisterService $registers, private readonly CashRegisterResolver $resolver)
    {
    }

    /** @return list<array<string, mixed>> */
    public function execute(bool $includeInactive): array
    {
        return array_map(fn ($register) => CashRegisterOutputDto::toArray($register, $this->resolver->openSession($register)), $this->registers->list($includeInactive));
    }
}
