<?php

namespace App\Gestion\Services\Console;

use App\Entity\AiUsage;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Entity\GestionConversation;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Message\AnswerConsoleQuestionMessage;
use App\Gestion\Services\Conversation\GestionConversationService;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Console IA générale de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md`) : les échanges. Une question est gardée tout de
 * suite avec sa réponse « en préparation » ; le worker `gestion_ai` répond (`ConsoleResponder`) ; l'application relit l'échange.
 * Les échanges vivent dans les tables du fil de la gestion, sous le type `console` : pas de table de plus. Ils ne sont jamais rendus par
 * les routes du fil (`/api/gestion/conversations`), qui ne connaissent que les factures et les actions.
 *
 * Messages : la question (`question`, `{ text, context }`) puis sa réponse (`answer`, `{ state, replyTo, error, blocks, analyses,
 * proposals, answeredAt }`). Une réponse à la fois par échange. 1 unité par réponse rédigée par le modèle, réservée à la question et
 * rendue si l'IA échoue, si le délai est dépassé, ou si le serveur répond lui-même.
 */
class ConsoleService
{
    public const KIND = 'console';
    public const QUESTION = 'question';
    public const ANSWER = 'answer';
    public const STATE_PENDING = 'pending';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';
    public const MAX_TEXT = 1000;
    public const TITLE_LENGTH = 80;
    /** Délai maximal d'une réponse : au-delà, elle passe en échec (`CONSOLE_TIMEOUT`), sans unité */
    public const TIMEOUT_SECONDS = 120;
    public const HISTORY_MONTHS = 12;
    public const COST = 1;
    /** Messages de l'échange donnés au modèle pour choisir (texte seul) */
    public const HISTORY_MESSAGES = 6;

    /** Écrans de la gestion d'où une question peut venir ; un écran inconnu est ignoré, sans erreur */
    public const SCREENS = ['accueil', 'journee', 'statistiques', 'statistiques.annee', 'periodes', 'periode', 'periode.resultat', 'periode.budget', 'periode.preparation', 'commandes', 'commande',
        'articles', 'article', 'stock', 'tresorerie', 'tresorerie.rapprochements', 'frais-fixes', 'conseils', 'documents', 'prix', 'promotions', 'publicite', 'voyages', 'simulateur', 'console'];
    /** Paramètres d'écran gardés : des identifiants existants du site, ou une année */
    private const CONTEXT_PARAMS = ['periodId' => "SELECT 1 FROM collections WHERE id = ? AND del = false", 'productId' => 'SELECT 1 FROM product WHERE id = ?', 'commandeId' => 'SELECT 1 FROM commande WHERE id = ?'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly LandingAiQuotaService $quota,
        private readonly MessageBusInterface $bus,
        private readonly AnalysisCatalog $analyses,
        private readonly ContentAuditRecorder $audit,
        private readonly StoreClock $clock,
        private readonly GestionDocumentService $documents,
    ) {
    }

    /**
     * POST /api/gestion/console/messages : pose une question. 422 `text` (`REQUIRED`, `TOO_LONG`), `conversationId` (`INVALID_ID`) ;
     * 404 `CONSOLE_CONVERSATION_NOT_FOUND` ; 409 `CONSOLE_ANSWER_PENDING` ; 429 `AI_DAILY_LIMIT` ; palier et crédits : `LandingAiException`.
     *
     * @return array<string, mixed>
     */
    public function ask(mixed $text, mixed $conversationId, mixed $context, string $locale, ?string $user, bool $platformOwner): array
    {
        $text = is_string($text) ? trim($text) : '';
        $fields = [];
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            $fields['text'] = $text === '' ? 'REQUIRED' : 'TOO_LONG';
        }
        if ($conversationId !== null && (!is_int($conversationId) || $conversationId <= 0)) {
            $fields['conversationId'] = 'INVALID_ID';
        }
        if ($fields !== []) {
            throw new GestionValidationException($fields, 'Question invalide : ' . implode(', ', array_keys($fields)));
        }
        $this->documents->assertAiIncluded($platformOwner); // 403 : palier d'IA du site
        $this->releaseStale();
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conversation = $conversationId === null ? null : $this->find($conversationId);
        if ($conversation !== null && ($pending = $this->pendingAnswer((int) $conversation->getId())) !== null) {
            throw new GestionConflictException('CONSOLE_ANSWER_PENDING', 'La réponse précédente de cet échange est encore en préparation.', 409, ['messageId' => $pending]);
        }
        // Plafond du jour (jour du magasin), commun avec la phrase libre du fil
        [$dayStart, $dayEnd] = $this->clock->range(null, null);
        $sent = (int) $conn->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE role = 'assistant' AND kind IN ('text', 'answer') AND created_at >= ? AND created_at < ?", [$dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s')]);
        if ($sent >= GestionConversationService::AI_MESSAGES_PER_DAY) {
            throw new GestionConflictException('AI_DAILY_LIMIT', sprintf('%d messages à l\'assistant par jour au plus : reprenez demain.', GestionConversationService::AI_MESSAGES_PER_DAY), 429,
                ['limit' => GestionConversationService::AI_MESSAGES_PER_DAY, 'resetAt' => $dayEnd->format(\DateTimeInterface::ATOM)]);
        }
        // 1 unité réservée (402 s'il n'en reste plus) ; le propriétaire de la plateforme n'est pas décompté
        $usage = $platformOwner ? null : $this->quota->reserve('assistant', 'gestion_console', self::COST, $user, mb_substr($text, 0, 200), self::TIMEOUT_SECONDS + 60);
        // Historique gardé 12 mois : les échanges plus anciens sont retirés quand une question arrive
        $conn->executeStatement("DELETE FROM gestion_conversation WHERE kind = ? AND updated_at < NOW() - INTERVAL '" . self::HISTORY_MONTHS . " months'", [self::KIND]);

        $now = new \DateTimeImmutable();
        if ($conversation === null) {
            $conversation = (new GestionConversation())->setKind(self::KIND)->setLocale($locale)->setCreatedBy($user)->setCreatedAt($now)->setAnswers(['title' => mb_substr($text, 0, self::TITLE_LENGTH)]);
            $em->persist($conversation);
        }
        $conversation->setUpdatedAt($now);
        $question = GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, self::QUESTION, ['text' => $text, 'context' => $this->context($context)], $user);
        $em->persist($question);
        $em->flush();
        $answer = GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, self::ANSWER,
            ['state' => self::STATE_PENDING, 'replyTo' => (int) $question->getId(), 'error' => null, 'blocks' => [], 'analyses' => [], 'proposals' => [], 'answeredAt' => null, 'usageId' => $usage?->getId()]);
        $em->persist($answer);
        $em->flush();
        $this->bus->dispatch(new AnswerConsoleQuestionMessage((int) $answer->getId(), (string) $this->tenants->getCurrentTenantCode()));

        return ['conversationId' => (int) $conversation->getId(), 'questionMessageId' => (int) $question->getId(), 'messageId' => (int) $answer->getId(), 'state' => self::STATE_PENDING,
            'blocks' => [], 'analyses' => [], 'proposals' => [], 'credits' => $this->quota->credits()];
    }

    /**
     * GET /api/gestion/console/conversations/{id} : l'échange entier, dans l'ordre.
     *
     * @return array<string, mixed>
     */
    public function conversation(int $id): array
    {
        $this->releaseStale();
        $conversation = $this->find($id);
        $messages = $this->emProvider->getEntityManager()->getRepository(GestionConversationMessage::class)->findBy(['conversation' => $conversation], ['id' => 'ASC']);
        $rendered = array_map(fn (GestionConversationMessage $m) => self::message($m), $messages);

        return ['id' => (int) $conversation->getId(), 'title' => (string) ($conversation->getAnswers()['title'] ?? ''),
            'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updatedAt' => $conversation->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'pending' => array_filter($rendered, fn (array $m) => ($m['state'] ?? null) === self::STATE_PENDING) !== [],
            'messages' => $rendered, 'credits' => $this->quota->credits()];
    }

    /**
     * GET /api/gestion/console/conversations : l'historique du site, du plus récemment modifié au plus ancien.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(int $page, int $limit): array
    {
        $this->releaseStale();
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT c.id, c.answers, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM gestion_conversation_message m WHERE m.conversation_id = c.id AND m.kind = 'question') AS questions,
                    (SELECT m.content FROM gestion_conversation_message m WHERE m.conversation_id = c.id AND m.kind = 'answer' ORDER BY m.id DESC LIMIT 1) AS last
             FROM gestion_conversation c WHERE c.kind = :kind ORDER BY c.updated_at DESC, c.id DESC LIMIT " . $limit . ' OFFSET ' . (($page - 1) * $limit),
            ['kind' => self::KIND]
        );
        $date = fn (?string $v): ?string => $v === null ? null : (new \DateTimeImmutable($v))->format(\DateTimeInterface::ATOM);

        return [array_map(function (array $r) use ($date): array {
            $state = json_decode((string) ($r['last'] ?? ''), true)['state'] ?? null;

            return ['id' => (int) $r['id'], 'title' => (string) (json_decode((string) ($r['answers'] ?? ''), true)['title'] ?? ''), 'createdAt' => $date($r['created_at']), 'updatedAt' => $date($r['updated_at']),
                'questions' => (int) $r['questions'], 'pending' => $state === self::STATE_PENDING, 'lastState' => $state];
        }, $rows), (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_conversation WHERE kind = ?', [self::KIND])];
    }

    /** DELETE /api/gestion/console/conversations/{id} : le gérant efface un échange ; inscrit au journal. 409 si une réponse est en préparation. */
    public function delete(int $id): void
    {
        $this->releaseStale();
        $conversation = $this->find($id);
        if (($pending = $this->pendingAnswer($id)) !== null) {
            throw new GestionConflictException('CONSOLE_ANSWER_PENDING', 'Une réponse de cet échange est encore en préparation : attendez-la avant d\'effacer.', 409, ['messageId' => $pending]);
        }
        $em = $this->emProvider->getEntityManager();
        $questions = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ? AND kind = 'question'", [$id]);
        $before = ['title' => (string) ($conversation->getAnswers()['title'] ?? ''), 'questions' => $questions, 'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM)];
        $em->remove($conversation); // ses messages suivent (suppression en cascade)
        $em->flush();
        $this->audit->record('gestion-console', $id, 'delete', $before, null);
    }

    /** @return list<array<string, mixed>> GET /api/gestion/console/analyses : le catalogue */
    public function catalog(string $locale): array
    {
        return $this->analyses->listed($locale);
    }

    /**
     * Écran d'où vient la question, contrôlé : module `gestion`, écran connu, paramètres qui sont des identifiants existants du site
     * (ou une année). Tout le reste est écarté ; un contexte inconnu ou invalide vaut null, sans erreur.
     *
     * @return array{module: string, screen: string, params: array<string, int>}|null
     */
    public function context(mixed $context): ?array
    {
        if (!is_array($context) || ($context['module'] ?? null) !== 'gestion' || !is_string($context['screen'] ?? null) || !in_array($context['screen'], self::SCREENS, true)) {
            return null;
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $params = [];
        foreach (is_array($context['params'] ?? null) ? $context['params'] : [] as $name => $value) {
            if (isset(self::CONTEXT_PARAMS[$name]) && is_int($value) && $conn->fetchOne(self::CONTEXT_PARAMS[$name], [$value]) !== false) {
                $params[$name] = $value;
            } elseif ($name === 'year' && is_int($value) && $value >= 2000 && $value <= 2100) {
                $params[$name] = $value;
            }
        }

        return ['module' => 'gestion', 'screen' => $context['screen'], 'params' => $params];
    }

    /**
     * Une réponse restée « en préparation » au-delà du délai passe en échec, et son unité est rendue. Appelé à chaque lecture, comme
     * pour une lecture de document perdue : l'application ne reste jamais sur « en cours ».
     */
    public function releaseStale(): void
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT m.id, m.content FROM gestion_conversation_message m JOIN gestion_conversation c ON c.id = m.conversation_id
             WHERE c.kind = ? AND m.kind = 'answer' AND m.content::jsonb ->> 'state' = 'pending' AND m.created_at < NOW() - INTERVAL '" . self::TIMEOUT_SECONDS . " seconds'", [self::KIND]);
        foreach ($rows as $r) {
            $content = json_decode((string) $r['content'], true) ?: [];
            $this->finish((int) $r['id'], $content, self::STATE_FAILED, ['error' => ['code' => 'CONSOLE_TIMEOUT', 'message' => 'La console n\'a pas répondu à temps. Reposez votre question.']], false);
        }
    }

    /**
     * Écrit l'état final d'une réponse et règle son unité : décomptée (`charge`) ou rendue.
     *
     * @param array<string, mixed> $content contenu actuel du message
     * @param array<string, mixed> $changes blocs, analyses, propositions, erreur
     */
    public function finish(int $messageId, array $content, string $state, array $changes, bool $charge, ?\App\Services\LandingAiService\LandingAiUsageStats $stats = null, ?string $summary = null): void
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $usage = ($content['usageId'] ?? null) !== null ? $em->find(AiUsage::class, (int) $content['usageId']) : null;
        if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
            $charge ? $this->quota->complete($usage, $stats ?? new \App\Services\LandingAiService\LandingAiUsageStats(ConsoleModel::MODEL_WRITE), $summary) : $this->quota->release($usage, $stats);
        }
        $content = array_merge($content, $changes, ['state' => $state, 'answeredAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
        // seulement si la réponse est encore en préparation : un délai dépassé puis une réponse tardive ne s'écrasent pas
        $conn->executeStatement("UPDATE gestion_conversation_message SET content = ? WHERE id = ? AND content::jsonb ->> 'state' = 'pending'", [json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $messageId]);
        $conn->executeStatement('UPDATE gestion_conversation SET updated_at = NOW() WHERE id = (SELECT conversation_id FROM gestion_conversation_message WHERE id = ?)', [$messageId]);
    }

    private function find(int $id): GestionConversation
    {
        $conversation = $this->emProvider->getEntityManager()->getRepository(GestionConversation::class)->findOneBy(['id' => $id, 'kind' => self::KIND]);
        if ($conversation === null) {
            throw new GestionConflictException('CONSOLE_CONVERSATION_NOT_FOUND', 'Échange introuvable.', 404, ['conversationId' => $id]);
        }

        return $conversation;
    }

    private function pendingAnswer(int $conversationId): ?int
    {
        $id = $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            "SELECT id FROM gestion_conversation_message WHERE conversation_id = ? AND kind = 'answer' AND content::jsonb ->> 'state' = 'pending' ORDER BY id DESC LIMIT 1", [$conversationId]);

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, mixed> un message tel que l'application le lit */
    public static function message(GestionConversationMessage $m): array
    {
        $c = $m->getContent();
        $at = $m->getCreatedAt()?->format(\DateTimeInterface::ATOM);
        if ($m->getKind() === self::QUESTION) {
            return ['messageId' => (int) $m->getId(), 'role' => 'user', 'text' => (string) ($c['text'] ?? ''), 'context' => $c['context'] ?? null, 'createdAt' => $at];
        }

        return ['messageId' => (int) $m->getId(), 'role' => 'assistant', 'replyTo' => $c['replyTo'] ?? null, 'state' => $c['state'] ?? self::STATE_FAILED, 'error' => $c['error'] ?? null,
            'blocks' => $c['blocks'] ?? [], 'analyses' => $c['analyses'] ?? [], 'proposals' => $c['proposals'] ?? [], 'createdAt' => $at, 'answeredAt' => $c['answeredAt'] ?? null];
    }
}
