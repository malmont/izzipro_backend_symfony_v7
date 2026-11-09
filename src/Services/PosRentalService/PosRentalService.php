<?php

namespace App\Services\PosRentalService;

use App\Dto\ProductCommerceDto;
use App\Entity\Booking;
use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\PosRentalDeposit;
use App\Entity\PosTerminalPayment;
use App\Entity\Product;
use App\Entity\User;
use App\Services\CaisseService\CaisseService;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\MediaUrlResolver;
use App\Services\OrderService\RentalLineResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;

/**
 * Location au comptoir (09/11/2026) : catalogue des locations pour la caisse (en ligne seulement), réservations confirmées par la vente,
 * caution encaissée (espèces dans le tiroir, carte sur le lecteur Stripe ou carte déclarée) et restituée en entier ou avec retenue motivée
 * (validation d'un responsable comme un remboursement), retour du matériel, annulation et retours qui libèrent les créneaux. Les montants
 * sont en cents. Contrat : docs/caisse.md « Location au comptoir et caution ».
 */
class PosRentalService
{
    public const BOOKING_CONFIRMED = 'CONFIRMED';
    public const BOOKING_CANCELLED = 'CANCELLED';
    public const BOOKING_RETURNED = 'RETURNED';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly CaisseService $sessions,
        private readonly HandleCaisseTransactionUseCase $caisseTransactions,
        private readonly PosAuditService $audit,
        private readonly PosApprovalService $approvals,
        private readonly PosTerminalPaymentService $terminalPayments,
        private readonly ?\App\Services\Booking\BookingClock $clock = null, // dates au fuseau de la boutique (09/11/2026)
    ) {
    }

    /** Date au fuseau de la boutique avec son décalage (ATOM), comme le devis et la commande ; UTC sans horloge */
    private function at(?\DateTimeInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return $this->clock !== null ? $this->clock->format($at) : $at->format(\DateTimeInterface::ATOM);
    }

    /** @return array{id: int, name: ?string, phone: ?string}|null client de caisse rattaché à la vente */
    private function customerBlock(?Order $order): ?array
    {
        if ($order?->getCustomerId() === null) {
            return null;
        }
        $customer = $this->emProvider->getEntityManager()->find(\App\Entity\PosCustomer::class, $order->getCustomerId());

        return ['id' => $order->getCustomerId(), 'name' => $customer !== null ? trim(implode(' ', array_filter([$customer->getFirstName(), $customer->getLastName()]))) ?: null : null, 'phone' => $customer?->getPhone()];
    }

    // ---------------------------------------------------------------- catalogue

    /**
     * Produits louables vendables en caisse, avec le même bloc `rental` que la fiche produit (jamais dans le pack hors ligne).
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(string $productsBaseUrl, string $locale = 'fr'): array
    {
        $em = $this->emProvider->getEntityManager();
        $out = [];
        foreach ($em->getRepository(Product::class)->findBy(['isPos' => true], ['id' => 'ASC']) as $product) {
            if (!$product->isRentalEnabled() || $product->getBookingConfiguration() === null) {
                continue;
            }
            $variants = [];
            foreach ($product->getVariants() as $variant) {
                $variants[] = ['id' => (int) $variant->getId(), 'label' => trim(implode(' / ', array_filter([$variant->getColor()?->getName(), $variant->getSize()?->getName()]))) ?: null, 'barcode' => $variant->getBarcode()];
            }
            $out[] = [
                'id' => (int) $product->getId(), 'code' => $product->getCode(), 'barcode' => $product->getBarcode(), 'name' => $product->getName(),
                'image' => MediaUrlResolver::joinStored($product->getImage(), $productsBaseUrl),
                'categoryIds' => array_values(array_map(fn ($c) => (int) $c->getId(), $product->getCategory()->toArray())),
                'variants' => $variants,
                'rental' => ProductCommerceDto::bookingConfig($product, $locale),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- vente

    /**
     * Bloc `deposit` de create-multi-payment, contrôlé avant toute écriture : { amount (cents), method (cash | card | declared_card),
     * cashDetails?, terminalPaymentId? (method card) }. Null sans caution.
     *
     * @param list<array<string, mixed>> $items
     * @return array{amount: int, method: string, cashDetails: array, terminalPaymentId: int|string|null}|null
     */
    public function checkDepositInput(mixed $deposit, array $items, int $orderSource, int $typeOrder): ?array
    {
        if ($deposit === null || $deposit === []) {
            return null;
        }
        if (!is_array($deposit) || $orderSource !== 2 || $typeOrder !== 1) {
            throw new CashRegisterException('DEPOSIT_INVALID', 'Une caution n\'accompagne qu\'une vente au comptoir : deposit { amount, method }', 422, [['path' => 'deposit', 'message' => 'objet attendu']]);
        }
        $amount = is_numeric($deposit['amount'] ?? null) ? (int) round((float) $deposit['amount']) : 0;
        $method = (string) ($deposit['method'] ?? '');
        $errors = [];
        if ($amount <= 0) {
            $errors[] = ['path' => 'deposit.amount', 'message' => 'montant en cents, supérieur à 0'];
        }
        if (!in_array($method, PosRentalDeposit::METHODS, true)) {
            $errors[] = ['path' => 'deposit.method', 'message' => 'cash, card ou declared_card attendu'];
        }
        if ($method === 'card' && !isset($deposit['terminalPaymentId'])) {
            $errors[] = ['path' => 'deposit.terminalPaymentId', 'message' => 'paiement par terminal requis pour une caution par carte (sinon declared_card)'];
        }
        if ($errors !== []) {
            throw new CashRegisterException('DEPOSIT_INVALID', 'Caution invalide : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], 422, $errors);
        }
        if (!$this->hasRentalLine($items)) {
            throw new CashRegisterException('DEPOSIT_WITHOUT_RENTAL', 'Une caution exige une ligne de location (booking { start, end }) dans la vente', 422, [['path' => 'deposit', 'message' => 'aucune ligne de location']]);
        }

        return ['amount' => $amount, 'method' => $method, 'cashDetails' => is_array($deposit['cashDetails'] ?? null) ? $deposit['cashDetails'] : [], 'terminalPaymentId' => $deposit['terminalPaymentId'] ?? null];
    }

    /** @param list<array<string, mixed>> $items */
    public function hasRentalLine(array $items): bool
    {
        $em = $this->emProvider->getEntityManager();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['productVariantId'])) {
                continue;
            }
            $variant = $em->getRepository(\App\Entity\ProductVariant::class)->find((int) $item['productVariantId']);
            if ($variant !== null && RentalLineResolver::isRental($variant->getProduct(), $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dans la transaction d'une vente au comptoir : réservations confirmées, puis caution encaissée s'il y en a une.
     *
     * @param array{amount: int, method: string, cashDetails: array, terminalPaymentId: int|string|null}|null $deposit
     */
    public function afterSale(Order $order, ?User $user, Caisse $caisse, ?array $deposit): void
    {
        $bookings = $this->bookingsOf($order);
        if ($bookings === []) {
            return;
        }
        foreach ($bookings as $booking) {
            $booking->setStatus(self::BOOKING_CONFIRMED);
        }
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::RENTAL_BOOKING_CONFIRMED, [
            'reference' => $order->getReference(),
            'bookings' => array_map(fn (Booking $b) => ['bookingId' => $b->getId(), 'productId' => $b->getProduct()?->getId(), 'start' => $this->at($b->getStartAt()), 'end' => $this->at($b->getEndAt()), 'quantity' => $b->getQuantity()], $bookings),
        ], $caisse, null, 'order', $order->getId(), $user);
        if ($deposit !== null) {
            $this->collect($order, $user, $caisse, $deposit, $bookings[0]);
        }
    }

    /**
     * Encaisse une caution (à la vente ou après coup) : espèces dans le tiroir (mouvement 11), carte débitée sur le lecteur et rattachée,
     * ou carte déclarée. Une seule caution en cours par commande.
     *
     * @param array{amount: int, method: string, cashDetails: array, terminalPaymentId: int|string|null} $deposit
     */
    public function collect(Order $order, ?User $user, Caisse $caisse, array $deposit, ?Booking $booking = null): PosRentalDeposit
    {
        $em = $this->emProvider->getEntityManager();
        if ($em->getRepository(PosRentalDeposit::class)->heldForOrder((int) $order->getId()) !== null) {
            throw new CashRegisterException('DEPOSIT_ALREADY_HELD', 'Une caution est déjà en cours sur cette commande', 409, [], ['orderId' => $order->getId()]);
        }
        $entity = new PosRentalDeposit((int) $order->getId(), $deposit['amount'], $deposit['method']);
        $entity->setBookingId($booking?->getId() ?? ($this->bookingsOf($order)[0] ?? null)?->getId())->setCollectedById($user?->getId())->setCollectedSessionId($caisse->getId())->setCashRegisterId($caisse->getCashRegister()?->getId());
        $terminal = null;
        if ($deposit['method'] === 'card') {
            $terminal = $this->terminalPayments->verifyForSale($deposit['terminalPaymentId'], $deposit['amount'], $caisse->getCashRegister(), PosTerminalPayment::PURPOSE_RENTAL_DEPOSIT);
            $this->terminalPayments->captureForSale($terminal, $order);
            $entity->setTerminalPaymentId($terminal->getId());
        }
        $em->persist($entity);
        $em->flush();
        if ($user !== null) {
            $cash = $deposit['method'] === 'cash' ? $deposit['amount'] : 0;
            $this->caisseTransactions->execute($caisse, $order, $user, $deposit['amount'], HandleCaisseTransactionUseCase::RENTAL_DEPOSIT, $deposit['cashDetails'], $cash, null);
        }
        $this->audit->record(PosAuditService::RENTAL_DEPOSIT_COLLECTED, [
            'depositId' => $entity->getId(), 'reference' => $order->getReference(), 'amount' => $entity->getAmount(), 'method' => $entity->getMethod(), 'bookingId' => $entity->getBookingId(),
            'terminalPaymentId' => $terminal?->getId(), 'stripePaymentIntentId' => $terminal?->getStripePaymentIntentId(),
        ], $caisse, null, 'rental_deposit', $entity->getId(), $user);

        return $entity;
    }

    // ---------------------------------------------------------------- restitution, retour du matériel

    /**
     * Restitue une caution en cours, en entier ou avec une retenue motivée (validation d'un responsable au seuil « remboursement »).
     * Espèces : mouvement 12 (tiroir) ; carte du lecteur : remboursement Stripe du montant rendu ; carte déclarée : à rembourser sur
     * le terminal non relié. Finalise les réservations de la commande (matériel rendu).
     *
     * @return array<string, mixed> caution + reçu de restitution
     */
    public function returnDeposit(PosRentalDeposit $d, User $user, ?int $cashRegisterId, int $retained, ?string $reason, ?string $method, ?string $terminalRefundId, ?string $posKey, ?string $approvalToken = null): array
    {
        if (!$d->isHeld()) {
            throw new CashRegisterException('DEPOSIT_NOT_HELD', 'Cette caution a déjà été restituée', 409, [], ['depositId' => $d->getId(), 'status' => $d->getStatus()]);
        }
        $retained = max(0, $retained);
        if ($retained > $d->getAmount()) {
            throw new CashRegisterException('RETENTION_INVALID', 'La retenue dépasse la caution', 422, [['path' => 'retainedAmount', 'message' => sprintf('au plus %d', $d->getAmount())]]);
        }
        if ($retained > 0 && trim((string) $reason) === '') {
            throw new CashRegisterException('RETENTION_REASON_REQUIRED', 'Une retenue sur caution exige un motif', 422, [['path' => 'reason', 'message' => 'requis']]);
        }
        $method = $method ?? $d->getMethod();
        if (!in_array($method, PosRentalDeposit::METHODS, true)) {
            throw new CashRegisterException('DEPOSIT_INVALID', 'method : cash, card ou declared_card attendu', 422, [['path' => 'method', 'message' => 'cash, card ou declared_card']]);
        }
        if ($method === 'card' && $d->getTerminalPaymentId() === null) {
            throw new CashRegisterException('DEPOSIT_INVALID', 'Cette caution n\'a pas été prise sur le lecteur : restituez en espèces ou en carte déclarée', 422, [['path' => 'method', 'message' => 'pas de paiement par terminal']]);
        }
        if ($retained > 0) {
            $this->approvals->requireFor(PosApprovalService::REFUND, $user, ['depositId' => (int) $d->getId(), 'amount' => $retained], $retained, $approvalToken);
        }
        $caisse = $this->registers->requireOpenSession($cashRegisterId ?? $d->getCashRegisterId(), 409);
        $em = $this->emProvider->getEntityManager();
        $order = $em->find(Order::class, $d->getOrderId());
        $returned = $d->getAmount() - $retained;
        $terminal = null;
        $em->getConnection()->beginTransaction();
        try {
            if ($method === 'card' && $returned > 0) {
                $terminal = $this->terminalPayments->find((int) $d->getTerminalPaymentId());
                $d->setTerminalRefundId($this->terminalPayments->refundForReturn($terminal, $returned, $terminalRefundId, $user, $posKey !== null ? $posKey . '-deposit' : null));
            }
            $d->settle($retained, $retained > 0 ? trim((string) $reason) : null, $method, $user->getId(), $caisse->getId());
            $em->flush();
            if ($returned > 0) {
                $this->caisseTransactions->execute($caisse, $order, $user, $returned, HandleCaisseTransactionUseCase::RENTAL_DEPOSIT_RETURN, [], $method === 'cash' ? $returned : 0, null);
            }
            if ($order !== null) {
                $this->finalizeBookings($order);
            }
            $this->audit->record(PosAuditService::RENTAL_DEPOSIT_RETURNED, [
                'depositId' => $d->getId(), 'reference' => $order?->getReference(), 'amount' => $d->getAmount(), 'returnedAmount' => $returned, 'retainedAmount' => $retained, 'reason' => $d->getRetainedReason(),
                'method' => $method, 'terminalRefundId' => $d->getTerminalRefundId(),
            ] + ($this->approvals->approvedBy() !== null ? ['approvedBy' => $this->approvals->approvedBy()] : []), $caisse, null, 'rental_deposit', $d->getId(), $user);
            $em->getConnection()->commit();
        } catch (\Throwable $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            throw $e;
        }

        return $this->toArray($d) + ['receipt' => ['kind' => 'deposit_return', 'amount' => $d->getAmount(), 'returnedAmount' => $returned, 'retainedAmount' => $retained, 'reason' => $d->getRetainedReason(), 'method' => $method,
            'terminal' => $terminal !== null ? $this->terminalBlock($terminal) : null, 'action' => $this->action($method), 'returnedAt' => $d->getReturnedAt()?->format(\DateTimeInterface::ATOM), 'cashRegister' => ['id' => $caisse->getCashRegister()?->getId(), 'name' => $caisse->getCashRegister()?->getName()]]];
    }

    /** Matériel rendu sans caution (ou caution déjà réglée) : la réservation est finalisée */
    public function returnBooking(Booking $booking, User $user, ?int $cashRegisterId): array
    {
        if ($booking->getStatus() === self::BOOKING_CANCELLED) {
            throw new CashRegisterException('BOOKING_CANCELLED', 'Cette réservation est annulée', 409, [], ['bookingId' => $booking->getId()]);
        }
        if ($booking->isFinalized() && $booking->getStatus() === self::BOOKING_RETURNED) {
            throw new CashRegisterException('BOOKING_ALREADY_RETURNED', 'Ce matériel est déjà rendu', 409, [], ['bookingId' => $booking->getId()]);
        }
        $caisse = $this->registers->openSession($this->registers->resolve($cashRegisterId));
        $booking->setStatus(self::BOOKING_RETURNED)->setIsFinalized(true);
        $this->emProvider->getEntityManager()->flush();
        $order = $booking->getOrderItem()?->getOrderAssociated();
        $this->audit->record(PosAuditService::RENTAL_RETURNED, ['bookingId' => $booking->getId(), 'reference' => $order?->getReference(), 'productId' => $booking->getProduct()?->getId()], $caisse, null, 'booking', $booking->getId(), $user);

        return $this->bookingArray($booking);
    }

    // ---------------------------------------------------------------- annulation, retours

    /** Toute annulation de commande (web ou caisse) libère ses créneaux */
    public function cancelBookings(Order $order): void
    {
        foreach ($this->bookingsOf($order) as $booking) {
            if ($booking->getStatus() !== self::BOOKING_CANCELLED) {
                $booking->setStatus(self::BOOKING_CANCELLED);
            }
        }
    }

    /**
     * Annulation d'une vente au comptoir : la caution en cours est restituée en entier par son moyen d'encaissement, dans la même
     * transaction (espèces : tiroir, 400 INSUFFICIENT_FUNDS si le tiroir ne couvre pas ; carte du lecteur : remboursement Stripe ;
     * carte déclarée : à rembourser sur le terminal non relié).
     *
     * @return array<string, mixed>|null
     */
    public function onCancelDeposit(Order $order, ?User $user, Caisse $caisse, ?string $terminalRefundId): ?array
    {
        $em = $this->emProvider->getEntityManager();
        $d = $em->getRepository(PosRentalDeposit::class)->heldForOrder((int) $order->getId());
        if ($d === null) {
            return null;
        }
        $terminal = null;
        if ($d->getMethod() === 'card' && $d->getTerminalPaymentId() !== null && $user !== null) {
            $terminal = $this->terminalPayments->find((int) $d->getTerminalPaymentId());
            $d->setTerminalRefundId($this->terminalPayments->refundForReturn($terminal, $d->getAmount(), $terminalRefundId, $user, null));
        }
        $d->settle(0, null, $d->getMethod(), $user?->getId(), $caisse->getId());
        $em->flush();
        if ($user !== null) {
            $this->caisseTransactions->execute($caisse, $order, $user, $d->getAmount(), HandleCaisseTransactionUseCase::RENTAL_DEPOSIT_RETURN, [], $d->getMethod() === 'cash' ? $d->getAmount() : 0, null);
        }
        $this->audit->record(PosAuditService::RENTAL_DEPOSIT_RETURNED, ['depositId' => $d->getId(), 'reference' => $order->getReference(), 'amount' => $d->getAmount(), 'returnedAmount' => $d->getAmount(), 'retainedAmount' => 0, 'method' => $d->getMethod(), 'cause' => 'order_cancelled', 'terminalRefundId' => $d->getTerminalRefundId()], $caisse, null, 'rental_deposit', $d->getId(), $user);

        return $this->toArray($d) + ['action' => $this->action($d->getMethod()), 'terminal' => $terminal !== null ? $this->terminalBlock($terminal) : null];
    }

    /** Retour partiel (F2) d'une ligne de location : réservation réduite à la quantité restante, annulée si tout est rendu */
    public function onReturnLine(OrderItems $sold, int $quantity): void
    {
        $booking = $sold->getBooking();
        if ($booking === null || $quantity <= 0) {
            return;
        }
        $remaining = (int) $booking->getQuantity() - $quantity;
        if ($remaining <= 0) {
            $booking->setStatus(self::BOOKING_CANCELLED);
        } else {
            $booking->setQuantity($remaining);
        }
    }

    // ---------------------------------------------------------------- lecture

    /** @return list<array<string, mixed>> */
    public function deposits(?string $status, ?int $cashRegisterId): array
    {
        $criteria = [];
        if ($status !== null && in_array($status, [PosRentalDeposit::HELD, PosRentalDeposit::RETURNED, PosRentalDeposit::RETAINED], true)) {
            $criteria['status'] = $status;
        }
        if ($cashRegisterId !== null) {
            $criteria['cashRegisterId'] = $cashRegisterId;
        }

        return array_map(fn (PosRentalDeposit $d) => $this->toArray($d), $this->emProvider->getEntityManager()->getRepository(PosRentalDeposit::class)->findBy($criteria, ['id' => 'DESC'], 200));
    }

    public function deposit(int $id): PosRentalDeposit
    {
        return $this->emProvider->getEntityManager()->find(PosRentalDeposit::class, $id)
            ?? throw new CashRegisterException('DEPOSIT_NOT_FOUND', 'Caution introuvable', 404, [], ['depositId' => $id]);
    }

    public function booking(int $id): Booking
    {
        return $this->emProvider->getEntityManager()->find(Booking::class, $id)
            ?? throw new CashRegisterException('BOOKING_NOT_FOUND', 'Réservation introuvable', 404, [], ['bookingId' => $id]);
    }

    /** Locations en cours vendues au comptoir : réservations confirmées, non rendues */
    public function ongoing(?int $cashRegisterId): array
    {
        $em = $this->emProvider->getEntityManager();
        $rows = $em->getConnection()->fetchAllAssociative("SELECT b.id FROM booking b JOIN order_items i ON i.id = b.order_item_id JOIN \"order\" o ON o.id = i.order_associated_id WHERE b.status = ? AND b.is_finalized = FALSE AND o.order_source_id = 2" . ($cashRegisterId !== null ? ' AND o.pos_cash_register_id = ?' : '') . ' ORDER BY b.start_at ASC, b.id ASC LIMIT 300', $cashRegisterId !== null ? [self::BOOKING_CONFIRMED, $cashRegisterId] : [self::BOOKING_CONFIRMED]);
        $out = [];
        foreach ($rows as $row) {
            $booking = $em->find(Booking::class, (int) $row['id']);
            if ($booking !== null) {
                $out[] = $this->bookingArray($booking);
            }
        }

        return $out;
    }

    /** Bloc `rental` du ticket : lignes de location (dates) et cautions de la commande ; null sans location */
    public function receiptBlock(Order $order): ?array
    {
        $lines = [];
        foreach ($order->getOrderItems() as $item) {
            $booking = $item->getBooking();
            if ($booking === null) {
                continue;
            }
            $lines[] = ['orderItemId' => $item->getId(), 'bookingId' => $booking->getId(), 'productName' => $item->displayName(), 'start' => $this->at($booking->getStartAt()), 'end' => $this->at($booking->getEndAt()), 'quantity' => $booking->getQuantity(), 'status' => $booking->getStatus()];
        }
        $deposits = array_map(fn (PosRentalDeposit $d) => $this->toArray($d), $this->emProvider->getEntityManager()->getRepository(PosRentalDeposit::class)->forOrder((int) $order->getId()));
        if ($lines === [] && $deposits === []) {
            return null;
        }

        return ['lines' => $lines, 'deposits' => $deposits];
    }

    /** @return array<string, mixed> */
    public function toArray(PosRentalDeposit $d): array
    {
        $em = $this->emProvider->getEntityManager();
        $order = $em->find(Order::class, $d->getOrderId());
        $booking = $d->getBookingId() !== null ? $em->find(Booking::class, $d->getBookingId()) : null;
        $receipt = $order !== null ? $em->getRepository(\App\Entity\PosReceipt::class)->findOneBy(['order' => $order, 'kind' => \App\Entity\PosReceipt::TICKET]) : null;

        return [
            'id' => $d->getId(), 'orderId' => $d->getOrderId(), 'reference' => $order?->getReference(),
            'receiptReference' => $receipt !== null ? $receipt->getCashRegister()->receiptReference($receipt->getNumber()) : null,
            'customer' => $this->customerBlock($order),
            'product' => $booking?->getProduct() !== null ? ['id' => $booking->getProduct()->getId(), 'name' => $booking->getProduct()->getName()] : null,
            'booking' => $booking !== null ? ['id' => $booking->getId(), 'start' => $this->at($booking->getStartAt()), 'end' => $this->at($booking->getEndAt()), 'quantity' => $booking->getQuantity(), 'status' => $booking->getStatus()] : null,
            'amount' => $d->getAmount(), 'method' => $d->getMethod(), 'status' => $d->getStatus(),
            'collectedAt' => $d->getCollectedAt()->format(\DateTimeInterface::ATOM), 'cashRegisterId' => $d->getCashRegisterId(),
            'returnedAt' => $d->getReturnedAt()?->format(\DateTimeInterface::ATOM), 'returnMethod' => $d->getReturnMethod(), 'returnedAmount' => $d->getReturnedAmount(),
            'retainedAmount' => $d->getRetainedAmount(), 'retainedReason' => $d->getRetainedReason(),
            'terminalPaymentId' => $d->getTerminalPaymentId(), 'terminalRefundId' => $d->getTerminalRefundId(),
        ];
    }

    /** @return array<string, mixed> */
    public function bookingArray(Booking $booking): array
    {
        $item = $booking->getOrderItem();
        $order = $item?->getOrderAssociated();
        $em = $this->emProvider->getEntityManager();
        $deposit = $order !== null ? ($em->getRepository(PosRentalDeposit::class)->heldForOrder((int) $order->getId()) ?? ($em->getRepository(PosRentalDeposit::class)->forOrder((int) $order->getId())[0] ?? null)) : null;
        $receipt = $order !== null ? $em->getRepository(\App\Entity\PosReceipt::class)->findOneBy(['order' => $order, 'kind' => \App\Entity\PosReceipt::TICKET]) : null;

        return [
            'bookingId' => $booking->getId(), 'orderId' => $order?->getId(), 'reference' => $order?->getReference(),
            'receiptReference' => $receipt !== null ? $receipt->getCashRegister()->receiptReference($receipt->getNumber()) : null,
            'product' => $booking->getProduct() !== null ? ['id' => $booking->getProduct()->getId(), 'name' => $booking->getProduct()->getName()] : null,
            'variant' => $item?->getProductVariant() !== null ? ['id' => $item->getProductVariant()->getId(), 'label' => $item->getVariantLabel()] : null,
            'start' => $this->at($booking->getStartAt()), 'end' => $this->at($booking->getEndAt()), 'quantity' => $booking->getQuantity(),
            'status' => $booking->getStatus(), 'finalized' => $booking->isFinalized(),
            'customer' => $this->customerBlock($order),
            'expectedDeposit' => (int) ($booking->getProduct()?->getBookingConfiguration()?->getDeposit() ?? 0) * max(1, (int) $booking->getQuantity()), // caution prévue par la fiche (09/11/2026)
            'deposit' => $deposit !== null ? ['id' => $deposit->getId(), 'amount' => $deposit->getAmount(), 'method' => $deposit->getMethod(), 'status' => $deposit->getStatus()] : null,
        ];
    }

    // ---------------------------------------------------------------- interne

    /** @return list<Booking> */
    private function bookingsOf(Order $order): array
    {
        $out = [];
        foreach ($order->getOrderItems() as $item) {
            if ($item->getBooking() !== null) {
                $out[] = $item->getBooking();
            }
        }

        return $out;
    }

    private function finalizeBookings(Order $order): void
    {
        foreach ($this->bookingsOf($order) as $booking) {
            if ($booking->getStatus() !== self::BOOKING_CANCELLED) {
                $booking->setStatus(self::BOOKING_RETURNED)->setIsFinalized(true);
            }
        }
        $this->emProvider->getEntityManager()->flush();
    }

    private function action(string $method): string
    {
        return match ($method) { 'cash' => 'cash_returned', 'card' => 'refunded_on_terminal', default => 'declared' };
    }

    /** @return array<string, mixed> */
    private function terminalBlock(PosTerminalPayment $p): array
    {
        return ['paymentId' => $p->getId(), 'refundId' => $p->getRefundId(), 'paymentMethodType' => $p->getPaymentMethodType(), 'cardBrand' => $p->getCardBrand(), 'last4' => $p->getLast4()];
    }
}
