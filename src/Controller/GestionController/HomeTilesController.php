<?php

namespace App\Controller\GestionController;

use App\Entity\User;
use App\Services\EmailConfigurationService\EmailLocale;
use App\UseCase\GestionUseCase\HomeTilesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vignettes d'accueil de l'application de gestion (première version, 11/11/2026, `docs/vignettes-gestion.md`), `ROLE_ADMIN`
 * (`access_control` `/api/gestion`) : 3 vignettes au plus pour l'utilisateur connecté, et l'écart d'une vignette.
 */
#[Route('/api/gestion/accueil/vignettes')]
class HomeTilesController extends AbstractController
{
    public function __construct(private readonly HomeTilesUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('', name: 'gestion_accueil_vignettes', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $response = $this->json($this->useCase->list($this->userId(), $this->locale($request->query->get('locale'))));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/{id}/ecarter', name: 'gestion_accueil_vignettes_ecarter', methods: ['POST'], requirements: ['id' => '[a-z0-9-]{1,40}'])]
    public function dismiss(string $id): Response
    {
        $this->useCase->dismiss($this->userId(), $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function userId(): int
    {
        $user = $this->getUser();

        return $user instanceof User ? (int) $user->getId() : 0;
    }

    private function locale(mixed $asked): string
    {
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}
