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
    /** action → clés de contexte qui doivent correspondre */
    public const CONTEXT = [
        self::REFUND => ['orderId'],
        self::WITHDRAWAL => ['amount', 'cashRegisterId'],
        self::FUND_WITHDRAWAL => ['amount', 'cashRegisterId'],
        self::DRAWER_OPEN => ['cashRegisterId'],
        self::CLOSE_WITHOUT_COUNT => ['cashRegisterId'],
        self::DISCOUNT => [],
        self::RETURN => ['orderId', 'amount'],
    ];
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

    /** @return array{approvalToken: string, expiresAt: string} */
    public function grant(mixed $action, mixed $managerId, mixed $pin, mixed $context, User $requester): array
    {
        if (!is_string($action) || !array_key_exists($action, self::CONTEXT)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'action : ' . implode(', ', array_keys(self::CONTEXT)) . ' attendue', 422, [['path' => 'action', 'message' => 'action inconnue']]);
        }
        $context = self::normalizeContext($action, $context);
        $terminal = $this->terminals->current() ?? throw new CashRegisterException('TERMINAL_REQUIRED', 'Terminal de caisse non identifié (en-tête X-Pos-Terminal)', 403);
        $em = $this->emProvider->getEntityManager();
        $manager = is_numeric($managerId) ? $em->find(User::class, (int) $managerId) : null;
        try {
            if (!$manager instanceof User || $manager->isDeleted() || !self::isManager($manager)) {
                throw new CashRegisterException('APPROVER_NOT_ALLOWED', 'Le validateur doit être responsable de caisse ou administrateur', 403);
            }
            $this->pins->verify($manager, $pin, 400, ['managerId' => $manager->getId()], 'approval');
        } catch (CashRegisterException $e) {
            $this->audit->record(PosAuditService::APPROVAL_FAILED, ['action' => $action, 'context' => $context, 'managerId' => is_numeric($managerId) ? (int) $managerId : null, 'reason' => $e->errorCode], subjectType: 'approval');
            throw $e;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = new \DateTimeImmutable('+' . self::TTL_SECONDS . ' seconds');
        $approval = new PosApproval(hash('sha256', $token), $action, (int) $manager->getId(), (int) $requester->getId(), $terminal['id'], $context, $expiresAt);
        $em->persist($approval);
        $em->flush();
        $this->audit->record(PosAuditService::APPROVAL_GRANTED, ['action' => $action, 'context' => $context, 'approver' => self::person($manager), 'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM)],
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

    public function requireFor(string $action, User $actor, array $context, ?int $amount = null): void
    {
        if (self::isManager($actor) || !$this->settings->requiresApproval($action, $amount)) {
            return;
        }
        $token = (string) $this->requestStack->getMainRequest()?->headers->get(self::HEADER, '');
        if ($token === '') {
            throw new CashRegisterException('APPROVAL_REQUIRED', 'Validation d\'un responsable requise', 403, [], ['action' => $action, 'context' => $context]);
        }
        $em = $this->emProvider->getEntityManager();
        /** @var ?PosApproval $approval */
        $approval = $em->getRepository(PosApproval::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        $now = new \DateTimeImmutable();
        $terminal = $this->terminals->current();
        $reason = match (true) {
            $approval === null => 'jeton inconnu',
            $approval->getUsedAt() !== null => 'jeton déjà utilisé',
            $approval->getExpiresAt() <= $now => 'jeton expiré',
            $approval->getAction() !== $action => 'jeton d\'une autre action',
            $approval->getRequestedById() !== $actor->getId() => 'jeton demandé par un autre utilisateur',
            $terminal === null || $approval->getTerminalId() !== $terminal['id'] => 'jeton d\'un autre terminal',
            !self::sameContext($action, $approval->getContext(), $context) => 'contexte différent',
            default => null,
        };
        if ($reason !== null) {
            throw new CashRegisterException('APPROVAL_INVALID', 'Validation invalide : ' . $reason, 403, [], ['action' => $action]);
        }
        $consumed = $em->getConnection()->executeStatement('UPDATE pos_approval SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
            [$now->format('Y-m-d H:i:s'), $approval->getId(), $now->format('Y-m-d H:i:s')]);
        if ($consumed !== 1) {
            throw new CashRegisterException('APPROVAL_INVALID', 'Validation invalide : jeton déjà utilisé ou expiré', 403, [], ['action' => $action]);
        }
        $manager = $em->find(User::class, $approval->getManagerId());
        $this->current->set(self::person($manager) ?? ['id' => $approval->getManagerId(), 'name' => null]);
    }

    /** @return array<string, int> */
    private static function normalizeContext(string $action, mixed $context): array
    {
        $context = is_array($context) ? $context : [];
        $out = [];
        foreach (self::CONTEXT[$action] as $key) {
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
            if ((int) ($approved[$key] ?? -1) !== (int) ($actual[$key] ?? -2)) {
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
