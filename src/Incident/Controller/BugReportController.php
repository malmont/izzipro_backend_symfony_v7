<?php

namespace App\Incident\Controller;

use App\Entity\User;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\UseCase\PlatformBugReportUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * « Signaler un problème » et « Mes signalements » (lot INC-3, 18/11/2026, `docs/incidents-et-signalements.md`), pour le PERSONNEL du site
 * (règle `^/api/incidents/bug-reports$` d'`access_control` : administrateur, responsable de caisse, caissier ; un client reçoit 403) :
 * - POST (multipart : `description`, `app`, `appVersion?`, `screen?`, `context?` en JSON, `screenshot?` PNG, JPEG ou WebP de 5 Mo) →
 *   201 { report } ; 422 VALIDATION_FAILED { fields }, 403 NOT_STAFF, 429 BUG_REPORT_LIMIT { scope: account | site } ;
 * - GET → { reports [{ id, app, appVersion, screen, description, status, answer, createdAt, answeredAt }] } : ceux du compte connecté, sur
 *   ce site ; jamais la note interne ni la capture.
 */
class BugReportController extends AbstractController
{
    public function __construct(private readonly PlatformBugReportUseCase $reports)
    {
    }

    #[Route('/api/incidents/bug-reports', name: 'api_incidents_bug_reports_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $file = $request->files->get('screenshot');
        try {
            return $this->json($this->reports->create($request->request->all(), $file instanceof UploadedFile ? $file : null, $user), 201);
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Signalement invalide.', 'fields' => $e->fields], 422);
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    #[Route('/api/incidents/bug-reports', name: 'api_incidents_bug_reports_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            return $this->json($this->reports->mine($user));
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }
}
