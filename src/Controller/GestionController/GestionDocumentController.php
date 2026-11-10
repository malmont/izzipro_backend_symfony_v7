<?php

namespace App\Controller\GestionController;

use App\Entity\GestionDocument;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\GestionDocumentService\GestionDocumentService;
use App\Services\GestionService\GestionConflictException;
use App\Services\LandingAiService\LandingAiException;
use App\UseCase\GestionUseCase\ConfirmGestionDocumentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture de documents par IA pour l'application de gestion (lot L1, 10/11/2026, `docs/lecture-documents-gestion.md`), `ROLE_ADMIN`
 * (`access_control` `/api/gestion`) : envoi (202, lecture asynchrone), état, liste, fichiers, relecture, brouillon, photo par ligne,
 * confirmation, suppression. Codes : 422 DOCUMENT_INVALID / VALIDATION_FAILED, 402 AI_CREDITS_EXHAUSTED, 403 AI_NOT_INCLUDED, 409 DOCUMENT_IN_PROGRESS /
 * DOCUMENT_CONFIRMED / INVOICE_ALREADY_CONFIRMED / PERIOD_CLOSED / SHIPPING_COST_ALREADY_SET, 410 DOCUMENT_EXPIRED, 404.
 */
#[Route('/api/gestion/documents')]
class GestionDocumentController extends AbstractController
{
    public function __construct(private readonly GestionDocumentService $documents, private readonly ConfirmGestionDocumentUseCase $confirm, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    #[Route('', name: 'gestion_documents_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $files = $request->files->get('files') ?? $request->files->get('file');
        $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile)) : []);
        try {
            $this->documents->assertAiIncluded($this->isGranted('ROLE_SUPER_ADMIN'));
            $document = $this->documents->create((string) $request->request->get('type', ''), $files, $this->locale($request->request->get('locale')), $this->getUser()?->getUserIdentifier());
        } catch (LandingAiException $e) {
            return $this->aiError($e);
        }

        return $this->json($this->documents->toArray($document), Response::HTTP_ACCEPTED);
    }

    #[Route('', name: 'gestion_documents_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        $status = $request->query->get('status');
        if ($type !== null && !in_array($type, GestionDocument::TYPES, true)) {
            throw new GestionConflictException('VALIDATION_FAILED', 'type : facture, note ou transport', 422, ['fields' => ['type' => 'INVALID_TYPE']]);
        }
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        [$rows, $total] = $this->documents->search($type, $status !== null ? (string) $status : null, $page, $limit);
        $response = $this->json(array_map(fn (GestionDocument $d) => $this->documents->toArray($d, false), $rows));
        $response->headers->set('X-Total-Count', (string) $total);
        $response->headers->set('X-Page', (string) $page);
        $response->headers->set('X-Limit', (string) $limit);

        return $response;
    }

    #[Route('/{id}', name: 'gestion_documents_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(GestionDocument $document): JsonResponse
    {
        $this->documents->releaseStaleReadings(); // l'écran interroge cette route : une lecture perdue doit y apparaître en échec
        return $this->json($this->documents->toArray($document));
    }

    #[Route('/{id}', name: 'gestion_documents_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(GestionDocument $document): Response
    {
        $this->documents->delete($document);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/fichier/{n}', name: 'gestion_documents_file', methods: ['GET'], requirements: ['id' => '\d+', 'n' => '\d+'])]
    public function page(GestionDocument $document, int $n): Response
    {
        return $this->binary($this->documents->file($document, $n), 'page-' . $n);
    }

    #[Route('/{id}/relire', name: 'gestion_documents_reread', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reread(GestionDocument $document): JsonResponse
    {
        try {
            $this->documents->assertAiIncluded($this->isGranted('ROLE_SUPER_ADMIN'));
            $this->documents->reread($document, $this->getUser()?->getUserIdentifier());
        } catch (LandingAiException $e) {
            return $this->aiError($e);
        }

        return $this->json($this->documents->toArray($document), Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}/brouillon', name: 'gestion_documents_draft', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function draft(GestionDocument $document, Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $this->documents->saveDraft($document, $data);

        return $this->json($this->documents->toArray($document));
    }

    #[Route('/{id}/lignes/{n}/photo', name: 'gestion_documents_line_photo_put', methods: ['POST'], requirements: ['id' => '\d+', 'n' => '\d+'])]
    public function linePhoto(GestionDocument $document, int $n, Request $request): JsonResponse
    {
        $file = $request->files->get('file') ?? $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            throw new GestionConflictException('DOCUMENT_INVALID', 'Photo attendue dans le champ « file » (multipart)', 422, ['fields' => ['file' => 'REQUIRED']]);
        }

        return $this->json($this->documents->storeLinePhoto($document, $n, $file));
    }

    #[Route('/{id}/lignes/{n}/photo', name: 'gestion_documents_line_photo_get', methods: ['GET'], requirements: ['id' => '\d+', 'n' => '\d+'])]
    public function linePhotoGet(GestionDocument $document, int $n): Response
    {
        return $this->binary($this->documents->file($document, $n, true), 'ligne-' . $n);
    }

    #[Route('/{id}/lignes/{n}/photo', name: 'gestion_documents_line_photo_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'n' => '\d+'])]
    public function linePhotoDelete(GestionDocument $document, int $n): Response
    {
        $this->documents->removeLinePhoto($document, $n);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/confirmer', name: 'gestion_documents_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(GestionDocument $document, Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->confirm->execute($document, $data, $request->getSchemeAndHttpHost(), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    private function binary(?array $file, string $name): Response
    {
        if ($file === null) {
            throw new GestionConflictException('FILE_NOT_FOUND', 'Fichier introuvable.', 404);
        }
        [$path, $mime] = $file;
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name . '.' . (\App\Services\GestionDocumentService\GestionDocumentStorage::EXTENSIONS[$mime] ?? 'bin'));

        return $response;
    }

    private function locale(mixed $asked): string
    {
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }

    private function aiError(LandingAiException $e): JsonResponse
    {
        $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 403 => 'AI_NOT_INCLUDED', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

        return $this->json(['error' => $code, 'code' => $code, 'message' => $e->getMessage()], $e->getStatusCode());
    }
}
