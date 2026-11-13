<?php

namespace App\Gestion\UseCase;

use App\Gestion\Entity\GestionDocument;
use App\Gestion\Entity\GestionConversation;
use App\Gestion\Services\Conversation\Action\ActionCatalog;
use App\Gestion\Services\Conversation\ActionThreadService;
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
        private readonly ActionThreadService $actions,
        private readonly ActionCatalog $catalog,
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return array{thread: array<string, mixed>, created: bool}
     */
    public function start(array $files, mixed $documentId, ?string $text, string $locale, ?string $user, bool $platformOwner, mixed $action = null, mixed $params = null): array
    {
        if ($action !== null) {
            // lot IA-ACT-1 : fil d'une action du catalogue, sans document ; toujours un nouveau fil
            if ($files !== [] || $documentId !== null) {
                throw new GestionValidationException(['action' => 'NOT_WITH_DOCUMENT'], 'Une action se prépare sans document : envoyez soit action, soit une facture');
            }

            return ['thread' => $this->conversations->thread($this->actions->open($action, $params, $text, $locale, $user), $locale), 'created' => true];
        }
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

    /** @param array<string, mixed> $body @return array<string, mixed> phrase libre interprétée par l'IA (lot IA-FACT-2-B) */
    public function message(int $id, array $body, ?string $locale, ?string $user, bool $platformOwner): array
    {
        $conversation = $this->conversations->find($id);
        $out = $this->conversations->message($conversation, $body['text'] ?? null, $user, $platformOwner);

        return $out + ['thread' => $this->conversations->thread($conversation, $locale)];
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

    /** @return array<string, mixed> le fil abandonné */
    public function abandon(int $id, ?string $locale, ?string $user): array
    {
        $conversation = $this->conversations->find($id);
        $this->conversations->abandon($conversation, $user);

        return $this->conversations->thread($conversation, $locale);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} `kind` : `invoice` ou la clé d'une action du catalogue */
    public function list(int $page, int $limit, mixed $kind = null): array
    {
        if ($kind !== null && $kind !== '' && $kind !== GestionConversation::KIND_INVOICE && !(is_string($kind) && $this->catalog->has($kind))) {
            throw new GestionValidationException(['kind' => 'UNKNOWN_KIND'], 'kind : invoice, ' . implode(', ', $this->catalog->keys()));
        }

        return $this->conversations->search($page, $limit, $kind === '' ? null : $kind);
    }
}
