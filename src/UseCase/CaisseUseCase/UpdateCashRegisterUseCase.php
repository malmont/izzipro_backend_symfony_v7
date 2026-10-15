<?php

namespace App\UseCase\CaisseUseCase;

use App\Dto\CashRegisterInputDto;
use App\Dto\CashRegisterOutputDto;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\CaisseService\CashRegisterService;

/** PUT /api/cash-registers/{id} {name?, isActive?} (ROLE_ADMIN) ; désactiver un poste avec une session ouverte → 409 */
class UpdateCashRegisterUseCase
{
    public function __construct(private readonly CashRegisterService $registers, private readonly CashRegisterResolver $resolver)
    {
    }

    public function execute(int $id, CashRegisterInputDto $input): array
    {
        $register = $this->registers->update($this->registers->find($id), $input->hasName ? $input->name : null, $input->isActive);

        return CashRegisterOutputDto::toArray($register, $this->resolver->openSession($register));
    }
}
