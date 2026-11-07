<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderReturn;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\PosReturnService\PosReturnService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Psr\Log\LoggerInterface;

/**
 * Retours web, lot 7 (07/11/2026) : un client rend un ou plusieurs articles d'une commande web ; le marchand saisit les lignes et quantités
 * depuis la fiche de la commande. Montant = prix payé des unités rendues (net des remises) plus leur part des taxes de la commande, au
 * prorata et en cumul comme les retours de caisse (`PosReturnService::taxForQuantity`) ; les frais de livraison ne sont jamais repris
 * (« Rembourser » sert pour un geste). Le remboursement est réel chez Stripe (`OrderOperationsService::refund`), le stock est remis si demandé,
 * le client reçoit un courriel `order_returned`. Un échange web = un retour puis une nouvelle commande du client. Les ventes au comptoir
 * ont les retours de la caisse (`pos_return`).
 */
class OrderReturnService
{
    public const MESSAGES = [
        'COUNTER_SALE' => 'Vente au comptoir : les retours se font depuis la caisse.',
        'ORDER_CANCELLED' => 'Commande annulée : son paiement est déjà remboursé.',
        'NO_ONLINE_PAYMENT' => 'Aucun paiement par carte capturé : rien à rembourser en ligne.',
        'NOT_PAID' => 'Commande non payée : rien à retourner.',
        'NOTHING_TO_RETURN' => 'Tous les articles ont déjà été retournés.',
        'LINES_REQUIRED' => 'Indiquez au moins un article et une quantité à retourner.',
        'LINE_UNKNOWN' => 'Ligne inconnue sur cette commande.',
        'QUANTITY_EXCEEDED' => 'Quantité supérieure à ce qui reste à retourner.',
        'NOTHING_REFUNDABLE' => 'Le montant du retour dépasse ce qui reste remboursable : remboursez le reste depuis « Rembourser ».',
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly OrderOperationsService $operations,
        private readonly UpdateStockAndInventoryUseCase $stock,
        private readonly OrderMailerService $mailer,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Raison pour laquelle aucun retour n'est possible, null sinon */
    public function blocker(Order $order): ?string
    {
        if ($order->getOrderSource()?->getId() === 2) {
            return 'COUNTER_SALE';
        }
        $status = (int) ($order->getStatus()?->getId() ?? 0);
        if ($status === OrderShipmentService::STATUS_CANCELLED) {
            return 'ORDER_CANCELLED';
        }
        if (in_array($status, [1, 2], true)) {
            return 'NOT_PAID';
        }
        $money = $this->operations->money($order);
        if ($money['captured'] === []) {
            return 'NO_ONLINE_PAYMENT';
        }
        if (array_sum(array_column($this->lines($order), 'returnableQuantity')) === 0) {
            return 'NOTHING_TO_RETURN';
        }

        return null;
    }

    /**
     * Lignes de la commande avec ce qui a déjà été retourné et le montant de la prochaine unité.
     * @return list<array{orderItemId: int, name: string, quantity: int, returnedQuantity: int, returnableQuantity: int, unitRefundable: int, hasVariant: bool}>
     */
    public function lines(Order $order): array
    {
        $returned = $this->repository()->returnedQuantities($order);
        $out = [];
        foreach ($order->getOrderItems() as $item) {
            $qty = (int) $item->getQuantity();
            $before = $returned[$item->getId()] ?? 0;
            $out[] = [
                'orderItemId' => (int) $item->getId(), 'name' => (string) ($item->displayName() ?? ''), 'quantity' => $qty, 'returnedQuantity' => $before,
                'returnableQuantity' => max(0, $qty - $before), 'unitRefundable' => $this->amountFor($order, $item, $before, 1), 'hasVariant' => $item->getProductVariant() !== null,
            ];
        }

        return $out;
    }

    /** @return OrderReturn[] */
    public function returns(Order $order): array
    {
        return $this->repository()->findForOrder($order);
    }

    /**
     * Crée le retour : remboursement Stripe, écriture, remise en stock, courriel, journal.
     * @param list<array{orderItemId: int|string, quantity: int|string}> $lines
     * @return array{status: int, body: array<string, mixed>}
     */
    public function create(Order $order, array $lines, string $reason, bool $restock, bool $notify, ?string $by, string $domain): array
    {
        $blocker = $this->blocker($order);
        if ($blocker !== null) {
            return $this->refuse($blocker);
        }
        $known = [];
        foreach ($this->lines($order) as $l) {
            $known[$l['orderItemId']] = $l;
        }
        $items = [];
        foreach ($order->getOrderItems() as $item) {
            $items[(int) $item->getId()] = $item;
        }
        $wanted = [];
        foreach ($lines as $l) {
            $id = (int) ($l['orderItemId'] ?? 0);
            $qty = (int) ($l['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            if (!isset($known[$id])) {
                return $this->refuse('LINE_UNKNOWN', ['orderItemId' => $id]);
            }
            if ($qty > $known[$id]['returnableQuantity']) {
                return $this->refuse('QUANTITY_EXCEEDED', ['orderItemId' => $id, 'requested' => $qty, 'returnable' => $known[$id]['returnableQuantity']]);
            }
            $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
            if ($wanted[$id] > $known[$id]['returnableQuantity']) {
                return $this->refuse('QUANTITY_EXCEEDED', ['orderItemId' => $id, 'requested' => $wanted[$id], 'returnable' => $known[$id]['returnableQuantity']]);
            }
        }
        if ($wanted === []) {
            return $this->refuse('LINES_REQUIRED');
        }
        $returnLines = [];
        $amount = 0;
        foreach ($wanted as $id => $qty) {
            $lineAmount = $this->amountFor($order, $items[$id], $known[$id]['returnedQuantity'], $qty);
            $returnLines[] = ['orderItemId' => $id, 'name' => $known[$id]['name'], 'quantity' => $qty, 'amountCents' => $lineAmount];
            $amount += $lineAmount;
        }
        $money = $this->operations->money($order);
        if ($amount <= 0 || $money['refundableCents'] <= 0) {
            return $this->refuse('NOTHING_REFUNDABLE', ['amountCents' => $amount, 'refundableCents' => $money['refundableCents']]);
        }
        $amount = min($amount, (int) $money['refundableCents']); // un geste antérieur a pu entamer le remboursable : on rend ce qui reste
        $refund = $this->operations->refund($order, $amount, $reason !== '' ? 'Retour : ' . $reason : 'Retour d\'articles', false, $by, $domain);
        if ($refund['status'] !== 200) {
            return $refund;
        }
        $em = $this->emProvider->getEntityManager();
        $return = (new OrderReturn($order))->setLines($returnLines)->setAmount((int) $refund['body']['amountCents'])->setRestock($restock)->setReason($reason)->setRefunds($refund['body']['refunds'])->setCreatedBy($by);
        $em->persist($return);
        $restocked = [];
        if ($restock) {
            foreach ($wanted as $id => $qty) {
                $variant = $items[$id]->getProductVariant();
                if ($variant === null) {
                    continue; // article libre ou service : aucun stock
                }
                $this->stock->execute($variant, $qty, true);
                $restocked[] = ['orderItemId' => $id, 'quantity' => $qty];
            }
        }
        $em->flush();
        $notified = $notify ? $this->mailer->sendOperationNotice($order, 'order_returned', ['amountCents' => $return->getAmount(), 'reason' => $reason, 'lines' => $returnLines], $domain) : false;
        $this->audit->record('order', (int) $order->getId(), 'return', ['returned' => array_values($this->repository()->returnedQuantities($order))], ['returnId' => (int) $return->getId(), 'lines' => $returnLines, 'amountCents' => $return->getAmount(), 'restock' => $restock, 'restocked' => $restocked, 'reason' => mb_substr($reason, 0, 500), 'by' => $by, 'notified' => $notified], ['returns']);
        $this->logger->info(sprintf('[Retours] commande %s : retour n° %d de %d cents (%s)', $order->getReference(), $return->getId(), $return->getAmount(), $by ?? '-'));

        return ['status' => 201, 'body' => ['return' => $return->toArray(), 'refund' => $refund['body'], 'restocked' => $restocked, 'notified' => $notified]];
    }

    /** Montant (net payé + taxes de la ligne au prorata, cumul exact) des $qty prochaines unités d'une ligne dont $before sont déjà rendues */
    private function amountFor(Order $order, OrderItems $item, int $before, int $qty): int
    {
        $sold = (int) $item->getQuantity();
        $lineNet = (int) round((float) $item->getTotalPrice());
        $amount = PosReturnService::taxForQuantity($lineNet, $sold, $before, $qty);
        if ($order->isPricesIncludeTax()) {
            return $amount; // TTC : la taxe est dans le net payé
        }
        foreach ($order->getOrderTaxes() as $orderTax) {
            $category = $orderTax->getTax()?->getCategory();
            if (!$item->isTaxable() || !TaxCategory::applies($category, $item->getTaxCategory())) {
                continue;
            }
            $pool = (int) array_sum(array_map(fn (OrderItems $i) => $i->isTaxable() && TaxCategory::applies($category, $i->getTaxCategory()) ? (int) round((float) $i->getTotalPrice()) : 0, $order->getOrderItems()->toArray()));
            $lineTax = $pool > 0 ? (int) round((int) round((float) $orderTax->getAmount()) * $lineNet / $pool) : 0;
            $amount += PosReturnService::taxForQuantity($lineTax, $sold, $before, $qty);
        }

        return $amount;
    }

    private function repository(): \App\Repository\OrderReturnRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(OrderReturn::class);
    }

    private function refuse(string $code, array $extra = []): array
    {
        return ['status' => in_array($code, ['LINE_UNKNOWN', 'QUANTITY_EXCEEDED', 'LINES_REQUIRED'], true) ? 422 : 409, 'body' => ['error' => $code, 'message' => self::MESSAGES[$code]] + $extra];
    }
}
