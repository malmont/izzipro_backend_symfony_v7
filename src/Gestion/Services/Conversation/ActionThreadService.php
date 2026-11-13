<?php

namespace App\Gestion\Services\Conversation;

use App\Gestion\Entity\GestionConversation;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Services\Conversation\Action\ActionCatalog;
use App\Gestion\Services\Conversation\Action\GestionAction;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fil d'une action préparable de la gestion (lot IA-ACT-1, 13/11/2026, `docs/conversation-gestion.md` § 9) : un fil SANS document, dont
 * `kind` est la clé de l'action (`expense.create`…). Même contrat que le fil d'une facture : le serveur pose une question par paramètre,
 * calcule l'aperçu et son empreinte, n'écrit qu'à la confirmation (par le service de la route existante) et sait défaire ce qu'il a créé.
 * Aucune table de plus : ce que la confirmation a créé est noté dans son message `confirmed`, d'où part l'annulation.
 *
 * États : `questions` (une réponse manque, ou un refus est à lever), `ready`, `confirmed`, `abandoned`.
 */
class ActionThreadService
{
    public const UNDO_HOURS = 24;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ActionCatalog $catalog,
        private readonly LandingAiQuotaService $quota,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Ouvre le fil d'une action du catalogue (liste blanche). Les paramètres donnés sont contrôlés comme des réponses par bouton et rendus
     * déjà répondus.
     *
     * @throws GestionValidationException 422 action inconnue (`action: UNKNOWN_ACTION`), paramètre inconnu ou refusé (`params.<id>`)
     */
    public function open(mixed $key, mixed $params, ?string $text, string $locale, ?string $user): GestionConversation
    {
        if (!is_string($key) || !$this->catalog->has($key)) {
            throw new GestionValidationException(['action' => 'UNKNOWN_ACTION'], 'Action inconnue : ' . implode(', ', $this->catalog->keys()));
        }
        if ($params !== null && !is_array($params)) {
            throw new GestionValidationException(['params' => 'MUST_BE_OBJECT'], 'params : objet { paramètre: valeur } attendu');
        }
        $action = $this->catalog->get($key);
        $answers = [];
        $fields = [];
        foreach ($params ?? [] as $id => $value) {
            $known = array_column($action->build($answers, $locale)['questions'], null, 'id');
            if (!isset($known[$id])) {
                $fields["params.$id"] = 'UNKNOWN_PARAM';
                continue;
            }
            try {
                $accepted = $action->accept($known[$id], $value, 0, $answers);
            } catch (GestionValidationException $e) {
                $fields["params.$id"] = (string) (array_values($e->fields)[0] ?? 'INVALID_VALUE');
                continue;
            }
            if ($accepted !== null) {
                $answers[(string) $id] = $accepted;
            }
        }
        if ($fields !== []) {
            throw new GestionValidationException($fields, 'Paramètres invalides');
        }
        $em = $this->emProvider->getEntityManager();
        $now = new \DateTimeImmutable();
        $conversation = (new GestionConversation())->setKind($key)->setLocale($locale)->setCreatedBy($user)->setCreatedAt($now)->setUpdatedAt($now)->setAnswers($answers);
        $em->persist($conversation);
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'action', ['action' => $key, 'params' => $answers ?: new \stdClass()], $user));
        if ($text !== null && trim($text) !== '') {
            $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, 'text', ['text' => mb_substr(trim($text), 0, 2000)], $user));
        }
        $em->flush();

        return $conversation;
    }

    public function handles(GestionConversation $conversation): bool
    {
        return $conversation->getKind() !== GestionConversation::KIND_INVOICE;
    }

    /** L'action d'un fil ; 409 si elle n'est plus au catalogue (un fil ancien dont l'action a été retirée) */
    public function action(GestionConversation $conversation): GestionAction
    {
        if (!$this->catalog->has($conversation->getKind())) {
            throw new GestionConflictException('ACTION_UNAVAILABLE', 'Cette action n\'est plus disponible.', 409, ['action' => $conversation->getKind()]);
        }

        return $this->catalog->get($conversation->getKind());
    }

    /** L'action d'un fil qui attend encore une réponse ou une confirmation ; 409 `CONVERSATION_NOT_OPEN` sinon */
    public function workable(GestionConversation $conversation): GestionAction
    {
        $state = $this->state($conversation);
        if ($state !== GestionConversationService::STATE_QUESTIONS) {
            throw new GestionConflictException('CONVERSATION_NOT_OPEN', 'Ce fil n\'attend pas de réponse (action déjà confirmée, ou fil abandonné).', 409, ['state' => $state]);
        }

        return $this->action($conversation);
    }

    /** État sans regarder les questions (`questions` devient `ready` dans `thread()`) */
    public function state(GestionConversation $conversation): string
    {
        if ($conversation->getStatus() === GestionConversation::STATUS_ABANDONED) {
            return GestionConversationService::STATE_ABANDONED;
        }

        return $this->confirmation($conversation) !== null ? GestionConversationService::STATE_CONFIRMED : GestionConversationService::STATE_QUESTIONS;
    }

    /**
     * Pour la liste des fils : l'état sans regarder les questions, et la fenêtre d'annulation de la confirmation en vigueur (sans les
     * blocages, constatés au détail du fil).
     *
     * @return array{0: string, 1: ?array{available: bool, until: string}}
     */
    public function listed(GestionConversation $conversation): array
    {
        if ($conversation->getStatus() === GestionConversation::STATUS_ABANDONED) {
            return [GestionConversationService::STATE_ABANDONED, null];
        }
        $confirmed = $this->confirmation($conversation);

        return $confirmed === null ? [GestionConversationService::STATE_QUESTIONS, null] : [GestionConversationService::STATE_CONFIRMED, self::window($confirmed)];
    }

    /**
     * Le fil tel que l'application l'affiche ; tout est recalculé, rien n'est écrit.
     *
     * @return array<string, mixed>
     */
    public function thread(GestionConversation $conversation, ?string $locale = null): array
    {
        $locale ??= $conversation->getLocale();
        $state = $this->state($conversation);
        $available = $this->catalog->has($conversation->getKind());
        $built = ['questions' => [], 'issues' => []];
        $preview = null;
        $hash = null;
        $open = 0;
        if ($available && $state === GestionConversationService::STATE_QUESTIONS) {
            $action = $this->catalog->get($conversation->getKind());
            $built = $action->build($conversation->getAnswers(), $locale);
            $open = count(array_filter($built['questions'], fn (array $q) => $q['required'] && $q['answer'] === null));
            if ($open === 0 && $built['issues'] === []) {
                $preview = $action->preview($conversation->getAnswers(), $locale);
                // l'empreinte ne dépend pas de la langue demandée : elle est celle de l'aperçu dans la langue du fil (celle que la confirmation recalcule)
                $hash = self::hash($conversation, $locale === $conversation->getLocale() ? $preview : $action->preview($conversation->getAnswers(), $conversation->getLocale()));
                $preview['rows'] = $action->rows($preview, $locale); // forme commune, en plus de la forme propre à l'action
                $state = GestionConversationService::STATE_READY;
            }
        }
        $messages = $this->emProvider->getEntityManager()->getRepository(GestionConversationMessage::class)->findBy(['conversation' => $conversation], ['id' => 'ASC']);
        $confirmed = $state === GestionConversationService::STATE_CONFIRMED ? $this->confirmation($conversation) : null;
        $tr = fn (string $id, array $params = []) => $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
        $name = str_replace('.', '_', $conversation->getKind());
        $summary = match (true) {
            $state === GestionConversationService::STATE_CONFIRMED => $tr('conversation.actions.summary.confirmed'),
            $state === GestionConversationService::STATE_ABANDONED => $tr('conversation.actions.summary.abandoned'),
            $state === GestionConversationService::STATE_READY => $tr('conversation.summary.ready'),
            $open > 0 => $tr('conversation.summary.questions', ['%count%' => (string) $open]),
            default => $tr('conversation.actions.summary.issues'),
        };

        return [
            'id' => $conversation->getId(), 'kind' => $conversation->getKind(), 'state' => $state, 'locale' => $conversation->getLocale(),
            'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updatedAt' => $conversation->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'document' => null,
            'action' => ['key' => $conversation->getKind(), 'title' => $tr("conversation.actions.$name.title"), 'available' => $available, 'result' => $confirmed?->getContent()['result'] ?? null],
            'summary' => ['text' => $tr("conversation.actions.$name.title") . ' : ' . $summary, 'openQuestions' => $open],
            // ce qui sert à défaire (`undo`) reste au serveur : le message `confirmed` est rendu sans lui
            'messages' => array_map(fn (GestionConversationMessage $m) => ['id' => $m->getId(), 'role' => $m->getRole(), 'kind' => $m->getKind(),
                'content' => array_diff_key($m->getContent(), $m->getKind() === 'confirmed' ? ['undo' => true] : []) ?: new \stdClass(), 'createdAt' => $m->getCreatedAt()?->format(\DateTimeInterface::ATOM)], $messages),
            'questions' => $built['questions'],
            'openQuestions' => $open,
            'issues' => $built['issues'],
            'preview' => $preview,
            'previewHash' => $hash,
            'undo' => $confirmed === null ? null : self::window($confirmed) + ['blockers' => $available ? $this->catalog->get($conversation->getKind())->blockers($confirmed->getContent()['undo']) : []],
            'credits' => $this->quota->credits(),
        ];
    }

    /**
     * Confirmation de l'aperçu : l'empreinte doit être celle de l'aperçu d'aujourd'hui, sinon 409 `PREVIEW_CHANGED` avec le nouvel aperçu.
     *
     * @return array<string, mixed> `{ result, undo: { available, until } }`
     */
    public function confirm(GestionConversation $conversation, mixed $previewHash, ?string $user): array
    {
        $action = $this->workable($conversation);
        if (!is_string($previewHash) || $previewHash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        $thread = $this->thread($conversation);
        if ($thread['state'] !== GestionConversationService::STATE_READY) {
            throw new GestionConflictException('CONVERSATION_NOT_READY', 'Il reste des questions sans réponse ou un refus à lever avant de confirmer.', 409,
                ['state' => $thread['state'], 'openQuestions' => $thread['openQuestions'], 'issues' => $thread['issues']]);
        }
        if (!hash_equals((string) $thread['previewHash'], $previewHash)) {
            throw new GestionConflictException('PREVIEW_CHANGED', 'L\'aperçu a changé depuis son affichage : vérifiez-le avant de confirmer.', 409, ['preview' => $thread['preview'], 'previewHash' => $thread['previewHash']]);
        }
        $done = $action->execute($conversation->getAnswers(), $user); // les refus du service existant remontent tels quels (409, 422)
        $em = $this->emProvider->getEntityManager();
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $message = GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'confirmed', ['action' => $conversation->getKind(), 'result' => $done['result'], 'undo' => $done['undo'], 'previewHash' => $previewHash], $user);
        $em->persist($message);
        $em->flush();

        return ['result' => $done['result'], 'undo' => self::window($message)];
    }

    /**
     * Annulation d'un bloc de la confirmation du fil, une fois, dans les 24 heures ; le fil revient à ses questions répondues.
     * 409 `NOTHING_TO_UNDO`, `ALREADY_UNDONE`, `UNDO_EXPIRED`, `UNDO_BLOCKED { reasons }`.
     *
     * @return array<string, mixed>
     */
    public function undo(GestionConversation $conversation, ?string $user): array
    {
        $confirmed = $this->confirmation($conversation);
        if ($confirmed === null) {
            throw $this->last($conversation, 'undone') !== null
                ? new GestionConflictException('ALREADY_UNDONE', 'Cette confirmation a déjà été annulée.')
                : new GestionConflictException('NOTHING_TO_UNDO', 'Ce fil n\'a rien confirmé : rien à annuler.');
        }
        $window = self::window($confirmed);
        if (!$window['available']) {
            throw new GestionConflictException('UNDO_EXPIRED', sprintf('L\'annulation n\'est possible que dans les %d heures qui suivent la confirmation.', self::UNDO_HOURS), 409, ['until' => $window['until']]);
        }
        $action = $this->action($conversation);
        $reasons = $action->blockers($confirmed->getContent()['undo']);
        if ($reasons !== []) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Annulation impossible : ' . implode(' ', array_column($reasons, 'message')), 409, ['reasons' => $reasons]);
        }
        $result = $action->undo($confirmed->getContent()['undo']);
        $em = $this->emProvider->getEntityManager();
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'undone', $result + ['confirmedMessageId' => $confirmed->getId()], $user));
        $em->flush();

        return $result;
    }

    /** Abandon du fil : rien n'a été écrit, rien n'est à défaire */
    public function abandon(GestionConversation $conversation, ?string $user): void
    {
        $this->workable($conversation);
        $em = $this->emProvider->getEntityManager();
        $conversation->setStatus(GestionConversation::STATUS_ABANDONED)->setUpdatedAt(new \DateTimeImmutable());
        $em->persist(GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, 'abandoned', [], $user));
        $em->flush();
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /** Le message `confirmed` en vigueur : le dernier, s'il n'a pas été suivi d'une annulation */
    private function confirmation(GestionConversation $conversation): ?GestionConversationMessage
    {
        $confirmed = $this->last($conversation, 'confirmed');
        $undone = $this->last($conversation, 'undone');

        return $confirmed !== null && ($undone === null || $undone->getId() < $confirmed->getId()) ? $confirmed : null;
    }

    private function last(GestionConversation $conversation, string $kind): ?GestionConversationMessage
    {
        return $conversation->getId() === null ? null : $this->emProvider->getEntityManager()->getRepository(GestionConversationMessage::class)->findOneBy(['conversation' => $conversation, 'kind' => $kind], ['id' => 'DESC']);
    }

    /** @return array{available: bool, until: string} fenêtre d'annulation d'une confirmation */
    private static function window(GestionConversationMessage $confirmed): array
    {
        $until = ($confirmed->getCreatedAt() ?? new \DateTimeImmutable())->modify('+' . self::UNDO_HOURS . ' hours');

        return ['available' => $until > new \DateTimeImmutable(), 'until' => $until->format(\DateTimeInterface::ATOM)];
    }

    /**
     * Empreinte de l'aperçu : l'action, les réponses et ce que l'aperçu en a calculé (dont les valeurs d'avant, pour un budget). Les
     * réponses sont triées : les mêmes réponses donnent la même empreinte, quel que soit l'ordre dans lequel elles ont été données.
     */
    private static function hash(GestionConversation $conversation, array $preview): string
    {
        $answers = $conversation->getAnswers();
        ksort($answers);

        return hash('sha256', (string) json_encode([$conversation->getKind(), $answers, $preview]));
    }
}
