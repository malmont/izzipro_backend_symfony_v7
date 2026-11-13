<?php

namespace App\Gestion\Services\Conversation;

use App\Gestion\Entity\GestionConversation;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Entity\GestionDocument;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\Document\GestionDocumentUndoService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\ConfirmGestionDocumentUseCase;
use App\Gestion\UseCase\PreviewGestionDocumentUseCase;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fil de conversation de la gestion (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md`) : une facture fournisseur menée de la
 * pièce jointe à la commande. Le serveur calcule tout : l'état, le résumé de la lecture, les questions, l'aperçu et son empreinte. Une
 * réponse par bouton est gratuite et n'appelle aucune IA (aucune IA dans ce lot, hors la lecture du document). La confirmation passe par
 * le cas d'usage existant, l'annulation par `GestionDocumentUndoService`.
 *
 * États : `reading` (lecture en cours), `failed` (lecture en échec), `questions` (il manque une réponse, ou l'aperçu signale un refus),
 * `ready` (aperçu prêt à confirmer), `confirmed`, `abandoned`.
 */
class GestionConversationService
{
    public const STATE_READING = 'reading';
    public const STATE_FAILED = 'failed';
    public const STATE_QUESTIONS = 'questions';
    public const STATE_READY = 'ready';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_ABANDONED = 'abandoned';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionDocumentService $documents,
        private readonly InvoiceQuestions $questions,
        private readonly PreviewGestionDocumentUseCase $preview,
        private readonly ConfirmGestionDocumentUseCase $confirmation,
        private readonly GestionDocumentUndoService $undoService,
        private readonly LandingAiQuotaService $quota,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Fil d'un document : le fil ouvert s'il existe (un seul fil actif par document), sinon un nouveau.
     *
     * @return array{0: GestionConversation, 1: bool} le fil, et vrai s'il vient d'être créé
     */
    public function open(GestionDocument $document, ?string $text, string $locale, ?string $user): array
    {
        if ($document->getType() !== GestionDocument::TYPE_FACTURE) {
            throw new GestionConflictException('DOCUMENT_TYPE', 'La conversation ne mène que les factures fournisseurs.', 422, ['documentId' => $document->getId()]);
        }
        $em = $this->emProvider->getEntityManager();
        $existing = $em->getRepository(GestionConversation::class)->findOneBy(['document' => $document, 'status' => GestionConversation::STATUS_OPEN]);
        if ($existing !== null) {
            return [$existing, false];
        }
        $now = new \DateTimeImmutable();
        $conversation = (new GestionConversation())->setDocument($document)->setLocale($locale)->setCreatedBy($user)->setCreatedAt($now)->setUpdatedAt($now);
        $em->persist($conversation);
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'document', ['documentId' => $document->getId(), 'files' => count($document->getFiles()['pages'] ?? [])], $user));
        if ($text !== null && trim($text) !== '') {
            $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'text', ['text' => mb_substr(trim($text), 0, 2000)], $user));
        }
        $em->flush();

        return [$conversation, true];
    }

    public function find(int $id): GestionConversation
    {
        $conversation = $this->emProvider->getEntityManager()->find(GestionConversation::class, $id);
        if ($conversation === null) {
            throw new GestionConflictException('CONVERSATION_NOT_FOUND', 'Conversation introuvable.', 404, ['conversationId' => $id]);
        }

        return $conversation;
    }

    /**
     * Le fil tel que l'application l'affiche. Tout est recalculé : rien n'est écrit par cette lecture.
     *
     * @return array<string, mixed>
     */
    public function thread(GestionConversation $conversation, ?string $locale = null): array
    {
        $locale ??= $conversation->getLocale();
        $document = $conversation->getDocument();
        $this->documents->releaseStaleReadings(); // une lecture perdue doit apparaître en échec dans le fil, comme sur l'écran du document
        $state = $this->state($conversation);
        $built = null;
        $preview = null;
        $hash = null;
        if ($document !== null && in_array($document->getStatus(), [GestionDocument::STATUS_EXTRACTED, GestionDocument::STATUS_FAILED], true) && $document->getExtraction() !== null) {
            $built = $this->questions->build($document, $conversation->getAnswers(), $locale);
            if ($state !== self::STATE_ABANDONED && $built['body']['lines'] !== []) {
                $preview = $this->preview->execute($document, $built['body']);
                $hash = self::hash($built['body'], $preview);
            }
        }
        $open = $built === null ? 0 : count(array_filter($built['questions'], fn (array $q) => $q['required'] && $q['answer'] === null));
        if ($state === self::STATE_QUESTIONS && $built !== null && $open === 0 && $preview !== null && $preview['issues'] === []) {
            $state = self::STATE_READY;
        }
        $messages = $this->emProvider->getEntityManager()->getRepository(GestionConversationMessage::class)->findBy(['conversation' => $conversation], ['id' => 'ASC']);
        $undo = $state === self::STATE_CONFIRMED ? GestionDocumentUndoService::window($document) + ['blockers' => $this->undoService->blockers($document)] : null;

        return [
            'id' => $conversation->getId(), 'kind' => $conversation->getKind(), 'state' => $state, 'locale' => $conversation->getLocale(),
            'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updatedAt' => $conversation->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'document' => $document === null ? null : ['id' => $document->getId(), 'status' => $document->getStatus(), 'error' => $document->getErrorCode(), 'commandeId' => $document->getCommandeId()],
            'summary' => $document !== null && $document->getExtraction() !== null ? $this->summary($document, $open, $locale) : null,
            'messages' => array_map(fn (GestionConversationMessage $m) => ['id' => $m->getId(), 'role' => $m->getRole(), 'kind' => $m->getKind(), 'content' => $m->getContent() ?: new \stdClass(), 'createdAt' => $m->getCreatedAt()?->format(\DateTimeInterface::ATOM)], $messages),
            'questions' => $state === self::STATE_CONFIRMED || $built === null ? [] : $built['questions'],
            'openQuestions' => $state === self::STATE_CONFIRMED ? 0 : $open,
            'preview' => $state === self::STATE_CONFIRMED ? null : $preview,
            'previewHash' => $state === self::STATE_READY ? $hash : null,
            'undo' => $undo,
            'credits' => $this->quota->credits(),
        ];
    }

    /**
     * Réponses du gérant aux questions du serveur (boutons : gratuit, aucune IA). `value: null` retire une réponse. Répondre « abandon »
     * ferme le fil sans rien créer.
     *
     * @param list<mixed> $answers [{ questionId, value }]
     */
    public function answer(GestionConversation $conversation, array $answers, ?string $user): void
    {
        $document = $this->workable($conversation);
        $current = $conversation->getAnswers();
        $known = array_column($this->questions->build($document, $current, $conversation->getLocale())['questions'], null, 'id');
        $fields = [];
        $accepted = [];
        foreach (array_values($answers) as $i => $answer) {
            $id = is_array($answer) && is_string($answer['questionId'] ?? null) ? $answer['questionId'] : null;
            if ($id === null || !isset($known[$id])) {
                $fields["answers[$i].questionId"] = 'UNKNOWN_QUESTION';
                continue;
            }
            $accepted[$id] = $this->questions->accept($known[$id], $answer['value'] ?? null, $i);
        }
        if ($answers === [] || $fields !== []) {
            throw new GestionValidationException($fields ?: ['answers' => 'REQUIRED'], 'Réponses invalides');
        }
        foreach ($accepted as $id => $value) {
            if ($value === null) {
                unset($current[$id]);
            } else {
                $current[$id] = $value;
            }
        }
        $em = $this->emProvider->getEntityManager();
        $conversation->setAnswers($current)->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'answers', ['answers' => $accepted], $user));
        if (in_array(InvoiceQuestions::ABANDON, $accepted, true)) {
            $conversation->setStatus(GestionConversation::STATUS_ABANDONED);
            $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'abandoned', []));
        }
        $em->flush();
    }

    /**
     * Confirmation de l'aperçu : l'empreinte doit être celle de l'aperçu d'aujourd'hui, sinon 409 `PREVIEW_CHANGED` avec le nouvel aperçu.
     *
     * @return array<string, mixed> le résultat de la confirmation d'une facture, plus `undo`
     */
    public function confirm(GestionConversation $conversation, mixed $previewHash, string $host, ?string $user): array
    {
        $document = $this->workable($conversation);
        if (!is_string($previewHash) || $previewHash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        $thread = $this->thread($conversation);
        if ($thread['state'] !== self::STATE_READY) {
            throw new GestionConflictException('CONVERSATION_NOT_READY', 'Il reste des questions sans réponse ou un refus à lever avant de confirmer.', 409,
                ['state' => $thread['state'], 'openQuestions' => $thread['openQuestions'], 'issues' => $thread['preview']['issues'] ?? []]);
        }
        if (!hash_equals((string) $thread['previewHash'], $previewHash)) {
            throw new GestionConflictException('PREVIEW_CHANGED', 'L\'aperçu a changé depuis son affichage : vérifiez-le avant de confirmer.', 409, ['preview' => $thread['preview'], 'previewHash' => $thread['previewHash']]);
        }
        $body = $this->questions->build($document, $conversation->getAnswers(), $conversation->getLocale())['body'];
        $result = $this->confirmation->execute($document, $body, $host, $user);
        $em = $this->emProvider->getEntityManager();
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'confirmed', ['commandeId' => $document->getCommandeId(), 'previewHash' => $previewHash], $user));
        $em->flush();

        return $result + ['undo' => GestionDocumentUndoService::window($document)];
    }

    /** Annulation d'un bloc de la confirmation du fil ; le fil revient à l'aperçu */
    public function undo(GestionConversation $conversation, ?string $user): array
    {
        $document = $conversation->getDocument();
        if ($document === null) {
            throw new GestionConflictException('DOCUMENT_NOT_CONFIRMED', 'Ce fil n\'a rien confirmé : rien à annuler.');
        }
        $result = $this->undoService->undo($document);
        $em = $this->emProvider->getEntityManager();
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'undone', $result, $user));
        $em->flush();

        return $result;
    }

    /**
     * Liste légère des fils, du plus récent au plus ancien.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(int $page, int $limit): array
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionConversation::class);
        $rows = $repo->findBy([], ['id' => 'DESC'], $limit, ($page - 1) * $limit);

        return [array_map(fn (GestionConversation $c) => [
            'id' => $c->getId(), 'kind' => $c->getKind(), 'state' => $this->state($c),
            'document' => $c->getDocument() === null ? null : ['id' => $c->getDocument()->getId(), 'status' => $c->getDocument()->getStatus(), 'commandeId' => $c->getDocument()->getCommandeId()],
            'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updatedAt' => $c->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ], $rows), $repo->count([])];
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /** État d'après le fil et son document, sans regarder les questions (`questions` devient `ready` dans `thread()`) */
    private function state(GestionConversation $conversation): string
    {
        $document = $conversation->getDocument();
        if ($conversation->getStatus() === GestionConversation::STATUS_ABANDONED) {
            return self::STATE_ABANDONED; // un fil abandonné le reste, même si un autre fil a ensuite confirmé le même document
        }
        if ($document !== null && $document->isConfirmed()) {
            return self::STATE_CONFIRMED;
        }

        return match ($document?->getStatus()) {
            GestionDocument::STATUS_READING => self::STATE_READING,
            GestionDocument::STATUS_EXTRACTED => self::STATE_QUESTIONS,
            default => self::STATE_FAILED, // lecture en échec, document expiré ou disparu
        };
    }

    /** Document d'un fil sur lequel on peut encore répondre ou confirmer : lu, non confirmé, fil non abandonné */
    private function workable(GestionConversation $conversation): GestionDocument
    {
        $state = $this->state($conversation);
        $document = $conversation->getDocument();
        if ($document === null || $state !== self::STATE_QUESTIONS) {
            throw new GestionConflictException('CONVERSATION_NOT_OPEN', 'Ce fil n\'attend pas de réponse (lecture en cours ou en échec, facture déjà confirmée, ou fil abandonné).', 409, ['state' => $state]);
        }
        $this->documents->assertOpen($document);

        return $document;
    }

    /** Résumé de la lecture, écrit par le serveur à partir des chiffres lus (aucune IA, aucune unité) */
    private function summary(GestionDocument $document, int $open, string $locale): array
    {
        $x = $document->getExtraction() ?? [];
        $currency = strtoupper((string) ($x['invoice']['currency'] ?? $x['currency'] ?? ''));
        $total = $x['computed']['total'] ?? $x['totals']['total'] ?? null;
        $figures = [
            'supplier' => $x['supplier']['name'] ?? null, 'number' => $x['invoice']['number'] ?? null, 'date' => $x['invoice']['date'] ?? null,
            'lines' => count(is_array($x['lines'] ?? null) ? $x['lines'] : []), 'total' => $total, 'currency' => $currency !== '' ? $currency : null, 'openQuestions' => $open,
        ];
        $tr = fn (string $id, array $params = []) => $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
        $text = $tr('conversation.summary.read', [
            '%supplier%' => (string) ($figures['supplier'] ?? '?'), '%number%' => (string) ($figures['number'] ?? '?'), '%date%' => (string) ($figures['date'] ?? '?'), '%lines%' => (string) $figures['lines'],
            '%total%' => $total !== null ? number_format(((int) $total) / 100, 2, $locale === 'en' ? '.' : ',', ' ') : '?', '%currency%' => (string) $figures['currency'],
        ]) . ' ' . ($open > 0 ? $tr('conversation.summary.questions', ['%count%' => (string) $open]) : $tr('conversation.summary.ready'));

        return ['text' => $text] + $figures;
    }

    /** Empreinte de l'aperçu : le corps de la confirmation et ce que l'aperçu en a calculé */
    private static function hash(array $body, array $preview): string
    {
        return hash('sha256', (string) json_encode([$body, $preview['lines'], $preview['totals'], $preview['exchangeRate'], $preview['invoiceCurrency']]));
    }
}
