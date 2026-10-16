<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\UseCase\Quota\GetMyAiQuotasUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Quotas IA vus par l'application mobile Living Memory (16/10/2026). Connexion obligatoire (access_control
 * `^/api/memoires/mobile/`). Contrat : docs/memoires-vivantes.md, « Quotas des appels IA ».
 */
#[Route('/api/memoires/mobile/quotas')]
class MobileQuotaController extends AbstractController
{
    public function __construct(private readonly GetMyAiQuotasUseCase $myQuotas)
    {
    }

    /** {transcription: {limit, used, remaining, resetsAt}, improvement: {…}, maxAudioSeconds} ; limit null = illimité */
    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        return $this->json($this->myQuotas->execute($user));
    }
}
