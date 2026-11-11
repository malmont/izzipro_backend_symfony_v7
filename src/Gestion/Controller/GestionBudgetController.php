<?php

namespace App\Gestion\Controller;

use App\Entity\Collections;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionBudgetUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Budget par poste d'une période de gestion (lot B1a, 11/11/2026, `docs/budgets-gestion.md` § 1), `ROLE_ADMIN` (`access_control`
 * `/api/periodes`) : prévu comparé au réel, saisie du prévu, copie depuis une autre période. Le budget global (`PATCH /api/periodes/{id}`)
 * reste à part.
 */
#[Route('/api/periodes/{id}/budget', requirements: ['id' => '\d+'])]
class GestionBudgetController extends AbstractController
{
    public function __construct(private readonly GestionBudgetUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('', name: 'gestion_periode_budget', methods: ['GET'])]
    public function get(Collections $collection, Request $request): JsonResponse
    {
        return $this->json($this->useCase->get($collection, $this->locale($request)));
    }

    #[Route('', name: 'gestion_periode_budget_put', methods: ['PUT'])]
    public function put(Collections $collection, Request $request): JsonResponse
    {
        return $this->json($this->useCase->put($collection, $this->body($request), $this->locale($request)));
    }

    #[Route('/copier', name: 'gestion_periode_budget_copier', methods: ['POST'])]
    public function copy(Collections $collection, Request $request): JsonResponse
    {
        $body = $request->getContent() === '' ? [] : $this->body($request);
        $from = $body['fromPeriodId'] ?? null;
        if ($from !== null && !is_int($from)) {
            throw new GestionValidationException(['fromPeriodId' => 'MUST_BE_INTEGER']);
        }

        return $this->json($this->useCase->copy($collection, $from, $this->locale($request)));
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
