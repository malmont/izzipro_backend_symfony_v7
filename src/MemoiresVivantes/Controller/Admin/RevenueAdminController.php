<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\MemoiresVivantes\UseCase\Admin\GetRevenueOverviewUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Vue d'ensemble des revenus par livre (16/10/2026), lecture seule, ROLE_ADMIN. Contrat : docs/memoires-vivantes.md, § Vue d'ensemble par livre. */
#[Route('/api/memoires/admin/revenue')]
#[IsGranted('ROLE_ADMIN')]
class RevenueAdminController extends AbstractController
{
    public function __construct(private readonly GetRevenueOverviewUseCase $revenue)
    {
    }

    /** ?from&to&channel=web|mobile&search&page&perPage */
    #[Route('', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        try {
            return $this->json($this->revenue->execute($request->query->all()));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => explode(' ', $e->getMessage())[0], 'message' => $e->getMessage()]]], 422);
        }
    }
}
