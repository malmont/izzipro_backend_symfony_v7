<?php

namespace App\Controller\CashRegisterController;

use App\Dto\CashRegisterInputDto;
use App\Services\CaisseService\CashRegisterException;
use App\UseCase\CaisseUseCase\CreateCashRegisterUseCase;
use App\UseCase\CaisseUseCase\ListCashRegistersUseCase;
use App\UseCase\CaisseUseCase\UpdateCashRegisterUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Postes de caisse (15/10/2026). Lecture : caissiers et administrateurs ; écritures : administrateurs (access_control).
 * Pas de suppression : un poste se désactive (historique des sessions). Contrat : docs/caisse.md.
 */
class CashRegisterController extends AbstractController
{
    public function __construct(
        private readonly ListCashRegistersUseCase $list,
        private readonly CreateCashRegisterUseCase $create,
        private readonly UpdateCashRegisterUseCase $update
    ) {
    }

    #[Route('/api/cash-registers', name: 'cash_registers_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $includeInactive = $this->isGranted('ROLE_ADMIN') && $request->query->getBoolean('all');

        return $this->json($this->list->execute($includeInactive));
    }

    #[Route('/api/cash-registers', name: 'cash_registers_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            return $this->json($this->create->execute(CashRegisterInputDto::fromRequestBody($request->getContent(), true)), JsonResponse::HTTP_CREATED);
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    #[Route('/api/cash-registers/{id}', name: 'cash_registers_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            return $this->json($this->update->execute($id, CashRegisterInputDto::fromRequestBody($request->getContent(), false)));
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }
}
