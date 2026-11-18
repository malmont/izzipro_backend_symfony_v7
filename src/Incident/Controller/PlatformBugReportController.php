<?php

namespace App\Incident\Controller;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\UseCase\PlatformBugReportUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Signalements, côté plateforme (lot INC-3, 18/11/2026), super administrateur seul (règle `^/api/platform` et l'attribut) :
 * - GET ?status=&tenant=&app=&page=&limit= → { reports [...], total, page, limit } ;
 * - GET /{id} → le signalement (note interne, contexte, ticket d'origine, `screenshot` booléen) ; 404 BUG_REPORT_NOT_FOUND ;
 * - GET /{id}/capture → l'image, servie ici seulement (jamais au site ni au signaleur), `Cache-Control: private, no-store` ;
 * - PATCH /{id} { status?, answer?, note?, incidentId? } → le signalement et `mailed` (courriel au signaleur quand la réponse change ou
 *   qu'un état final est posé) ; 422 { fields }, 404.
 */
#[Route('/api/platform/bug-reports')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformBugReportController extends AbstractController
{
    public function __construct(private readonly PlatformBugReportUseCase $reports)
    {
    }

    #[Route('', name: 'api_platform_bug_reports_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = [];
        foreach (['status', 'tenant', 'app'] as $key) {
            $value = $request->query->get($key);
            $filters[$key] = is_string($value) && $value !== '' ? $value : null;
        }
        try {
            return $this->json($this->reports->list($filters, max(1, $request->query->getInt('page', 1)), $request->query->getInt('limit', 50)));
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Filtre invalide.', 'fields' => $e->fields], 422);
        }
    }

    #[Route('/{id}', name: 'api_platform_bug_reports_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        try {
            return $this->json($this->reports->get($id));
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    #[Route('/{id}/capture', name: 'api_platform_bug_reports_capture', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function capture(int $id): Response
    {
        try {
            return self::image($this->reports->screenshotFile($id));
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    #[Route('/{id}', name: 'api_platform_bug_reports_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'objet JSON attendu', 'fields' => ['body' => 'INVALID_JSON']], 422);
        }
        try {
            return $this->json($this->reports->update($id, $data, (string) $this->getUser()?->getUserIdentifier()));
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Signalement invalide.', 'fields' => $e->fields], 422);
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** L'image d'une capture : affichée, jamais gardée en cache */
    public static function image(string $path): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'capture.' . pathinfo($path, PATHINFO_EXTENSION));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
