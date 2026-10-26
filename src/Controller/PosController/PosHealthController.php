<?php

namespace App\Controller\PosController;

use App\UseCase\PosHealthUseCase\PosHealthUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Surveillance des paiements de caisse (M1) : mode Stripe et compte Connect, dernière réconciliation, paiements sans vente, remboursements
 * Interac expirés, sessions ouvertes depuis plus de 24 h, échecs de capture. Administrateur du site seulement (access_control ^/api/pos/health).
 * Toujours 200 ; l'état est dans « status » (ok | info | warning | critical). Aucune donnée de carte.
 */
class PosHealthController extends AbstractController
{
    public function __construct(private readonly PosHealthUseCase $health)
    {
    }

    #[Route('/api/pos/health', name: 'pos_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $response = $this->json($this->health->report());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
