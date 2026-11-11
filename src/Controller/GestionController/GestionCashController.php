<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\GestionService\GestionValidationException;
use App\UseCase\GestionUseCase\GestionCashUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Trésorerie saisie de la gestion (lot B1b-1, 11/11/2026, `docs/tresorerie-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) :
 * projection du solde, soldes datés, mouvements prévus, réglages. Code d'écran : `tresorerie`.
 */
#[Route('/api/gestion/tresorerie')]
class GestionCashController extends AbstractController
{
    public function __construct(private readonly GestionCashUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('', name: 'gestion_tresorerie', methods: ['GET'])]
    public function projection(Request $request): JsonResponse
    {
        return $this->json($this->useCase->projection($request->query->get('days'), $this->locale($request)));
    }

    #[Route('/soldes', name: 'gestion_tresorerie_soldes', methods: ['GET'])]
    public function balances(Request $request): JsonResponse
    {
        return $this->json($this->useCase->balances(max(1, min(200, (int) $request->query->get('limit', 50)))));
    }

    #[Route('/soldes', name: 'gestion_tresorerie_soldes_create', methods: ['POST'])]
    public function saveBalance(Request $request): JsonResponse
    {
        $out = $this->useCase->saveBalance($this->body($request), $this->getUser()?->getUserIdentifier());

        return $this->json($out, $out['replaced'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    #[Route('/soldes/{id}', name: 'gestion_tresorerie_soldes_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateBalance(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->updateBalance($id, $this->body($request)));
    }

    #[Route('/soldes/{id}', name: 'gestion_tresorerie_soldes_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteBalance(int $id): Response
    {
        $this->useCase->deleteBalance($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Lot RAP : historique des rapprochements (attendu, réel, écart, décision, composition), du plus récent au plus ancien */
    #[Route('/rapprochements', name: 'gestion_tresorerie_rapprochements', methods: ['GET'])]
    public function reconciliations(Request $request): JsonResponse
    {
        return $this->json($this->useCase->reconciliations(max(1, min(200, (int) $request->query->get('limit', 50)))));
    }

    /** Lot RAP : décision du gérant sur l'écart d'un solde (unexplained, explained avec note, open pour revenir) */
    #[Route('/soldes/{id}/ecart', name: 'gestion_tresorerie_soldes_ecart', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decideGap(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->decideGap($id, $this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/mouvements', name: 'gestion_tresorerie_mouvements', methods: ['GET'])]
    public function movements(Request $request): JsonResponse
    {
        $category = $request->query->get('category');

        return $this->json($this->useCase->movements(is_string($category) ? strtolower(trim($category)) : null, $this->locale($request), $this->isGranted('ROLE_ADMIN')));
    }

    #[Route('/mouvements', name: 'gestion_tresorerie_mouvements_create', methods: ['POST'])]
    public function createMovement(Request $request): JsonResponse
    {
        return $this->json($this->useCase->createMovement($this->body($request), $this->getUser()?->getUserIdentifier(), $this->locale($request)), Response::HTTP_CREATED);
    }

    #[Route('/mouvements/{id}', name: 'gestion_tresorerie_mouvements_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateMovement(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->updateMovement($id, $this->body($request), $this->locale($request)));
    }

    #[Route('/mouvements/{id}', name: 'gestion_tresorerie_mouvements_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteMovement(int $id, Request $request): Response
    {
        $this->useCase->deleteMovement($id, $this->locale($request));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/reglages', name: 'gestion_tresorerie_reglages', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->json($this->useCase->settings());
    }

    #[Route('/reglages', name: 'gestion_tresorerie_reglages_put', methods: ['PUT'])]
    public function putSettings(Request $request): JsonResponse
    {
        return $this->json($this->useCase->putSettings($this->body($request)));
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }

    private function locale(Request $request): string
    {
        $asked = $request->query->get('locale');
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}
