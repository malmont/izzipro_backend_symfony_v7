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
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
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
 *
 * Lot IA-ACT-1 : un fil peut aussi mener une action préparable, sans document (`kind` = la clé de l'action). Ce qui lui est propre
 * (ouverture, fil, confirmation, annulation) est dans `ActionThreadService` ; les réponses par bouton et la phrase libre sont communes.
 */
class GestionConversationService
{
    public const STATE_READING = 'reading';
    public const STATE_FAILED = 'failed';
    public const STATE_QUESTIONS = 'questions';
    public const STATE_READY = 'ready';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_ABANDONED = 'abandoned';
    /** Type des échanges de la console IA (lot W2), rangés dans les mêmes tables mais hors des routes du fil */
    public const KIND_CONSOLE = 'console';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionDocumentService $documents,
        private readonly InvoiceQuestions $questions,
        private readonly PreviewGestionDocumentUseCase $preview,
        private readonly ConfirmGestionDocumentUseCase $confirmation,
        private readonly GestionDocumentUndoService $undoService,
        private readonly LandingAiQuotaService $quota,
        private readonly TranslatorInterface $translator,
        private readonly ActionThreadService $actions, // lot IA-ACT-1 : les fils d'action
        private readonly ?ConversationInterpreter $interpreter = null, // lot IA-FACT-2-B : la phrase libre
        private readonly ?\App\Services\CaisseService\StoreClock $clock = null,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    /** Messages envoyés à l'IA par jour (jour du magasin) et par site : garde-fou contre une boucle ; réglable ici */
    public const AI_MESSAGES_PER_DAY = 100;

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
        if ($conversation === null || $conversation->getKind() === self::KIND_CONSOLE) { // un échange de la console IA (lot W2) n'est pas un fil
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
        if ($this->actions->handles($conversation)) {
            return $this->actions->thread($conversation, $locale);
        }
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
            // `periodId` (demande du web, 13/11/2026) : la période de la commande, pour ouvrir la commande depuis un fil rouvert plus tard ; même valeur que la liste des articles sans coût
            'document' => $document === null ? null : ['id' => $document->getId(), 'status' => $document->getStatus(), 'error' => $document->getErrorCode(), 'commandeId' => $document->getCommandeId(), 'periodId' => $this->periodOf($document->getCommandeId())],
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
        [$build, $accept] = $this->driver($conversation);
        $current = $conversation->getAnswers();
        $fields = [];
        $accepted = [];
        // Les réponses s'appliquent dans l'ordre : chacune est contrôlée sur les questions telles que les précédentes les ont laissées
        // (répondre la cible fait naître la question du frais de port, que la réponse suivante du même envoi peut renseigner)
        foreach (array_values($answers) as $i => $answer) {
            $known = array_column($build($current), null, 'id');
            $id = is_array($answer) && is_string($answer['questionId'] ?? null) ? $answer['questionId'] : null;
            if ($id === null || !isset($known[$id])) {
                $fields["answers[$i].questionId"] = 'UNKNOWN_QUESTION';
                continue;
            }
            $accepted[$id] = $accept($known[$id], $answer['value'] ?? null, $i, $current);
            if ($accepted[$id] === null) {
                unset($current[$id]);
            } else {
                $current[$id] = $accepted[$id];
            }
        }
        if ($answers === [] || $fields !== []) {
            throw new GestionValidationException($fields ?: ['answers' => 'REQUIRED'], 'Réponses invalides');
        }
        $em = $this->emProvider->getEntityManager();
        $conversation->setAnswers($current)->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'answers', ['answers' => $accepted], $user));
        if (!$this->actions->handles($conversation) && in_array(InvoiceQuestions::ABANDON, $accepted, true)) { // un fil d'action s'abandonne par sa route
            $conversation->setStatus(GestionConversation::STATUS_ABANDONED);
            $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'abandoned', []));
        }
        $em->flush();
    }

    /**
     * Phrase libre du gérant (lot IA-FACT-2-B) : l'IA la traduit en réponses aux questions du fil, chacune contrôlée comme un bouton ;
     * 1 unité par réponse de l'IA, aucune si elle échoue. L'IA ne confirme et n'annule jamais.
     *
     * @return array{reply: string, applied: list<array{questionId: string, value: mixed}>, ignored: list<array{questionId: string, reason: string}>}
     *
     * @throws LandingAiException 402 crédits, 403 palier, 503 IA indisponible
     */
    public function message(GestionConversation $conversation, mixed $text, ?string $user, bool $platformOwner): array
    {
        [$build, $accept, $document, $action] = $this->driver($conversation);
        $text = is_string($text) ? trim($text) : '';
        if ($text === '' || mb_strlen($text) > ConversationInterpreter::MAX_TEXT) {
            throw new GestionValidationException(['text' => $text === '' ? 'REQUIRED' : 'TOO_LONG'], sprintf('text : une phrase de %d caractères au plus', ConversationInterpreter::MAX_TEXT));
        }
        $this->documents->assertAiIncluded($platformOwner);
        $em = $this->emProvider->getEntityManager();
        // Plafond du jour (jour du magasin), par site
        [$dayStart, $dayEnd] = $this->clock->range(null, null);
        $sent = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE role = 'assistant' AND kind IN ('text', 'answer') AND created_at >= ? AND created_at < ?", [$dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s')]);
        if ($sent >= self::AI_MESSAGES_PER_DAY) {
            throw new GestionConflictException('AI_DAILY_LIMIT', sprintf('%d messages à l\'assistant par jour au plus : répondez par les boutons, ou reprenez demain.', self::AI_MESSAGES_PER_DAY), 429,
                ['limit' => self::AI_MESSAGES_PER_DAY, 'resetAt' => $dayEnd->format(\DateTimeInterface::ATOM)]);
        }
        $locale = $conversation->getLocale();
        $x = $document?->getExtraction() ?? [];
        $names = array_values(array_filter(array_merge([(string) ($x['supplier']['name'] ?? '')], array_map(fn ($l) => (string) ($l['label'] ?? ''), is_array($x['lines'] ?? null) ? $x['lines'] : []),
            array_map(fn ($l) => (string) ($l['baseLabel'] ?? ''), is_array($x['lines'] ?? null) ? $x['lines'] : [])), fn (string $n) => $n !== ''));
        $stats = new LandingAiUsageStats(ConversationInterpreter::MODEL);
        $usage = $platformOwner ? null : $this->quota->reserve('assistant', 'gestion_conversation', ConversationInterpreter::COST, $user, mb_substr($text, 0, 200));
        try {
            // fil d'action : un choix n'est gardé que si la phrase nomme l'option (aucune valeur qui ne soit dans la phrase)
            $read = $this->interpreter->interpret($text, $build($conversation->getAnswers()), $names, (new \DateTimeImmutable('now', $this->clock->timezone()))->setTime(0, 0), $locale, $stats,
                $action === null ? null : $this->translator->trans('conversation.actions.' . str_replace('.', '_', $action::key()) . '.title', [], 'gestion', 'fr'), $action !== null);
            if ($usage !== null) {
                $this->quota->complete($usage, $stats, mb_substr($read['reply'], 0, 200));
            }
        } catch (\Throwable $e) {
            if ($usage !== null) {
                $this->quota->release($usage, $stats);
            }
            $this->logger?->error('[Gestion] phrase libre du fil ' . $conversation->getId() . ' : ' . $e->getMessage());
            throw $e instanceof LandingAiException ? $e : new LandingAiException(503, 'Assistant indisponible', 'L\'assistant ne répond pas pour le moment : répondez par les boutons, ou réessayez dans un instant.');
        }
        // Chaque réponse proposée repasse par le contrôle d'un bouton, dans l'ordre ; une réponse refusée est ignorée et dite
        $current = $conversation->getAnswers();
        $applied = [];
        $ignored = $read['ignored'];
        foreach ($read['answers'] as $i => $answer) {
            $known = array_column($build($current), null, 'id');
            if (!isset($known[$answer['questionId']])) {
                $ignored[] = ['questionId' => $answer['questionId'], 'reason' => 'UNKNOWN_QUESTION'];
                continue;
            }
            try {
                $value = $accept($known[$answer['questionId']], $answer['value'], $i, $current);
            } catch (GestionValidationException $e) {
                $ignored[] = ['questionId' => $answer['questionId'], 'reason' => (string) (array_values($e->fields)[0] ?? 'INVALID_VALUE')];
                continue;
            }
            if ($action === null && $value === InvoiceQuestions::ABANDON) {
                $ignored[] = ['questionId' => $answer['questionId'], 'reason' => 'ABANDON_BY_BUTTON_ONLY']; // abandonner reste un geste du gérant
                continue;
            }
            $current[$answer['questionId']] = $value;
            $applied[] = ['questionId' => $answer['questionId'], 'value' => $value];
        }
        $reply = $read['reply'] !== '' ? $read['reply'] : $this->translator->trans($applied === [] ? 'conversation.reply.nothing' : 'conversation.reply.applied', ['%count%' => (string) count($applied)], 'gestion', $locale === 'en' ? 'en' : 'fr');
        $conversation->setAnswers($current)->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'text', ['text' => $text], $user));
        if ($applied !== []) {
            $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'answers', ['answers' => array_column($applied, 'value', 'questionId'), 'source' => 'ai']));
        }
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'text', ['text' => $reply, 'applied' => array_column($applied, 'questionId'), 'ignored' => $ignored]));
        $em->flush();

        return ['reply' => $reply, 'applied' => $applied, 'ignored' => $ignored];
    }

    /**
     * Confirmation de l'aperçu : l'empreinte doit être celle de l'aperçu d'aujourd'hui, sinon 409 `PREVIEW_CHANGED` avec le nouvel aperçu.
     *
     * @return array<string, mixed> le résultat de la confirmation d'une facture, plus `undo`
     */
    public function confirm(GestionConversation $conversation, mixed $previewHash, string $host, ?string $user): array
    {
        if ($this->actions->handles($conversation)) {
            return $this->actions->confirm($conversation, $previewHash, $user);
        }
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
        if ($this->actions->handles($conversation)) {
            return $this->actions->undo($conversation, $user);
        }
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
     * Abandon d'un fil par le gérant (lot IA-ACT-1) : rien n'est créé, rien n'est à défaire. 409 `CONVERSATION_NOT_OPEN` si le fil est
     * déjà confirmé ou abandonné.
     */
    public function abandon(GestionConversation $conversation, ?string $user): void
    {
        if ($this->actions->handles($conversation)) {
            $this->actions->abandon($conversation, $user);

            return;
        }
        $state = $this->state($conversation);
        if (in_array($state, [self::STATE_CONFIRMED, self::STATE_ABANDONED], true)) {
            throw new GestionConflictException('CONVERSATION_NOT_OPEN', 'Ce fil est déjà confirmé ou abandonné.', 409, ['state' => $state]);
        }
        $em = $this->emProvider->getEntityManager();
        $conversation->setStatus(GestionConversation::STATUS_ABANDONED)->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'abandoned', [], $user));
        $em->flush();
    }

    /**
     * Liste légère des fils, du plus récent au plus ancien ; `kind` : `invoice` ou la clé d'une action.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(int $page, int $limit, ?string $kind = null): array
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionConversation::class);
        if ($kind === self::KIND_CONSOLE) {
            return [[], 0]; // les échanges de la console IA (lot W2) se lisent par leurs routes, jamais ici
        }
        $query = $repo->createQueryBuilder('c')->where('c.kind <> :console')->setParameter('console', self::KIND_CONSOLE);
        if ($kind !== null) {
            $query->andWhere('c.kind = :kind')->setParameter('kind', $kind);
        }
        $total = (int) (clone $query)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->orderBy('c.id', 'DESC')->setMaxResults($limit)->setFirstResult(($page - 1) * $limit)->getQuery()->getResult();

        return [array_map(function (GestionConversation $c): array {
            // `undo` (demande du web, 13/11/2026) : la fenêtre d'annulation d'une confirmation encore en vigueur, sans ses blocages (au détail du fil)
            [$state, $undo] = $this->actions->handles($c) ? $this->actions->listed($c)
                : [$state = $this->state($c), $state === self::STATE_CONFIRMED && $c->getDocument() !== null ? GestionDocumentUndoService::window($c->getDocument()) : null];

            return [
                'id' => $c->getId(), 'kind' => $c->getKind(), 'state' => $state,
                'document' => $c->getDocument() === null ? null : ['id' => $c->getDocument()->getId(), 'status' => $c->getDocument()->getStatus(), 'commandeId' => $c->getDocument()->getCommandeId()],
                'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updatedAt' => $c->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'undo' => $undo,
            ];
        }, $rows), $total];
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /**
     * Ce qui diffère entre un fil de facture et un fil d'action pour répondre : les questions d'après des réponses, et le contrôle d'une
     * réponse. Refuse (409 `CONVERSATION_NOT_OPEN`) un fil qui n'attend plus de réponse.
     *
     * @return array{0: \Closure(array<string, mixed>): list<array<string, mixed>>, 1: \Closure(array<string, mixed>, mixed, int, array<string, mixed>): mixed, 2: ?GestionDocument, 3: ?Action\GestionAction}
     */
    private function driver(GestionConversation $conversation): array
    {
        $locale = $conversation->getLocale();
        if ($this->actions->handles($conversation)) {
            $action = $this->actions->workable($conversation);

            return [fn (array $answers) => $action->build($answers, $locale)['questions'], fn (array $question, mixed $value, int $i, array $answers) => $action->accept($question, $value, $i, $answers), null, $action];
        }
        $document = $this->workable($conversation);

        return [fn (array $answers) => $this->questions->build($document, $answers, $locale)['questions'], fn (array $question, mixed $value, int $i, array $answers) => $this->questions->accept($question, $value, $i), $document, null];
    }

    /** Période (collection) d'une commande, ou null : lecture seule */
    private function periodOf(?int $commandeId): ?int
    {
        $id = $commandeId === null ? null : $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT collections_id FROM commande WHERE id = ?', [$commandeId]);

        return $id === null || $id === false ? null : (int) $id;
    }

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
            '%count%' => (string) $figures['lines'], // accord du libellé : « 1 ligne », « 2 lignes »
            '%total%' => $total !== null ? number_format(((int) $total) / 100, 2, $locale === 'en' ? '.' : ',', ' ') : '?', '%currency%' => (string) $figures['currency'],
        ]) . ' ' . ($open > 0 ? $tr('conversation.summary.questions', ['%count%' => (string) $open]) : $tr('conversation.summary.ready'));

        return ['text' => $text] + $figures;
    }

    /** Empreinte de l'aperçu : le corps de la confirmation et ce que l'aperçu en a calculé */
    private static function hash(array $body, array $preview): string
    {
        return hash('sha256', (string) json_encode([$body, $preview['lines'], $preview['totals'], $preview['exchangeRate'], $preview['invoiceCurrency'], $preview['target'] ?? null]));
    }
}
