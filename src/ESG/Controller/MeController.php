<?php

namespace App\ESG\Controller;

use App\ESG\UseCase\Team\GetMeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * GET /api/boussole/me (lot BOUSSOLE-CONSEILLER, 13/11/2026) : « qui suis-je » pour le front de la Boussole, quel que soit le compte
 * connecté. → {kind: company | team | none, id, email, firstName, lastName, roles, teamRoles, isAdvisor, company}.
 */
final class MeController extends AbstractController
{
    #[Route('/api/boussole/me', name: 'esg_me', methods: ['GET'])]
    public function me(GetMeUseCase $useCase): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->json(['error' => 'Authentification requise.', 'code' => 'UNAUTHENTICATED'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($useCase->execute($user));
    }
}
