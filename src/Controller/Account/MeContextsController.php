<?php

namespace App\Controller\Account;

use App\Services\Screens\ContextService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Les contextes du compte connecté (lot AG-3a, `docs/ecrans-et-contextes.md`) : son mode, les sites qu'il peut ouvrir, et pour chacun
 * ses pastilles, son état, ses modules actifs et les écrans qu'un menu peut montrer. Lecture seule ; jamais en cache partagé.
 */
class MeContextsController extends AbstractController
{
    public function __construct(private readonly ContextService $contexts)
    {
    }

    #[Route('/api/me/contexts', name: 'api_me_contexts', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $response = new JsonResponse($this->contexts->contexts($this->getUser()?->getRoles() ?? []));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
