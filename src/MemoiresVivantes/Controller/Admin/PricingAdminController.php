<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\MemoiresVivantes\Dto\PricingInputDto;
use App\MemoiresVivantes\Services\PricingService;
use App\MemoiresVivantes\UseCase\Pricing\UpdatePricingUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Tarifs du site (16/10/2026) : grille du livre imprimé par devise. ROLE_ADMIN. Contrat : docs/memoires-vivantes.md, « Rédaction payante et livre imprimé ». */
#[Route('/api/memoires/admin/pricing')]
#[IsGranted('ROLE_ADMIN')]
class PricingAdminController extends AbstractController
{
    public function __construct(private readonly PricingService $pricing, private readonly UpdatePricingUseCase $update)
    {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->pricing->toArray());
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => 'Corps JSON invalide', 'errors' => [['path' => '', 'message' => 'corps JSON invalide']]], 422);
        }
        $dto = new PricingInputDto($data);
        try {
            $settings = $this->update->execute($dto);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $dto->errors], 422);
        }

        return $this->json($this->pricing->toArray($settings));
    }
}
