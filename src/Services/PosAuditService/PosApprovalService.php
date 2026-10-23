<?php

namespace App\Services\PosAuditService;

use App\Entity\PosApproval;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validation d'un responsable pour les actions sensibles (chantier C3, 20/10/2026 ; droits vérifiés côté serveur).
 * grant() : le responsable saisit son PIN sur la tablette du caissier → jeton (2 minutes, usage unique, lié à l'action,
 * au contexte, au terminal et au caissier). requireFor() : appelé par l'action protégée DANS SA TRANSACTION SQL ; consomme
 * le jeton (en-tête X-Pos-Approval) de façon atomique : si l'action échoue ensuite, le ROLLBACK rend le jeton utilisable.
 * Un responsable (ROLE_POS_MANAGER) ou un administrateur qui agit lui-même n'a jamais besoin de validation.
 */
class PosApprovalService
{
    public const HEADER = 'X-Pos-Approval';
    public const REFUND = 'refund';
    public const WITHDRAWAL = 'withdrawal';
    public const FUND_WITHDRAWAL = 'fund_withdrawal';
    public const DRAWER_OPEN = 'drawer_open';
    public const CLOSE_WITHOUT_COUNT = 'close_without_count';
    public const DISCOUNT = 'discount'; // réservé (chantier F1)
    public const RETURN = 'return'; // retour partiel ou échange (F2, 24/10/2026)
    public const OFFLINE_FORCE = 'offline_force'; // acceptation forcée d'une vente hors ligne (C5 partie 2, 25/10/2026)
    public const CUSTOM_ITEM = 'custom_item'; // article libre au-delà du montant maximal (F7, 26/10/2026)
    public const PRICE_OVERRIDE = 'price_override'; // prix manuel : baisse au-delà du maximum (F7, 26/10/2026)
    public const GIFT_CARD_CANCEL = 'gift_card_cancel'; // annulation d'une carte-cadeau vendue, jamais utilisée (F9, 27/10/2026)
    public const GIFT_CARD_ADJUST = 'gift_card_adjust'; // ajustement manuel de solde ou remboursement en espèces au-delà du seuil (F9)
    public const GIFT_CARD_REPLACE = 'gift_card_replace'; // remplacement d'une carte perdue (F9)
    /** action → clés de contexte qui doivent correspondre */
    public const CONTEXT = [
        self::REFUND => ['orderId'],
        self::WITHDRAWAL => ['amount', 'cashRegisterId'],
        self::FUND_WITHDRAWAL => ['amount', 'cashRegisterId'],
        self::DRAWER_OPEN => ['cashRegisterId'],
        self::CLOSE_WITHOUT_COUNT => ['cashRegisterId'],
        self::DISCOUNT => ['amount', 'percent', 'scope'], // remises (F1) : montant (cents), pourcentage (centièmes de %), scope line | ticket | mixed
        self::RETURN => ['orderId', 'amount'],
        self::OFFLINE_FORCE => ['terminalId', 'counter', 'amount'],
        self::CUSTOM_ITEM => ['amount'], // total des articles libres de la vente (cents)
        self::PRICE_OVERRIDE => ['amount'], // somme des baisses par rapport au prix catalogue (cents)
        self::GIFT_CARD_CANCEL => ['amount'], // montant remboursé (cents)
        self::GIFT_CARD_ADJUST => ['amount'], // valeur absolue de l'ajustement ou du solde remboursé (cents)
        self::GIFT_CARD_REPLACE => ['amount'], // solde reporté (cents)
    ];
    /** Clés de contexte textuelles (les autres sont numériques) */
    public const STRING_CONTEXT = ['scope'];
    /** Clés de contexte facultatives, comparées si elles figurent dans la validation (remboursement d'un paiement par terminal sans vente, C4) */
    public const EXTRA_CONTEXT = [self::REFUND => ['terminalPaymentId']];
    public const TTL_SECONDS = 120;
    private const MANAGER_ROLES = ['ROLE_POS_MANAGER', 'ROLE_ADMIN'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosPinService $pins,
        private readonly PosTerminalService $terminals,
        private readonly PosAuditService $audit,
        private readonly PosSettingsService $settings,
        private readonly PosApprovalContext $current,
        private readonly RequestStack $requestStack
    ) {
    }

    public static function isManager(?User $user): bool
    {
        return $user !== null && array_intersect(self::MANAGER_ROLES, $user->getRoles()) !== [];
    }

