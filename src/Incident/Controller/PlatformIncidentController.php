<?php

namespace App\Incident\Controller;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\UseCase\PlatformIncidentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Incidents de la plateforme (lot INC-1a, 18/11/2026), super administrateur seul (règle `^/api/platform` d'`access_control` et l'attribut) :
 * - GET ?status=&app=&tenant=&level=&page=&limit= → { incidents [...], total, page, limit } ; 422 { fields } pour un filtre inconnu ;
 * - GET /{id} → l'incident et ses dernières occurrences (`events`) ; 404 INCIDENT_NOT_FOUND ;
 * - PATCH /{id} { status?, note?, externalUrl?, fixedIn? } → l'incident ; 422 { fields }, 404.
 */
#[Route('/api/platform/incidents')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformIncidentController extends AbstractController
{
    public function __construct(private readonly PlatformIncidentUseCase $incidents)
    {
    }

    #[Route('', name: 'api_platform_incidents_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = $request->query;
        $filters = [];
        foreach (['status', 'app', 'tenant', 'level'] as $key) {
            $value = $q->get($key);
            $filters[$key] = is_string($value) && $value !== '' ? $value : null;
        }
        try {
            return $this->json($this->incidents->list($filters, max(1, $q->getInt('page', 1)), $q->getInt('limit', 50)));
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Filtre invalide.', 'fields' => $e->fields], 422);
        }
    }

    #[Route('/{id}', name: 'api_platform_incidents_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        try {
            return $this->json($this->incidents->get($id));
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    #[Route('/{id}', name: 'api_platform_incidents_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'objet JSON attendu', 'fields' => ['body' => 'INVALID_JSON']], 422);
        }
        try {
            return $this->json($this->incidents->update($id, $data, (string) $this->getUser()?->getUserIdentifier()));
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Incident invalide.', 'fields' => $e->fields], 422);
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }
}
