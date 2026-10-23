<?php

namespace App\Services\CaisseService;

use App\Dto\StoreInfoDto;
use App\Entity\Caisse;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\TransactionCaisse;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase as Tx;

/**
 * Chiffres d'une session de caisse (19/10/2026) : rapport X (session en cours, calculé à la lecture) et contenu figé du
 * rapport Z (même calcul au moment de la fermeture). Montants en CENTS. Sources : transactions de la session (montant
 * de la vente, effet sur le tiroir `cashAmount`, arrondi `cashRounding`), paiements et taxes des commandes concernées.
 * Les annulations remettent aujourd'hui les taxes de la commande à 0 : `taxes` est donc net des annulations faites
 * pendant la session (une vraie contre-écriture viendra avec le journal d'audit, chantier C2).
 */
class CashSessionReportBuilder
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array<string, mixed> */
    public function build(Caisse $session, string $locale = 'fr'): array
    {
        $em = $this->emProvider->getEntityManager();
        /** @var list<TransactionCaisse> $transactions */
        $transactions = $em->createQueryBuilder()->select('t')->from(TransactionCaisse::class, 't')
            ->andWhere('t.caisse = :session')->setParameter('session', $session)->orderBy('t.id', 'ASC')->getQuery()->getResult();

        $opening = null;
        $sales = ['count' => 0, 'total' => 0, 'cash' => 0];
        $refunds = ['count' => 0, 'total' => 0, 'cash' => 0];
        $movements = ['deposits' => 0, 'withdrawals' => 0, 'fundDeposits' => 0, 'fundWithdrawals' => 0];
        $rounding = ['count' => 0, 'total' => 0];
        $saleOrders = [];
        $refundOrders = [];
        $cashFlow = 0;
        foreach ($transactions as $t) {
            $type = (int) $t->getTransactionType()->getId();
            $cash = $t->getCashAmount() ?? $this->legacyCashAmount($t, $type);
            if ($type !== Tx::OPENING && $type !== Tx::CLOSING) {
                $cashFlow += $cash;
            }
            if ($t->getCashRounding()) {
                $rounding['count']++;
                $rounding['total'] += $t->getCashRounding();
            }
            switch ($type) {
                case Tx::OPENING:
                    $opening ??= $t;
                    break;
                case Tx::SALE:
                    $sales['count']++;
                    $sales['total'] += (int) round((float) $t->getAmount());
                    $sales['cash'] += $cash;
                    if ($t->getOrderCaisse()) {
                        $saleOrders[$t->getOrderCaisse()->getId()] = $t->getOrderCaisse();
                    }
                    break;
                case Tx::REFUND:
                    $refunds['count']++;
                    $refunds['total'] += (int) round(abs((float) $t->getAmount()));
                    $refunds['cash'] += -$cash;
                    if ($t->getOrderCaisse()) {
                        $refundOrders[$t->getOrderCaisse()->getId()] = $t->getOrderCaisse();
                    }
                    break;
                case Tx::DEPOSIT:
                    $movements['deposits'] += $cash;
                    break;
                case Tx::WITHDRAWAL:
                    $movements['withdrawals'] += -$cash;
                    break;
                case Tx::CASH_FUND_DEPOSIT:
                    $movements['fundDeposits'] += $cash;
                    break;
                case Tx::CASH_FUND_WITHDRAWAL:
                    $movements['fundWithdrawals'] += -$cash;
                    break;
            }
        }

        $openingAmount = $opening ? (int) round((float) $opening->getAmount()) : 0;
        $register = $session->getCashRegister();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $sales['byPaymentMethod'] = $this->byPaymentMethod($saleOrders, fn (float $amount) => $amount > 0, $locale);
        $refunds['byPaymentMethod'] = $this->byPaymentMethod($refundOrders, fn (float $amount) => $amount < 0, $locale);

        return [
            'cashRegister' => $register ? ['id' => $register->getId(), 'name' => $register->getName()] : null,
            'sessionId' => $session->getId(),
            'openedAt' => $session->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'openedBy' => self::user($opening?->getUserCaisse()),
            'openingAmount' => $openingAmount,
            'openingFund' => (int) round((float) ($session->getFonDeCaisse() ?? 0)) - $movements['fundDeposits'] + $movements['fundWithdrawals'],
            'currentFund' => (int) round((float) ($session->getFonDeCaisse() ?? 0)),
            'sales' => $sales,
            'taxes' => $this->taxes($saleOrders + $refundOrders),
            'refunds' => $refunds,
            'discounts' => $this->discounts($saleOrders), // remises de caisse (F1, 24/10/2026)
            'movements' => $movements,
            'cashRounding' => $rounding,
            // Tickets et avoirs de la session (C2) : {first, last, count}
            'receipts' => $em->getRepository(\App\Entity\PosReceipt::class)->rangeForSession($session, \App\Entity\PosReceipt::TICKET),
            'creditNotes' => $em->getRepository(\App\Entity\PosReceipt::class)->rangeForSession($session, \App\Entity\PosReceipt::CREDIT_NOTE),
            'expectedCash' => $openingAmount + $cashFlow,
            // Lot R1 (23/10/2026) : alerte « trop d'espèces » selon le seuil du site (null sans seuil), préfixe des numéros du poste
            'cashAlert' => ($entreprise?->getPosCashAlertThreshold() ?? null) !== null ? ($openingAmount + $cashFlow) > $entreprise->getPosCashAlertThreshold() : null,
            'receiptPrefix' => $register?->getReceiptPrefix(),
            'discrepancyThreshold' => $entreprise?->getPosDiscrepancyThreshold() ?? 0,
            'store' => StoreInfoDto::fromEntreprise($entreprise),
            // Terminal de paiement Stripe (C4, 23/10/2026) : ajout, ventilation par moyen inchangée
            'cardTerminal' => $this->cardTerminal($saleOrders, $register),
            // Retours partiels et échanges (F2, 24/10/2026) : ajout ; les remboursements correspondants sont déjà dans refunds
            'returns' => $em->getRepository(\App\Entity\PosReturn::class)->totalsForSession((int) $session->getId()),
            // Ventes hors ligne synchronisées (C5 partie 2, 25/10/2026) : ajout
            'offline' => $this->offline($saleOrders),
            'customItems' => $this->customItems($saleOrders), // articles libres (F7, 26/10/2026)
            'priceOverrides' => $this->priceOverrides($saleOrders), // prix modifiés (F7)
        ];
    }

    /** @return array{count: int, total: int, priceDrift: int, stockShortfalls: int, lateEntries: int} ventes hors ligne de la session */
    private function offline(array $saleOrders): array
    {
        $out = ['count' => 0, 'total' => 0, 'priceDrift' => 0, 'stockShortfalls' => 0, 'lateEntries' => 0];
        $ids = array_keys(array_filter($saleOrders, fn (Order $o) => $o->getSyncedAt() !== null));
        if ($ids === []) {
            return $out;
        }
        foreach ($this->emProvider->getEntityManager()->getRepository(\App\Entity\PosOfflineSale::class)->findBy(['orderId' => $ids]) as $sale) {
            $out['count']++;
            $out['total'] += (int) round((float) $saleOrders[$sale->getOrderId()]->getTotalAmount());
            $out['priceDrift'] += abs($sale->getPriceDrift());
            $out['stockShortfalls'] += $sale->getStockShortfall();
            $out['lateEntries'] += $sale->isLateEntry() ? 1 : 0;
        }

        return $out;
    }

    /** @return array{count: int, total: int} lignes d'articles libres et leur total net (F7) */
    private function customItems(array $saleOrders): array
    {
        $count = $total = 0;
        foreach ($saleOrders as $order) {
            foreach ($order->getOrderItems() as $item) {
                if ($item->isCustom()) {
                    $count++;
                    $total += (int) round((float) $item->getTotalPrice());
                }
            }
        }

        return ['count' => $count, 'total' => $total];
    }

    /** @return array{count: int, catalogTotal: int, total: int, difference: int} lignes à prix manuel : prix catalogue, prix appliqué (brut × quantité), écart (F7) */
    private function priceOverrides(array $saleOrders): array
    {
        $out = ['count' => 0, 'catalogTotal' => 0, 'total' => 0, 'difference' => 0];
        foreach ($saleOrders as $order) {
            foreach ($order->getOrderItems() as $item) {
                if ($item->hasPriceOverride()) {
                    $qty = (int) $item->getQuantity();
                    $out['count']++;
                    $out['catalogTotal'] += (int) $item->getCatalogUnitPrice() * $qty;
                    $out['total'] += (int) round((float) $item->getUnitPrice()) * $qty;
                    $out['difference'] += ((int) $item->getCatalogUnitPrice() - (int) round((float) $item->getUnitPrice())) * $qty;
                }
            }
        }

        return $out;
    }

    /** @return array{count: int, total: int} ventes remisées et total des remises (lignes, remise de ticket répartie comprise) */
    private function discounts(array $saleOrders): array
    {
        $count = $total = 0;
        foreach ($saleOrders as $order) {
            $sum = (int) array_sum(array_map(fn ($i) => $i->getDiscountAmount(), $order->getOrderItems()->toArray()));
            if ($sum > 0) {
                $count++;
                $total += $sum;
            }
        }

        return ['count' => $count, 'total' => $total];
    }

    /** Parts carte des ventes de la session : terminal (carte de crédit), Interac, carte déclarée ; paiements sans vente du poste */
    private function cardTerminal(array $saleOrders, ?\App\Entity\CashRegister $register): array
    {
        $em = $this->emProvider->getEntityManager();
        $out = ['terminal' => 0, 'interac' => 0, 'declared' => 0, 'count' => 0, 'withoutSale' => 0];
        $byOrder = [];
        foreach ($em->getRepository(\App\Entity\PosTerminalPayment::class)->findByOrderIds(array_keys($saleOrders)) as $p) {
            $byOrder[$p->getOrderId()] = ($byOrder[$p->getOrderId()] ?? 0) + $p->getAmount();
            $out[$p->isInterac() ? 'interac' : 'terminal'] += $p->getAmount();
            $out['count']++;
        }
        foreach ($saleOrders as $id => $order) {
            foreach ($order->getPayments() as $payment) {
                $amount = (int) round((float) $payment->getAmount());
                if ($payment->getPaymentMethod()?->getId() === 1 && $amount > 0) {
                    $out['declared'] += max(0, $amount - ($byOrder[$id] ?? 0));
                    $byOrder[$id] = max(0, ($byOrder[$id] ?? 0) - $amount);
                }
            }
        }
        if ($register) {
            $out['withoutSale'] = count($em->getRepository(\App\Entity\PosTerminalPayment::class)->findOrphans($em->getRepository(Entreprise::class)->findOneBy([])?->getPosTerminalOrphanMinutes() ?? 5, $register));
        }

        return $out;
    }

    public static function user(?User $user): ?array
    {
        return $user ? ['id' => $user->getId(), 'email' => $user->getEmail(), 'name' => trim($user->getFirstname() . ' ' . $user->getLastname())] : null;
    }

    /** Transactions antérieures au 19/10/2026 sans effet espèces enregistré (normalement repris par la migration) */
    private function legacyCashAmount(TransactionCaisse $t, int $type): int
    {
        $amount = (int) round(abs((float) $t->getAmount()));

        return match ($type) {
            Tx::DEPOSIT, Tx::CASH_FUND_DEPOSIT => $amount,
            Tx::WITHDRAWAL, Tx::CASH_FUND_WITHDRAWAL => -$amount,
            Tx::SALE => $this->cashPayments($t->getOrderCaisse(), true),
            Tx::REFUND => $this->cashPayments($t->getOrderCaisse(), false),
            default => 0,
        };
    }

    private function cashPayments(?Order $order, bool $positive): int
    {
        $total = 0;
        foreach ($order?->getPayments() ?? [] as $payment) {
            $amount = (float) $payment->getAmount();
            if ($payment->getPaymentMethod()?->getId() === 2 && ($positive ? $amount > 0 : $amount < 0)) {
                $total += (int) round($amount);
            }
        }

        return $total;
    }

    /**
     * @param array<int, Order> $orders
     * @return list<array{type: ?int, label: ?string, count: int, total: int}>
     */
    private function byPaymentMethod(array $orders, callable $keep, string $locale): array
    {
        $groups = [];
        foreach ($orders as $order) {
            /** @var Payments $payment */
            foreach ($order->getPayments() as $payment) {
                $amount = (float) $payment->getAmount();
                if (!$keep($amount)) {
                    continue;
                }
                $method = $payment->getPaymentMethod();
                $key = $method?->getId() ?? 0;
                $groups[$key] ??= ['type' => $method?->getId(), 'label' => $method ? ($method->getTranslation($locale)?->getName() ?? $method->getName()) : null, 'count' => 0, 'total' => 0];
                $groups[$key]['count']++;
                $groups[$key]['total'] += (int) round(abs($amount));
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * @param array<int, Order> $orders
     * @return list<array{label: string, rate: ?float, amount: int}>
     */
    private function taxes(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            foreach ($order->getOrderTaxes() as $orderTax) {
                $tax = $orderTax->getTax();
                $key = ($tax?->getName() ?? 'Taxe') . '|' . ($tax?->getRate() ?? '');
                $groups[$key] ??= ['label' => $tax?->getName() ?? 'Taxe', 'rate' => $tax ? (float) $tax->getRate() : null, 'amount' => 0];
                $groups[$key]['amount'] += (int) round((float) $orderTax->getAmount());
            }
        }

        return array_values($groups);
    }
}
