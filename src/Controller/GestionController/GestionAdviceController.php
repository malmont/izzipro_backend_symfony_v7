<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\GestionService\GestionValidationException;
use App\UseCase\GestionUseCase\GestionAdviceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guide IA de la gestion (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) : conseils de la
 * période (règles au premier appel du jour, rédaction par l'IA en asynchrone), relance manuelle (3 par jour), fait, écarté. Code d'écran : `conseils`.
 */
#[Route('/api/gestion/conseils')]
class GestionAdviceController extends AbstractController
{
    public function __construct(private readonly GestionAdviceUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('', name: 'gestion_conseils', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list($request->query->get('periodId'), $request->query->get('status'), $this->locale($request)));
    }

    #[Route('/actualiser', name: 'gestion_conseils_actualiser', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        return $this->json($this->useCase->refresh($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}/fait', name: 'gestion_conseils_fait', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function done(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->setStatus($id, 'done', $this->body($request), $this->getUser()?->getUserIdentifier(), $this->locale($request)));
    }

    #[Route('/{id}/ecarter', name: 'gestion_conseils_ecarter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dismiss(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->setStatus($id, 'dismissed', $this->body($request), $this->getUser()?->getUserIdentifier(), $this->locale($request)));
    }

    private function body(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }
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
