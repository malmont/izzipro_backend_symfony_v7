<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossPreventionUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\LandingAiService\LandingAiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prévention des pertes, section de la gestion (lot L1-a, 14/11/2026, `docs/prevention-pertes.md`). `ROLE_ADMIN` (`access_control`
 * `/api/gestion`) : ni le responsable de caisse, ni le caissier. Deux gardes de module en mode « live » de la plateforme : la gestion
 * (`ModuleAccessSubscriber`), puis le module (`LossPreventionModuleSubscriber`, 403 `MODULE_INACTIVE` avec `feature: module.loss_prevention`).
 * Codes d'écran : `pertes.home`, `pertes.constats`, `pertes.constat`, `pertes.regles`, `pertes.reglages`, `pertes.exercices`.
 */
#[Route('/api/gestion/pertes')]
class LossPreventionController extends AbstractController
{
    public function __construct(private readonly LossPreventionUseCase $useCase, private readonly ?EmailLocale $siteLocale = null,
        private readonly ?\App\Services\Group\AllShops\AllShopsReader $allShops = null)
    {
    }

    #[Route('', name: 'gestion_pertes', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        // lot G-2 : « Toutes les boutiques » (en-tête X-Group-Scope) : jamais d'analyse lancée ; par poste et par jour, jamais par personne
        $locale = $this->locale($request);
        $query = ['analyse' => '0'] + $request->query->all();
        $query['analyse'] = '0';
        $group = $this->allShops?->forRequest($request, 'gestion_pertes', [], fn (): array => ['data' => \App\Services\Group\AllShops\AllShopsMerger::lossPart($this->useCase->summary($query, $locale))],
            \App\Services\Group\AllShops\AllShopsMerger::losses(...));
        if ($group !== null) {
            return $this->json($group);
        }

