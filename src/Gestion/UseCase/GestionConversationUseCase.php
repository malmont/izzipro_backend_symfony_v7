<?php

namespace App\Gestion\UseCase;

use App\Gestion\Entity\GestionDocument;
use App\Gestion\Services\Conversation\GestionConversationService;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fil de conversation de la gestion (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md`) : validation des entrées puis délégation
 * au service. Une facture entre dans un fil par ses fichiers (même chemin, mêmes refus et même crédit que
 * `POST /api/gestion/documents`) ou par l'identifiant d'un document déjà envoyé.
 */
class GestionConversationUseCase
{
    public function __construct(
        private readonly GestionConversationService $conversations,
        private readonly GestionDocumentService $documents,
        private readonly TenantEntityManagerProvider $emProvider,
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return array{thread: array<string, mixed>, created: bool}
     */
    public function start(array $files, mixed $documentId, ?string $text, string $locale, ?string $user, bool $platformOwner): array
    {
        if ($files !== []) {
            $this->documents->assertAiIncluded($platformOwner);
            $document = $this->documents->create(GestionDocument::TYPE_FACTURE, $files, $locale, $user);
        } elseif (is_numeric($documentId)) {
            $document = $this->emProvider->getEntityManager()->find(GestionDocument::class, (int) $documentId);
            if ($document === null) {
                throw new GestionConflictException('DOCUMENT_NOT_FOUND', 'Document introuvable.', 404, ['documentId' => (int) $documentId]);
            }
        } else {
            throw new GestionValidationException(['files' => 'REQUIRED'], 'Joignez la facture (files[]) ou donnez un document déjà envoyé (documentId)');
        }
        [$conversation, $created] = $this->conversations->open($document, $text, $locale, $user);

        return ['thread' => $this->conversations->thread($conversation, $locale), 'created' => $created];
    }

    /** @return array<string, mixed> */
    public function thread(int $id, ?string $locale): array
    {
        return $this->conversations->thread($this->conversations->find($id), $locale);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function answer(int $id, array $body, ?string $locale, ?string $user): array
    {
        $conversation = $this->conversations->find($id);
        if (!is_array($body['answers'] ?? null)) {
            throw new GestionValidationException(['answers' => 'REQUIRED'], 'answers : liste de { questionId, value } attendue');
        }
        $this->conversations->answer($conversation, $body['answers'], $user);

        return $this->conversations->thread($conversation, $locale);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function confirm(int $id, array $body, string $host, ?string $locale, ?string $user): array
    {
        $conversation = $this->conversations->find($id);
        $result = $this->conversations->confirm($conversation, $body['previewHash'] ?? null, $host, $user);

        return $result + ['thread' => $this->conversations->thread($conversation, $locale)];
    }

    /** @return array<string, mixed> */
    public function undo(int $id, ?string $locale, ?string $user): array
    {
        $conversation = $this->conversations->find($id);
        $undone = $this->conversations->undo($conversation, $user);

        return ['undone' => $undone, 'thread' => $this->conversations->thread($conversation, $locale)];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function list(int $page, int $limit): array
    {
        return $this->conversations->search($page, $limit);
    }
}
