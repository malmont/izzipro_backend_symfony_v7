<?php

namespace App\Entity;

use App\Repository\PosRentalDepositRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Caution d'une location vendue au comptoir (09/11/2026) : encaissée à la vente ou à la remise du matériel (espèces dans le tiroir,
 * carte déclarée sinon), gardée « en cours » (passif du site), puis restituée en entier ou en partie au retour du matériel (retenue
 * motivée). Les espèces passent par les mouvements de caisse 11 (encaissée) et 12 (restituée) ; les cartes sont déclarées.
 */
#[ORM\Entity(repositoryClass: PosRentalDepositRepository::class)]
#[ORM\Table(name: 'pos_rental_deposit')]
#[ORM\Index(name: 'idx_pos_rental_deposit_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_rental_deposit_status', columns: ['status'])]
class PosRentalDeposit
{
    public const HELD = 'held';
    public const RETURNED = 'returned';
    public const RETAINED = 'retained';
    public const METHODS = ['cash', 'card', 'declared_card'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'order_id')]
    private int $orderId;

    #[ORM\Column(name: 'booking_id', nullable: true)]
    private ?int $bookingId = null;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column(length: 16)]
    private string $status = self::HELD;

    #[ORM\Column(name: 'collected_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $collectedAt;

    #[ORM\Column(name: 'collected_by_id', nullable: true)]
    private ?int $collectedById = null;

    #[ORM\Column(name: 'collected_session_id', nullable: true)]
    private ?int $collectedSessionId = null;

    #[ORM\Column(name: 'cash_register_id', nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(name: 'returned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(name: 'returned_by_id', nullable: true)]
    private ?int $returnedById = null;

    #[ORM\Column(name: 'return_session_id', nullable: true)]
    private ?int $returnSessionId = null;

    #[ORM\Column(name: 'return_method', length: 16, nullable: true)]
    private ?string $returnMethod = null;

    #[ORM\Column(name: 'returned_amount', nullable: true)]
    private ?int $returnedAmount = null;

    #[ORM\Column(name: 'retained_amount')]
    private int $retainedAmount = 0;

    #[ORM\Column(name: 'retained_reason', type: Types::TEXT, nullable: true)]
    private ?string $retainedReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Paiement par terminal Stripe qui a débité la caution (method card), et remboursement Stripe de la restitution */
    #[ORM\Column(name: 'terminal_payment_id', nullable: true)]
    private ?int $terminalPaymentId = null;

    #[ORM\Column(name: 'terminal_refund_id', length: 64, nullable: true)]
    private ?string $terminalRefundId = null;

    public function __construct(int $orderId, int $amount, string $method)
    {
        $this->orderId = $orderId;
        $this->amount = $amount;
        $this->method = $method;
        $this->collectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrderId(): int { return $this->orderId; }
    public function getBookingId(): ?int { return $this->bookingId; }
    public function setBookingId(?int $id): static { $this->bookingId = $id; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function getMethod(): string { return $this->method; }
    public function getStatus(): string { return $this->status; }
    public function isHeld(): bool { return $this->status === self::HELD; }
    public function getCollectedAt(): \DateTimeImmutable { return $this->collectedAt; }
    public function getCollectedById(): ?int { return $this->collectedById; }
    public function setCollectedById(?int $id): static { $this->collectedById = $id; return $this; }
    public function getCollectedSessionId(): ?int { return $this->collectedSessionId; }
    public function setCollectedSessionId(?int $id): static { $this->collectedSessionId = $id; return $this; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function setCashRegisterId(?int $id): static { $this->cashRegisterId = $id; return $this; }
    public function getReturnedAt(): ?\DateTimeImmutable { return $this->returnedAt; }
    public function getReturnedById(): ?int { return $this->returnedById; }
    public function getReturnSessionId(): ?int { return $this->returnSessionId; }
    public function getReturnMethod(): ?string { return $this->returnMethod; }
    public function getReturnedAmount(): ?int { return $this->returnedAmount; }
    public function getRetainedAmount(): int { return $this->retainedAmount; }
    public function getRetainedReason(): ?string { return $this->retainedReason; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }
    public function getTerminalPaymentId(): ?int { return $this->terminalPaymentId; }
    public function setTerminalPaymentId(?int $id): static { $this->terminalPaymentId = $id; return $this; }
    public function getTerminalRefundId(): ?string { return $this->terminalRefundId; }
    public function setTerminalRefundId(?string $id): static { $this->terminalRefundId = $id; return $this; }

    /** Restitution (entière ou avec retenue) : fige le moyen, les montants et la session */
    public function settle(int $retained, ?string $reason, string $method, ?int $byId, ?int $sessionId): static
    {
        $this->retainedAmount = max(0, min($retained, $this->amount));
        $this->returnedAmount = $this->amount - $this->retainedAmount;
        $this->retainedReason = $reason;
        $this->returnMethod = $method;
        $this->returnedById = $byId;
        $this->returnSessionId = $sessionId;
        $this->returnedAt = new \DateTimeImmutable();
        $this->status = $this->retainedAmount > 0 ? self::RETAINED : self::RETURNED;

        return $this;
    }
}
