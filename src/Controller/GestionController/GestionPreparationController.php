<?php

namespace App\Controller\GestionController;

use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\GestionService\GestionValidationException;
use App\UseCase\GestionUseCase\GestionPreparationUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Préparer une période entière (lot P6, 11/11/2026, `docs/preparation-periode.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) : estimations des
 * ventes, plan simulé sans écriture, avis du guide, « Valider » (budgets des périodes concernées et voyage, en une transaction).
 */
#[Route('/api/gestion/preparation')]
class GestionPreparationController extends AbstractController
{
    public function __construct(private readonly GestionPreparationUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('/estimations', name: 'gestion_preparation_estimations', methods: ['GET'])]
    public function estimations(Request $request): JsonResponse
    {
        return $this->json($this->useCase->estimations($request->query->get('periodId'), $request->query->get('date')));
    }

    #[Route('/simulation', name: 'gestion_preparation_simulation', methods: ['POST'])]
    public function simulate(Request $request): JsonResponse
    {
        return $this->json($this->useCase->simulate($this->body($request)));
    }

    #[Route('/avis', name: 'gestion_preparation_avis', methods: ['POST'])]
    public function advise(Request $request): JsonResponse
    {
        $asked = $request->query->get('locale');
        $locale = is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true) ? strtolower($asked) : ($this->siteLocale?->language(null) ?? 'fr');

        return $this->json($this->useCase->advise($this->body($request), $locale, $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/valider', name: 'gestion_preparation_valider', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        return $this->json($this->useCase->validate($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }
}
