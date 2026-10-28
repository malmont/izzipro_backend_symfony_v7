<?php

namespace App\Services\PosCustomerService;

use App\Entity\Caisse;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\PosCustomer;
use App\Entity\PosCustomerConsent;
use App\Entity\PosLoyaltyTransaction;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;

/**
 * Fidélité (lot F8, 28/10/2026). Points gagnés sur le net des articles d'une vente en caisse (après remises, hors taxes, hors
 * part payée par carte-cadeau ou avoir, jamais sur une vente de carte-cadeau), rachetés en remise de ticket F1 calculée par le
 * serveur (sans plafond caissier), repris au retour au prorata du net remboursé. Expiration : N mois sans activité (réglage).
 */
class PosLoyaltyService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PosAuditService $audit, private readonly PosApprovalService $approvals)
    {
    }

    public function settings(): array
    {
        return self::settingsOf($this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]));
    }

    public static function settingsOf(?Entreprise $e): array
    {
        return [
            'enabled' => $e?->isPosLoyaltyEnabled() ?? false,
            'pointsPerDollar' => $e?->getPosLoyaltyPointsPerDollar() ?? 1,
            'valuePer100Points' => $e?->getPosLoyaltyValuePer100() ?? 100,
            'minRedeem' => $e?->getPosLoyaltyMinRedeem() ?? 500,
            'redeemStep' => $e?->getPosLoyaltyRedeemStep() ?? 100,
            'expiryMonths' => $e?->getPosLoyaltyExpiryMonths(),
            'earnBase' => 'net des articles après remises, hors taxes, hors part carte-cadeau ou avoir',
        ];
    }

    /** Assiette des points d'une vente (cents) : net des articles − part réglée par carte-cadeau ou avoir, plancher 0 ; 0 pour une vente de carte-cadeau */
    public static function base(Order $order): int
    {
        if ($order->getOrderType()?->getId() !== 1) {
            return 0;
        }
        $net = (int) array_sum(array_map(fn ($i) => (int) round((float) $i->getTotalPrice()), $order->getOrderItems()->toArray()));

        return max(0, $net - $order->getGiftCardApplied());
    }

    /** Points acquis par une vente ; 0 sans programme, sans consentement fidélité ou sans assiette */
    public function earn(Order $order, PosCustomer $customer, ?Caisse $caisse, ?User $user, PosCustomerService $customers): int
    {
        $settings = $this->settings();
        if (!$settings['enabled'] || !$customer->isActive() || !$customers->consentGranted($customer, PosCustomerConsent::LOYALTY)) {
            return 0;
        }
        $this->applyExpiry($customer, $caisse, $user);
        $base = self::base($order);
        $points = (int) floor($base / 100) * $settings['pointsPerDollar'];
        if ($points <= 0) {
            return 0;
        }
        $customer->setLoyaltyPoints($customer->getLoyaltyPoints() + $points)->setLoyaltyLastActivityAt(new \DateTimeImmutable())->touch();
        $this->transaction($customer, PosLoyaltyTransaction::EARN, $points, $base, $order->getId(), null, $caisse, $user);
        $this->audit->record(PosAuditService::LOYALTY_EARNED, ['customerId' => $customer->getId(), 'points' => $points, 'base' => $base, 'balance' => $customer->getLoyaltyPoints(), 'reference' => $order->getReference()], $caisse, null, 'order', $order->getId(), $user);

        return $points;
    }

    /**
     * Valeur d'un rachat (cents) après contrôles : programme actif, consentement, minimum, pas, solde.
     * 409 LOYALTY_DISABLED, LOYALTY_CONSENT_REQUIRED, LOYALTY_INSUFFICIENT ; 422 LOYALTY_POINTS_INVALID
     */
    public function rewardFor(PosCustomer $customer, int $points, PosCustomerService $customers, ?Caisse $caisse = null, ?User $user = null): int
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            throw new CashRegisterException('LOYALTY_DISABLED', 'Le programme de fidélité est désactivé sur ce site', 409);
        }
        if (!$customers->consentGranted($customer, PosCustomerConsent::LOYALTY)) {
            throw new CashRegisterException('LOYALTY_CONSENT_REQUIRED', 'Le client n\'a pas consenti au programme de fidélité', 409, [], ['customerId' => $customer->getId()]);
        }
        if ($points < $settings['minRedeem'] || $points % $settings['redeemStep'] !== 0) {
            throw new CashRegisterException('LOYALTY_POINTS_INVALID', sprintf('Rachat : au moins %d points, par pas de %d', $settings['minRedeem'], $settings['redeemStep']), 422, [['path' => 'loyalty.redeemPoints', 'message' => 'minimum et pas du site']], ['minRedeem' => $settings['minRedeem'], 'redeemStep' => $settings['redeemStep']]);
        }
        $this->applyExpiry($customer, $caisse, $user);
        if ($points > $customer->getLoyaltyPoints()) {
            throw new CashRegisterException('LOYALTY_INSUFFICIENT', 'Solde de points insuffisant', 409, [], ['balance' => $customer->getLoyaltyPoints(), 'requested' => $points]);
        }

        return intdiv($points * $settings['valuePer100Points'], 100);
    }

    /**
     * Valeur d'un rachat pour un devis (F8, devis de caisse, d'échange et part carte du terminal) : mêmes contrôles et mêmes refus que
     * rewardFor, sans aucune écriture ; des points expirés par inactivité comptent pour 0 (la vente écrira l'expiration, comme avant).
     *
     * @return array{amount: int, balance: int}
     */
    /**
     * Points que onReturn reprendra pour un retour (même calcul, sans écriture), bornés par le solde : sert au devis d'échange, qui doit
     * refuser ce que l'échange refusera une fois le retour passé (relecture du 28/10/2026).
     */
    public function previewReturn(Order $original, ?int $refundedNet): int
    {
        if ($original->getCustomerId() === null) {
            return 0;
        }
        $em = $this->emProvider->getEntityManager();
        $customer = $em->find(PosCustomer::class, $original->getCustomerId());
        if ($customer === null) {
            return 0;
        }
        $earned = $base = $already = 0;
        foreach ($em->getRepository(PosLoyaltyTransaction::class)->findBy(['orderId' => $original->getId(), 'kind' => PosLoyaltyTransaction::EARN]) as $tx) {
            $earned += $tx->getPoints();
            $base += (int) $tx->getAmount();
        }
        foreach ($em->getRepository(PosLoyaltyTransaction::class)->findBy(['orderId' => $original->getId(), 'kind' => PosLoyaltyTransaction::RETURN]) as $tx) {
            $already += -$tx->getPoints();
        }
        if ($earned <= 0) {
            return 0;
        }
        $share = $refundedNet === null || $base <= 0 ? $earned : (int) round($earned * min($refundedNet, $base) / $base);

        return max(0, min(max(0, min($share, $earned - $already)), $customer->getLoyaltyPoints()));
    }

    /** @param int $reservedPoints points qui seront repris avant la vente (retour d'un échange) : retranchés du solde contrôlé */
    public function previewReward(PosCustomer $customer, int $points, PosCustomerService $customers, int $reservedPoints = 0): array
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            throw new CashRegisterException('LOYALTY_DISABLED', 'Le programme de fidélité est désactivé sur ce site', 409);
        }
        if (!$customers->consentGranted($customer, PosCustomerConsent::LOYALTY)) {
            throw new CashRegisterException('LOYALTY_CONSENT_REQUIRED', 'Le client n\'a pas consenti au programme de fidélité', 409, [], ['customerId' => $customer->getId()]);
        }
        if ($points < $settings['minRedeem'] || $points % $settings['redeemStep'] !== 0) {
            throw new CashRegisterException('LOYALTY_POINTS_INVALID', sprintf('Rachat : au moins %d points, par pas de %d', $settings['minRedeem'], $settings['redeemStep']), 422, [['path' => 'loyalty.redeemPoints', 'message' => 'minimum et pas du site']], ['minRedeem' => $settings['minRedeem'], 'redeemStep' => $settings['redeemStep']]);
        }
        $months = $settings['expiryMonths'];
        $expired = $months !== null && $customer->getLoyaltyPoints() > 0 && $customer->getLoyaltyLastActivityAt() !== null && $customer->getLoyaltyLastActivityAt() <= new \DateTimeImmutable('-' . $months . ' months');
        $balance = max(0, ($expired ? 0 : $customer->getLoyaltyPoints()) - max(0, $reservedPoints));
        if ($points > $balance) {
            throw new CashRegisterException('LOYALTY_INSUFFICIENT', 'Solde de points insuffisant', 409, [], ['balance' => $balance, 'requested' => $points]);
        }

        return ['amount' => intdiv($points * $settings['valuePer100Points'], 100), 'balance' => $balance];
    }

    /** Débit des points rachetés (dans la transaction de la vente) */
    public function redeem(PosCustomer $customer, int $points, int $amount, Order $order, ?Caisse $caisse, ?User $user): void
    {
        $customer->setLoyaltyPoints($customer->getLoyaltyPoints() - $points)->setLoyaltyLastActivityAt(new \DateTimeImmutable())->touch();
        $this->transaction($customer, PosLoyaltyTransaction::REDEEM, -$points, $amount, $order->getId(), null, $caisse, $user);
        $this->audit->record(PosAuditService::LOYALTY_REDEEMED, ['customerId' => $customer->getId(), 'points' => $points, 'amount' => $amount, 'balance' => $customer->getLoyaltyPoints(), 'reference' => $order->getReference()], $caisse, null, 'order', $order->getId(), $user);
    }

    /**
     * Reprise des points d'une vente retournée, au prorata du net remboursé ; $refundedNet null = tout. Points déjà dépensés :
     * le solde est ramené à 0, le manque (shortfall) est indiqué dans la réponse et le journal, le retour n'est jamais bloqué.
     *
     * @return array{points: int, shortfall: int}
     */
    public function onReturn(Order $original, ?int $refundedNet, ?int $returnOrderId, ?Caisse $caisse, ?User $user): array
    {
        $none = ['points' => 0, 'shortfall' => 0];
        $em = $this->emProvider->getEntityManager();
        if ($original->getCustomerId() === null) {
            return $none;
        }
        $customer = $em->find(PosCustomer::class, $original->getCustomerId());
        if ($customer === null) {
            return $none;
        }
        $earned = 0;
        $base = 0;
        foreach ($em->getRepository(PosLoyaltyTransaction::class)->findBy(['orderId' => $original->getId(), 'kind' => PosLoyaltyTransaction::EARN]) as $tx) {
            $earned += $tx->getPoints();
            $base += (int) $tx->getAmount();
        }
        $already = 0;
        foreach ($em->getRepository(PosLoyaltyTransaction::class)->findBy(['orderId' => $original->getId(), 'kind' => PosLoyaltyTransaction::RETURN]) as $tx) {
            $already += -$tx->getPoints();
        }
        if ($earned <= 0) {
            return $none;
        }
        $share = $refundedNet === null || $base <= 0 ? $earned : (int) round($earned * min($refundedNet, $base) / $base);
        $due = max(0, min($share, $earned - $already));
        $points = min($due, $customer->getLoyaltyPoints());
        $shortfall = $due - $points;
        if ($due <= 0) {
            return $none;
        }
        $customer->setLoyaltyPoints($customer->getLoyaltyPoints() - $points)->touch();
        $this->transaction($customer, PosLoyaltyTransaction::RETURN, -$points, $refundedNet, $original->getId(), $returnOrderId, $caisse, $user)
            ->setReason($shortfall > 0 ? sprintf('%d points dus, %d repris : %d déjà dépensés (solde ramené à 0)', $due, $points, $shortfall) : null);
        $this->audit->record(PosAuditService::LOYALTY_RETURNED, ['customerId' => $customer->getId(), 'points' => $points, 'due' => $due, 'shortfall' => $shortfall, 'refundedNet' => $refundedNet, 'balance' => $customer->getLoyaltyPoints(), 'originalOrderId' => $original->getId(), 'returnOrderId' => $returnOrderId], $caisse, null, 'order', $returnOrderId ?? $original->getId(), $user);

        return ['points' => $points, 'shortfall' => $shortfall];
    }

    /** Points repris par un retour et manque éventuel (points déjà dépensés), pour la réponse du retour et son rejeu */
    public function returnSummary(int $returnOrderId): ?array
    {
        $tx = $this->emProvider->getEntityManager()->getRepository(PosLoyaltyTransaction::class)->findOneBy(['returnId' => $returnOrderId, 'kind' => PosLoyaltyTransaction::RETURN]);
        if ($tx === null) {
            return null;
        }
        $shortfall = preg_match('/^(\d+) points dus/', (string) $tx->getReason(), $m) ? (int) $m[1] + $tx->getPoints() : 0;

        return ['returned' => -$tx->getPoints(), 'shortfall' => max(0, $shortfall), 'balance' => $tx->getBalanceAfter()];
    }

    /** Ajustement manuel (responsable ou validation C3 loyalty_adjust {customerId, points}) */
    public function adjust(PosCustomer $customer, int $points, string $reason, User $actor, ?Caisse $caisse): PosLoyaltyTransaction
    {
        if ($points === 0) {
            throw new CashRegisterException('VALIDATION_FAILED', 'points : entier signé non nul attendu', 422, [['path' => 'points', 'message' => 'entier signé non nul']]);
        }
        if ($customer->getLoyaltyPoints() + $points < 0) {
            throw new CashRegisterException('LOYALTY_INSUFFICIENT', 'Le solde de points ne peut pas devenir négatif', 409, [], ['balance' => $customer->getLoyaltyPoints(), 'points' => $points]);
        }
        $this->approvals->requireFor(PosApprovalService::LOYALTY_ADJUST, $actor, ['customerId' => (int) $customer->getId(), 'points' => abs($points)], abs($points));
        $customer->setLoyaltyPoints($customer->getLoyaltyPoints() + $points)->setLoyaltyLastActivityAt(new \DateTimeImmutable())->touch();
        $tx = $this->transaction($customer, PosLoyaltyTransaction::ADJUST, $points, null, null, null, $caisse, $actor)->setReason($reason)->setApprovedBy($this->approvals->approvedBy());
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::LOYALTY_ADJUSTED, ['customerId' => $customer->getId(), 'points' => $points, 'reason' => $reason, 'balance' => $customer->getLoyaltyPoints()], $caisse, null, 'customer', $customer->getId(), $actor);

        return $tx;
    }

    /** Expiration par inactivité : solde remis à zéro après N mois sans gain ni rachat */
    public function applyExpiry(PosCustomer $customer, ?Caisse $caisse = null, ?User $user = null): void
    {
        $months = $this->settings()['expiryMonths'];
        if ($months === null || $customer->getLoyaltyPoints() <= 0 || $customer->getLoyaltyLastActivityAt() === null || $customer->getLoyaltyLastActivityAt() > new \DateTimeImmutable('-' . $months . ' months')) {
            return;
        }
        $points = $customer->getLoyaltyPoints();
        $customer->setLoyaltyPoints(0)->touch();
        $this->transaction($customer, PosLoyaltyTransaction::EXPIRE, -$points, null, null, null, $caisse, $user);
        $this->audit->record(PosAuditService::LOYALTY_EXPIRED, ['customerId' => $customer->getId(), 'points' => $points, 'inactiveSince' => $customer->getLoyaltyLastActivityAt()?->format(\DateTimeInterface::ATOM)], $caisse, null, 'customer', $customer->getId(), $user);
    }

    /** Points d'une vente (gagnés, rachetés et valeur du rachat) pour le ticket */
    public function forOrder(Order $order): array
    {
        $out = ['earned' => 0, 'redeemed' => 0, 'redeemedAmount' => 0];
        foreach ($this->emProvider->getEntityManager()->getRepository(PosLoyaltyTransaction::class)->findBy(['orderId' => $order->getId()]) as $tx) {
            if ($tx->getKind() === PosLoyaltyTransaction::EARN) {
                $out['earned'] += $tx->getPoints();
            } elseif ($tx->getKind() === PosLoyaltyTransaction::REDEEM) {
                $out['redeemed'] += -$tx->getPoints();
                $out['redeemedAmount'] += (int) $tx->getAmount();
            }
        }

        return $out;
    }

    /** Bloc fidélité d'une session (rapports X et Z) */
    public function reportBlock(int $caisseId): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT kind, COALESCE(SUM(points), 0) AS points, COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS n FROM pos_loyalty_transaction WHERE caisse_id = ? GROUP BY kind', [$caisseId]);
        $out = ['earned' => 0, 'redeemed' => ['points' => 0, 'amount' => 0, 'count' => 0], 'returned' => 0];
        foreach ($rows as $r) {
            match ($r['kind']) {
                PosLoyaltyTransaction::EARN => $out['earned'] += (int) $r['points'],
                PosLoyaltyTransaction::REDEEM => [$out['redeemed']['points'] += -(int) $r['points'], $out['redeemed']['amount'] += (int) $r['amount'], $out['redeemed']['count'] += (int) $r['n']],
                PosLoyaltyTransaction::RETURN => $out['returned'] += -(int) $r['points'],
                default => null,
            };
        }

        return $out;
    }

    private function transaction(PosCustomer $customer, string $kind, int $points, ?int $amount, ?int $orderId, ?int $returnId, ?Caisse $caisse, ?User $user): PosLoyaltyTransaction
    {
        $tx = (new PosLoyaltyTransaction($customer, $kind, $points))->setAmount($amount)->setOrderId($orderId)->setReturnId($returnId)->setCaisseId($caisse?->getId())->setUserId($user?->getId());
        $em = $this->emProvider->getEntityManager();
        $em->persist($customer);
        $em->persist($tx);
        $em->flush();

        return $tx;
    }
}
