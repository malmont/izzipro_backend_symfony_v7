<?php

namespace App\Gestion\Controller;

use App\Gestion\UseCase\GestionScreenGuideUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guide d'un écran de la gestion (lot W2-APPRENDRE A-4, 13/11/2026, `docs/lexique-gestion.md` § 6). `ROLE_ADMIN` par `access_control`
 * (`/api/gestion`). Aucun appel à l'IA, aucune unité : le « ? » d'un écran, hors de la console. `?locale=en` pour l'anglais.
 */
class GestionScreenGuideController extends AbstractController
{
    public function __construct(private readonly GestionScreenGuideUseCase $useCase)
    {
    }

    /** `{ screen, title, theme: { key, label }, purpose, points: [{ label, text, term }], next }` ; 404 `SCREEN_GUIDE_NOT_FOUND` */
    #[Route('/api/gestion/ecrans/{code}/guide', name: 'gestion_ecran_guide', methods: ['GET'], requirements: ['code' => '[a-z0-9.\-]+'])]
    public function guide(string $code, Request $request): JsonResponse
    {
        $asked = $request->query->get('locale');

        return $this->json($this->useCase->guide($code, is_string($asked) && strtolower($asked) === 'en' ? 'en' : 'fr'));
    }
}
