<?php

namespace App\Incident\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\UseCase\PlatformIncidentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Le gestionnaire d'erreurs de chaque application signale une erreur (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`).
 * POST { app, appVersion?, screen?, route?, level?, message, context? } → 202 { incidentId, fingerprint }.
 * Avec le jeton de l'utilisateur quand il y en a un (un jeton expiré : 401 du pare-feu, renvoyer sans jeton), sinon sans jeton (limite
 * par site et par IP, plafond des incidents nouveaux par site et par jour). Le site est celui de la requête, jamais celui du corps.
 * 422 VALIDATION_FAILED { fields }, 429 RATE_LIMITED (`Retry-After`). Route ouverte même pendant la construction d'une boutique.
 */
class IncidentReportController extends AbstractController
{
    public function __construct(private readonly PlatformIncidentUseCase $incidents)
    {
    }

    #[Route('/api/incidents/report', name: 'api_incidents_report', methods: ['POST'])]
    public function report(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'objet JSON attendu', 'fields' => ['body' => 'INVALID_JSON']], 422);
        }
        $user = $this->getUser();
        try {
            $result = $this->incidents->report($data, $user instanceof User ? $user->getId() : null, (string) $request->getClientIp(), $request->headers->get('X-Request-Id'));
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Rapport invalide.', 'fields' => $e->fields], 422);
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status, isset($e->details['retryAfterSeconds']) ? ['Retry-After' => (string) $e->details['retryAfterSeconds']] : []);
        }

        return $this->json($result, 202);
    }
}
