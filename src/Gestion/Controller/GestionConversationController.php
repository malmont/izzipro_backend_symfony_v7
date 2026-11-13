<?php

namespace App\Gestion\Controller;

use App\Gestion\UseCase\GestionConversationUseCase;
use App\Services\LandingAiService\LandingAiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fil de conversation de la gestion (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md`), `ROLE_ADMIN` (`access_control`
 * `/api/gestion`), module de gestion actif : une facture fournisseur menée de la pièce jointe à la commande, puis annulable d'un bloc.
 * Le serveur calcule les questions, l'aperçu et son empreinte ; aucune IA dans ce lot hors la lecture du document.
 * Codes : 404 `CONVERSATION_NOT_FOUND`, `DOCUMENT_NOT_FOUND` ; 409 `CONVERSATION_NOT_OPEN`, `CONVERSATION_NOT_READY`, `PREVIEW_CHANGED`,
 * `UNDO_BLOCKED`, `UNDO_EXPIRED`, `ALREADY_UNDONE` ; 422 `VALIDATION_FAILED`, `DOCUMENT_INVALID`, `DOCUMENT_TYPE` ; ceux de l'envoi et de
 * la confirmation d'un document (`GestionDocumentController`).
 */
#[Route('/api/gestion/conversations')]
class GestionConversationController extends AbstractController
{
    public function __construct(private readonly GestionConversationUseCase $useCase)
    {
    }

    /** Multipart `files[]` (ou `file`), `text?`, `locale?` ; ou JSON `{ documentId, text? }`. 201 nouveau fil, 200 fil déjà ouvert pour ce document. */
    #[Route('', name: 'gestion_conversations_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $files = $request->files->get('files') ?? $request->files->get('file');
        $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile)) : []);
        $json = $this->body($request) ?? [];
        $text = $request->request->get('text') ?? ($json['text'] ?? null);
        try {
            $out = $this->useCase->start($files, $request->request->get('documentId') ?? ($json['documentId'] ?? null), is_string($text) ? $text : null,
                self::locale($request->request->get('locale') ?? ($json['locale'] ?? null)) ?? 'fr', $this->getUser()?->getUserIdentifier(), $this->isGranted('ROLE_SUPER_ADMIN'));
        } catch (LandingAiException $e) {
            $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 403 => 'AI_NOT_INCLUDED', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

            return $this->json(['error' => $code, 'code' => $code, 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json($out['thread'], $out['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('', name: 'gestion_conversations_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('limit', 50)));
        [$rows, $total] = $this->useCase->list($page, $limit);

        return $this->json($rows, Response::HTTP_OK, ['X-Total-Count' => (string) $total, 'X-Page' => (string) $page, 'X-Limit' => (string) $limit]);
    }

    /** Le fil : état, résumé de la lecture, messages, questions, aperçu et empreinte, annulation. À interroger toutes les 3 à 5 s pendant la lecture. */
    #[Route('/{id}', name: 'gestion_conversations_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->thread($id, self::locale($request->query->get('locale'))));
    }

    /** `{ answers: [{ questionId, value }] }` → le fil à jour. Gratuit, aucune IA. */
    #[Route('/{id}/answers', name: 'gestion_conversations_answers', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function answers(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $body === null ? $this->invalidJson() : $this->json($this->useCase->answer($id, $body, self::locale($request->query->get('locale')), $this->getUser()?->getUserIdentifier()));
    }

    /** `{ previewHash }` → 201, le résultat de la confirmation d'une facture, `undo` et le fil ; 409 `PREVIEW_CHANGED { preview, previewHash }`. */
    #[Route('/{id}/confirm', name: 'gestion_conversations_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $body === null ? $this->invalidJson() : $this->json($this->useCase->confirm($id, $body, $request->getSchemeAndHttpHost(), self::locale($request->query->get('locale')), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    /** Annulation d'un bloc de la confirmation du fil → `{ undone, thread }` ; 409 `UNDO_BLOCKED { reasons }`, `UNDO_EXPIRED`, `ALREADY_UNDONE`. */
    #[Route('/{id}/undo', name: 'gestion_conversations_undo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function undo(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->undo($id, self::locale($request->query->get('locale')), $this->getUser()?->getUserIdentifier()));
    }

    /** Corps JSON (objet) ; corps vide = {} ; null si invalide */
    private function body(Request $request): ?array
    {
        $content = trim((string) $request->getContent());
        if ($content === '' || str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/')) {
            return [];
        }
        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function invalidJson(): JsonResponse
    {
        return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
    }

    private static function locale(mixed $asked): ?string
    {
        return is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true) ? strtolower($asked) : null;
    }
}