        return $this->json($this->useCase->summary($request->query->all(), $locale));
    }

    #[Route('/constats', name: 'gestion_pertes_constats', methods: ['GET'])]
    public function findings(Request $request): JsonResponse
    {
        $out = $this->useCase->findings($request->query->all(), $this->locale($request));

        return $this->json($out['rows'], 200, ['X-Total-Count' => (string) $out['total'], 'X-Page' => (string) $out['page'], 'X-Limit' => (string) $out['limit']]);
    }

    #[Route('/constats/{id}', name: 'gestion_pertes_constat', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function finding(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->finding($id, $this->locale($request), $this->userId()));
    }

    #[Route('/constats/{id}/decision', name: 'gestion_pertes_constat_decision', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->decide($id, $this->body($request), $this->locale($request), $this->userId()));
    }

    #[Route('/regles', name: 'gestion_pertes_regles', methods: ['GET'])]
    public function rules(Request $request): JsonResponse
    {
        return $this->json($this->useCase->rules($this->locale($request)));
    }

    /** Aperçu d'un réglage avant de l'appliquer : lecture seule, rien n'est écrit (POST parce que la demande porte un corps) */
    #[Route('/regles/apercu', name: 'gestion_pertes_regles_apercu', methods: ['POST'], priority: 1)]
    public function previewRule(Request $request): JsonResponse
    {
        return $this->json($this->useCase->previewRule($this->body($request), $this->locale($request)));
    }

    #[Route('/regles/{code}', name: 'gestion_pertes_regle_put', methods: ['PUT'], requirements: ['code' => '[A-Za-z_]+'])]
    public function putRule(string $code, Request $request): JsonResponse
    {
        return $this->json($this->useCase->putRule($code, $this->body($request), $this->locale($request), $this->userId()));
    }

    #[Route('/reglages', name: 'gestion_pertes_reglages', methods: ['GET'])]
    public function settings(Request $request): JsonResponse
    {
        return $this->json($this->useCase->settings($this->locale($request)));
    }

    #[Route('/reglages', name: 'gestion_pertes_reglages_put', methods: ['PUT'])]
    public function putSettings(Request $request): JsonResponse
    {
        return $this->json($this->useCase->putSettings($this->body($request), $this->locale($request), $this->userId()));
    }

    // ----- Mode exercice (lot EX-1). Ces cinq routes sont les SEULES de la section ouvertes sans le droit du module
    // (`LossPreventionModuleSubscriber::OPEN_ROUTES`) ; la garde de la gestion et le rôle d'administrateur restent exigés -----

    #[Route('/exercices', name: 'gestion_pertes_exercices', methods: ['GET'])]
    public function exercises(Request $request): JsonResponse
    {
        return $this->json($this->useCase->exercises($this->locale($request)));
    }

    #[Route('/exercices', name: 'gestion_pertes_exercices_start', methods: ['POST'])]
    public function startExercise(Request $request): JsonResponse
    {
        return $this->json($this->useCase->startExercise($this->body($request), $this->locale($request), $this->userId()), 201);
    }

    #[Route('/exercices', name: 'gestion_pertes_exercices_delete', methods: ['DELETE'])]
    public function deleteExercises(): JsonResponse
    {
        return $this->json($this->useCase->deleteExercises($this->userId()));
    }

    #[Route('/exercices/{id}', name: 'gestion_pertes_exercice', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exercise(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->exercise($id, $this->locale($request), $this->userId()));
    }

    #[Route('/exercices/{id}/decision', name: 'gestion_pertes_exercice_decision', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function decideExercise(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->decideExercise($id, $this->body($request), $this->locale($request), $this->userId()));
    }

    /**
     * Lot L5-c : l'avis de l'IA sur un constat → 202, la fiche avec `opinion` en préparation (relire la fiche jusqu'à `done` ou `failed`).
     * 403 `AI_NOT_INCLUDED`, 402 `AI_CREDITS_EXHAUSTED`, 429 `AI_DAILY_LIMIT`, 409 `OPINION_PENDING`, 422 `OPINION_NOT_ALLOWED`, 404.
     */
    #[Route('/constats/{id}/avis', name: 'gestion_pertes_constat_avis', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function opinion(int $id, Request $request): JsonResponse
    {
        return $this->opinionOf($id, $request, false);
    }

    /**
     * Lot L5-c (demande du web, 17/11/2026) : la lecture LÉGÈRE de l'avis, relue toutes les 3 s tant qu'il est en préparation → `{ opinion }`.
     * Aucun journal de consultation, et « nouveau » n'est pas éteint (la fiche, elle, se relit UNE fois à la fin). Vrais constats seulement.
     */
    #[Route('/constats/{id}/avis', name: 'gestion_pertes_constat_avis_lire', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function readOpinion(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->opinion($id, $this->locale($request)));
    }

    /** Lot L5-c : en exercice, un avis d'exemple écrit par le serveur, sans le modèle, à 0 unité (route ouverte sans le droit du module) */
    #[Route('/exercices/{id}/avis', name: 'gestion_pertes_exercice_avis', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function exerciseOpinion(int $id, Request $request): JsonResponse
    {
        return $this->opinionOf($id, $request, true);
    }

    private function opinionOf(int $id, Request $request, bool $exercise): JsonResponse
    {
        try {
            return $this->json($this->useCase->requestOpinion($id, $this->locale($request), $this->userId(), $this->getUser()?->getUserIdentifier(), $this->isGranted('ROLE_SUPER_ADMIN'), $exercise), 202);
        } catch (LandingAiException $e) {
            $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 403 => 'AI_NOT_INCLUDED', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

            return $this->json(['error' => $code, 'code' => $code, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    #[Route('/analyse', name: 'gestion_pertes_analyse', methods: ['POST'])]
    public function analyse(): JsonResponse
    {
        return $this->json($this->useCase->analyse());
    }

    private function userId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return (int) $user->getId();
    }

    /** @return array<string, mixed> */
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
