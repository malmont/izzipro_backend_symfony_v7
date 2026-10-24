<?php

namespace App\Entity;

use App\Repository\PosTerminalPaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement par terminal Stripe d'une vente en caisse (C4, 23/10/2026) : un PaymentIntent (card_present + interac_present,
 * capture manual_preferred) envoyé au lecteur du poste. Statuts internes : created (intention créée), waiting_for_card
 * (action envoyée au lecteur), processing, authorized (carte de crédit autorisée, à capturer avec la vente), captured
 * (débité : Interac aussitôt, carte à l'enregistrement de la vente), failed, canceled. Rattaché à la commande (order_id)
 * quand la vente est enregistrée ; sans commande au-delà du délai réglé : « paiement sans vente ».
 */
#[ORM\Entity(repositoryClass: PosTerminalPaymentRepository::class)]
#[ORM\Table(name: 'pos_terminal_payment')]
#[ORM\UniqueConstraint(name: 'uniq_pos_terminal_payment_intent', columns: ['stripe_payment_intent_id'])]
#[ORM\Index(name: 'idx_pos_terminal_payment_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_pos_terminal_payment_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_terminal_payment_register', columns: ['cash_register_id', 'created_at'])]
class PosTerminalPayment
{
    public const CREATED = 'created';
    public const WAITING_FOR_CARD = 'waiting_for_card';
    public const PROCESSING = 'processing';
    public const AUTHORIZED = 'authorized';
    public const CAPTURED = 'captured';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';
    /** Statuts encore en vie (réconciliation) */
    public const PENDING = [self::CREATED, self::WAITING_FOR_CARD, self::PROCESSING, self::AUTHORIZED];

    public const REFUND_WAITING = 'waiting_for_card';
    public const REFUND_SUCCEEDED = 'succeeded';
    public const REFUND_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $stripePaymentIntentId = '';

    #[ORM\ManyToOne(targetEntity: CashRegister::class)]
    #[ORM\JoinColumn(name: 'cash_register_id', nullable: false, onDelete: 'RESTRICT')]
    private CashRegister $cashRegister;

    #[ORM\Column(nullable: true)]
    private ?int $caisseId = null;

    #[ORM\Column]
    private int $userId = 0;

    #[ORM\Column(nullable: true)]
    private ?int $terminalId = null;

    #[ORM\Column(length: 64)]
    private string $readerId = '';

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'cad';

    #[ORM\Column(length: 20)]
    private string $status = self::CREATED;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $paymentMethodType = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cardBrand = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $last4 = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureMessage = null;

    #[ORM\Column]
    private int $quoteTotal = 0;

    #[ORM\Column]
    private int $otherPayments = 0;

    #[ORM\Column(length: 64)]
    private string $quoteHash = '';

    #[ORM\Column]
    private int $applicationFee = 0;

    #[ORM\Column(length: 36)]
    private string $idempotencyKey = '';

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderAttachedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $refundStatus = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $refundId = null;

    #[ORM\Column(nullable: true)]
    private ?int $refundAmount = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $refundFailureMessage = null;

    // F2 (24/10/2026) : cumul remboursé (remboursements partiels des retours) et validation C3 du dernier remboursement {id, name}
    #[ORM\Column(options: ['default' => 0])]
    private int $refundedTotal = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $refundApprovedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    // Kit Stripe Terminal dans l'application (F13/F16, 31/10/2026) : canal server (lecteur piloté par le serveur) ou sdk (Tap to Pay,
    // lecteur Bluetooth : intention créée ici, carte lue et confirmée par l'application) ; charge Stripe pour les remboursements en
    // personne ; libellé du lecteur déclaré par l'application ; heure de la demande d'un remboursement Interac à présenter sur le lecteur
    public const CHANNEL_SERVER = 'server';
    public const CHANNEL_SDK = 'sdk';

    #[ORM\Column(length: 10, options: ['default' => self::CHANNEL_SERVER])]
    private string $channel = self::CHANNEL_SERVER;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeChargeId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $readerLabel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundRequestedAt = null;

    /** Non persisté : secret client du PaymentIntent, rendu à l'application pour le canal sdk tant que la carte est attendue */
    public ?string $clientSecret = null;

    public function getChannel(): string { return $this->channel; }
    public function setChannel(string $channel): static { $this->channel = $channel === self::CHANNEL_SDK ? self::CHANNEL_SDK : self::CHANNEL_SERVER; return $this; }
    public function isSdk(): bool { return $this->channel === self::CHANNEL_SDK; }
    public function getStripeChargeId(): ?string { return $this->stripeChargeId; }
    public function setStripeChargeId(?string $id): static { $this->stripeChargeId = $id; return $this; }
    public function getReaderLabel(): ?string { return $this->readerLabel; }
    public function setReaderLabel(?string $label): static { $this->readerLabel = $label !== null ? mb_substr($label, 0, 100) : null; return $this; }
    public function getRefundRequestedAt(): ?\DateTimeImmutable { return $this->refundRequestedAt; }
    public function setRefundRequestedAt(?\DateTimeImmutable $at): static { $this->refundRequestedAt = $at; return $this; }

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getStripePaymentIntentId(): string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(string $id): static { $this->stripePaymentIntentId = $id; return $this; }
    public function getCashRegister(): CashRegister { return $this->cashRegister; }
    public function setCashRegister(CashRegister $register): static { $this->cashRegister = $register; return $this; }
    public function getCaisseId(): ?int { return $this->caisseId; }
    public function setCaisseId(?int $id): static { $this->caisseId = $id; return $this; }
    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $id): static { $this->userId = $id; return $this; }
    public function getTerminalId(): ?int { return $this->terminalId; }
    public function setTerminalId(?int $id): static { $this->terminalId = $id; return $this; }
    public function getReaderId(): string { return $this->readerId; }
    public function setReaderId(string $id): static { $this->readerId = $id; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): static { $this->amount = $amount; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtolower($currency); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        if (in_array($status, [self::CAPTURED, self::FAILED, self::CANCELED], true) && $this->finishedAt === null) {
            $this->finishedAt = $this->updatedAt;
        }

        return $this;
    }
    public function isPending(): bool { return in_array($this->status, self::PENDING, true); }
    public function getPaymentMethodType(): ?string { return $this->paymentMethodType; }
    public function setPaymentMethodType(?string $type): static { $this->paymentMethodType = $type; return $this; }
    public function isInterac(): bool { return $this->paymentMethodType === 'interac_present'; }
    public function getCardBrand(): ?string { return $this->cardBrand; }
    public function setCardBrand(?string $brand): static { $this->cardBrand = $brand !== null ? mb_substr($brand, 0, 20) : null; return $this; }
    public function getLast4(): ?string { return $this->last4; }
    public function setLast4(?string $last4): static { $this->last4 = $last4 !== null ? substr($last4, -4) : null; return $this; }
    public function getFailureCode(): ?string { return $this->failureCode; }
    public function setFailureCode(?string $code): static { $this->failureCode = $code !== null ? mb_substr($code, 0, 40) : null; return $this; }
    public function getFailureMessage(): ?string { return $this->failureMessage; }
    public function setFailureMessage(?string $message): static { $this->failureMessage = $message !== null ? mb_substr($message, 0, 255) : null; return $this; }
    public function getQuoteTotal(): int { return $this->quoteTotal; }
    public function setQuoteTotal(int $total): static { $this->quoteTotal = $total; return $this; }
    public function getOtherPayments(): int { return $this->otherPayments; }
    public function setOtherPayments(int $cents): static { $this->otherPayments = $cents; return $this; }
    public function getQuoteHash(): string { return $this->quoteHash; }
    public function setQuoteHash(string $hash): static { $this->quoteHash = $hash; return $this; }
    public function getApplicationFee(): int { return $this->applicationFee; }
    public function setApplicationFee(int $fee): static { $this->applicationFee = $fee; return $this; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function setIdempotencyKey(string $key): static { $this->idempotencyKey = $key; return $this; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function attachOrder(int $orderId): static { $this->orderId = $orderId; $this->orderAttachedAt = new \DateTimeImmutable(); return $this; }
    public function getOrderAttachedAt(): ?\DateTimeImmutable { return $this->orderAttachedAt; }
    public function getRefundStatus(): ?string { return $this->refundStatus; }
    public function setRefundStatus(?string $status): static { $this->refundStatus = $status; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getRefundId(): ?string { return $this->refundId; }
    public function setRefundId(?string $id): static { $this->refundId = $id; return $this; }
    public function getRefundAmount(): ?int { return $this->refundAmount; }
    public function setRefundAmount(?int $amount): static { $this->refundAmount = $amount; return $this; }
    public function getRefundFailureMessage(): ?string { return $this->refundFailureMessage; }
    public function getRefundedTotal(): int { return $this->refundedTotal; }
    public function addRefunded(int $amount): static { $this->refundedTotal += max(0, $amount); return $this; }
    public function getRefundable(): int { return max(0, $this->amount - $this->refundedTotal); }
    public function getRefundApprovedBy(): ?array { return $this->refundApprovedBy; }
    public function setRefundApprovedBy(?array $who): static { $this->refundApprovedBy = $who; return $this; }
    public function setRefundFailureMessage(?string $message): static { $this->refundFailureMessage = $message !== null ? mb_substr($message, 0, 255) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
}
