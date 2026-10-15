<?php

namespace App\UseCase\CaisseUseCase;

use App\Dto\CashRegisterInputDto;
use App\Dto\CashRegisterOutputDto;
use App\Services\CaisseService\CashRegisterService;

/** POST /api/cash-registers {name} (ROLE_ADMIN) */
class CreateCashRegisterUseCase
{
    public function __construct(private readonly CashRegisterService $registers)
    {
    }

    public function execute(CashRegisterInputDto $input): array
    {
        return CashRegisterOutputDto::toArray($this->registers->create((string) $input->name), null);
    }
}