    /**
     * Jeton pour une action {action, context}, ou groupé pour plusieurs actions d'une même vente (P2-a, 26/10/2026 :
     * actions: [{action, context}, …] ; le responsable saisit son PIN une seule fois).
     *
     * @return array{approvalToken: string, expiresAt: string}
     */
    public function grant(mixed $action, mixed $managerId, mixed $pin, mixed $context, User $requester, mixed $actions = null): array
    {
        $group = null;
        if (is_array($actions) && $actions !== []) {
            $group = [];
            foreach (array_values($actions) as $i => $one) {
                if (!is_array($one) || !is_string($one['action'] ?? null) || !array_key_exists($one['action'], self::CONTEXT)) {
                    throw new CashRegisterException('VALIDATION_FAILED', "actions[$i].action : " . implode(', ', array_keys(self::CONTEXT)) . ' attendue', 422, [['path' => "actions[$i].action", 'message' => 'action inconnue']]);
                }
                $group[] = ['action' => $one['action'], 'context' => self::normalizeContext($one['action'], $one['context'] ?? null)];
            }
            $action = $group[0]['action'];
            $context = $group[0]['context'];
        }
        if (!is_string($action) || !array_key_exists($action, self::CONTEXT)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'action : ' . implode(', ', array_keys(self::CONTEXT)) . ' attendue', 422, [['path' => 'action', 'message' => 'action inconnue']]);
        }
        $context = $group !== null ? $context : self::normalizeContext($action, $context);
        $terminal = $this->terminals->current() ?? throw new CashRegisterException('TERMINAL_REQUIRED', 'Terminal de caisse non identifié (en-tête X-Pos-Terminal)', 403);
        $em = $this->emProvider->getEntityManager();
        $manager = is_numeric($managerId) ? $em->find(User::class, (int) $managerId) : null;
        try {
            if (!$manager instanceof User || $manager->isDeleted() || !self::isManager($manager)) {
                throw new CashRegisterException('APPROVER_NOT_ALLOWED', 'Le validateur doit être responsable de caisse ou administrateur', 403);
            }
            $this->pins->verify($manager, $pin, 400, ['managerId' => $manager->getId()], 'approval');
        } catch (CashRegisterException $e) {
            $this->audit->record(PosAuditService::APPROVAL_FAILED, ['action' => $action, 'context' => $context, 'actions' => $group, 'managerId' => is_numeric($managerId) ? (int) $managerId : null, 'reason' => $e->errorCode], subjectType: 'approval');
            throw $e;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = new \DateTimeImmutable('+' . self::TTL_SECONDS . ' seconds');
        $approval = new PosApproval(hash('sha256', $token), $action, (int) $manager->getId(), (int) $requester->getId(), $terminal['id'], $context, $expiresAt);
        $approval->setActions($group !== null && count($group) > 1 ? $group : null);
        $em->persist($approval);
        $em->flush();
        $this->audit->record(PosAuditService::APPROVAL_GRANTED, ['action' => $action, 'context' => $context, 'actions' => $group, 'approver' => self::person($manager), 'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM)],
            subjectType: 'approval', subjectId: $approval->getId(), user: $requester);

        return ['approvalToken' => $token, 'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM)];
    }

    /**
     * À appeler par l'action protégée, dans sa transaction SQL. Rien à faire pour un responsable ou un administrateur, ni
     * si le réglage du site ne l'exige pas. Sinon jeton exigé : 403 APPROVAL_REQUIRED {action, context} ou 403
     * APPROVAL_INVALID (expiré, déjà utilisé, autre terminal, autre caissier, autre action ou contexte).
     *
     * @param array<string, int> $context
     */
    /** Validation consommée pendant la requête en cours ({id, name} du responsable), sinon null */
    public function approvedBy(): ?array
    {
        return $this->current->approvedBy();
    }

    public function requireFor(string $action, User $actor, array $context, ?int $amount = null, ?string $token = null): void
    {
        if (self::isManager($actor) || !$this->settings->requiresApproval($action, $amount)) {
            return;
        }
        $this->consume([['action' => $action, 'context' => $context]], $actor, $token, false);
    }

    /**
     * Plusieurs actions d'une même vente (remise, article libre, prix manuel ; P2-a, 26/10/2026) : celles que le site soumet
     * à validation sont demandées EN UNE FOIS (403 APPROVAL_REQUIRED {action, context, actions: [{action, context}, …]}) et
     * couvertes par UN jeton (POST /api/pos/approvals avec actions: [...]). Un jeton d'une seule action reste valable
     * quand une seule action est requise (compatibilité C3).
     *
     * @param list<array{action: string, context: array<string, int|string>, amount?: ?int}> $demands
     */
    public function requireAll(array $demands, User $actor, ?string $token = null): void
    {
        if (self::isManager($actor)) {
            return;
        }
        $required = array_values(array_filter($demands, fn ($d) => $this->settings->requiresApproval($d['action'], $d['amount'] ?? null)));
        if ($required === []) {
            return;
        }
        $this->consume(array_map(fn ($d) => ['action' => $d['action'], 'context' => $d['context']], $required), $actor, $token, true);
    }

    /**
     * Consomme le jeton (en-tête X-Pos-Approval, ou passé par l'appelant : ventes hors ligne, un jeton par vente dans le corps)
     * pour les actions demandées ; un jeton déjà consommé par cette requête reste valable pour ses autres actions.
     *
     * @param list<array{action: string, context: array}> $wanted
     */
    private function consume(array $wanted, User $actor, ?string $token, bool $listActions): void
    {
        $first = $wanted[0];
        $extra = $listActions ? ['actions' => $wanted] : [];
        $token ??= (string) $this->requestStack->getMainRequest()?->headers->get(self::HEADER, '');
        if ($token === '') {
            throw new CashRegisterException('APPROVAL_REQUIRED', 'Validation d\'un responsable requise', 403, [], ['action' => $first['action'], 'context' => $first['context']] + $extra);
        }
        $em = $this->emProvider->getEntityManager();
        /** @var ?PosApproval $approval */
        $approval = $em->getRepository(PosApproval::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        $now = new \DateTimeImmutable();
        $terminal = $this->terminals->current();
        $already = $approval !== null && ($this->current->consumed()['id'] ?? null) === $approval->getId();
        $uncovered = $approval !== null ? self::uncovered($approval->getActions(), $wanted) : null;
        $reason = match (true) {
            $approval === null => 'jeton inconnu',
            $approval->getUsedAt() !== null && !$already => 'jeton déjà utilisé',
            $approval->getExpiresAt() <= $now => 'jeton expiré',
            $approval->getRequestedById() !== $actor->getId() => 'jeton demandé par un autre utilisateur',
            $terminal === null || $approval->getTerminalId() !== $terminal['id'] => 'jeton d\'un autre terminal',
            $uncovered !== null && $uncovered['why'] === 'action' => 'jeton d\'une autre action',
            $uncovered !== null => 'contexte différent',
            default => null,
        };
        if ($reason !== null) {
            throw new CashRegisterException('APPROVAL_INVALID', 'Validation invalide : ' . $reason, 403, [], ['action' => $uncovered['action'] ?? $first['action']] + $extra);
        }
        if (!$already) {
            $consumed = $em->getConnection()->executeStatement('UPDATE pos_approval SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
                [$now->format('Y-m-d H:i:s'), $approval->getId(), $now->format('Y-m-d H:i:s')]);
            if ($consumed !== 1) {
                throw new CashRegisterException('APPROVAL_INVALID', 'Validation invalide : jeton déjà utilisé ou expiré', 403, [], ['action' => $first['action']] + $extra);
            }
            $manager = $em->find(User::class, $approval->getManagerId());
            $this->current->set(self::person($manager) ?? ['id' => $approval->getManagerId(), 'name' => null]);
            $this->current->setConsumed(['id' => $approval->getId(), 'actions' => $approval->getActions()]);
        }
    }

    /**
     * Première action demandée que le jeton ne couvre pas : {action, why: action|context}, ou null si tout est couvert.
     *
     * @param list<array{action: string, context: array}> $covered actions du jeton
     * @param list<array{action: string, context: array}> $wanted
     */
    private static function uncovered(array $covered, array $wanted): ?array
    {
        foreach ($wanted as $w) {
            $sameAction = array_values(array_filter($covered, fn ($c) => $c['action'] === $w['action']));
            if ($sameAction === []) {
                return ['action' => $w['action'], 'why' => 'action'];
            }
            $match = false;
            foreach ($sameAction as $c) {
                if (self::sameContext($w['action'], $c['context'], $w['context'])) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return ['action' => $w['action'], 'why' => 'context'];
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private static function normalizeContext(string $action, mixed $context): array
    {
        $context = is_array($context) ? $context : [];
        $out = [];
        foreach (self::CONTEXT[$action] as $key) {
            if (in_array($key, self::STRING_CONTEXT, true)) {
                if (!is_string($context[$key] ?? null) || !preg_match('/^[a-z_]{1,20}$/', $context[$key])) {
                    throw new CashRegisterException('VALIDATION_FAILED', sprintf('context.%s requis pour l\'action %s', $key, $action), 422, [['path' => "context.$key", 'message' => 'requis']]);
                }
                $out[$key] = $context[$key];
                continue;
            }
            if (!isset($context[$key]) || !is_numeric($context[$key])) {
                throw new CashRegisterException('VALIDATION_FAILED', sprintf('context.%s requis pour l\'action %s', $key, $action), 422, [['path' => "context.$key", 'message' => 'requis']]);
            }
            $out[$key] = (int) $context[$key];
        }
        foreach (self::EXTRA_CONTEXT[$action] ?? [] as $key) {
            if (isset($context[$key]) && is_numeric($context[$key])) {
                $out[$key] = (int) $context[$key];
            }
        }

        return $out;
    }

    private static function sameContext(string $action, array $approved, array $actual): bool
    {
        $keys = self::CONTEXT[$action];
        foreach (self::EXTRA_CONTEXT[$action] ?? [] as $extra) {
            if (array_key_exists($extra, $approved) || array_key_exists($extra, $actual)) {
                $keys[] = $extra;
            }
        }
        foreach ($keys as $key) {
            if (in_array($key, self::STRING_CONTEXT, true) ? (string) ($approved[$key] ?? '') !== (string) ($actual[$key] ?? '-') : (int) ($approved[$key] ?? -1) !== (int) ($actual[$key] ?? -2)) {
                return false;
            }
        }

        return true;
    }

    private static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->getId(), 'name' => trim($user->getFirstname() . ' ' . $user->getLastname())] : null;
    }
}
