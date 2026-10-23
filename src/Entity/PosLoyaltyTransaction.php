<?php

namespace App\Entity;

use App\Repository\PosLoyaltyTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Mouvement de points de fidélité (F8) : gain, rachat (remise F1), reprise au retour, ajustement, expiration, fusion */
#[ORM\Entity(repositoryClass: PosLoyaltyTransactionRepository::class)]
#[ORM\Table(name: 'pos_loyalty_transaction')]
#[ORM\Index(name: 'idx_pos_loyalty_tx_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_pos_loyalty_tx_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_loyalty_tx_session', columns: ['caisse_id'])]
class PosLoyaltyTransaction
{
    public const EARN = 'earn';
    public const REDEEM = 'redeem';
    public const RETURN = 'return';
    public const ADJUST = 'adjust';
    public const EXPIRE = 'expire';
    public const MERGE_IN = 'merge_in';
    public const MERGE_OUT = 'merge_out';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PosCustomer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private PosCustomer $customer;

    #[ORM\Column(length: 12)]
    private string $kind;

    /** Signé : positif quand le solde augmente */
    #[ORM\Column]
    private int $points;

    #[ORM\Column]
    private int $balanceAfter;

    /** Valeur en cents (rachat : remise accordée ; gain : base de calcul) */
    #[ORM\Column(nullable: true)]
    private ?int $amount = null;

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column(nullable: true)]
    private ?int $returnId = null;

    #[ORM\Column(nullable: true)]
    private ?int $caisseId = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $approvedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(PosCustomer $customer, string $kind, int $points)
    {
        $this->customer = $customer;
        $this->kind = $kind;
        $this->points = $points;
        $this->balanceAfter = $customer->getLoyaltyPoints();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): PosCustomer { return $this->customer; }
    public function getKind(): string { return $this->kind; }
    public function getPoints(): int { return $this->points; }
    public function getBalanceAfter(): int { return $this->balanceAfter; }
    public function getAmount(): ?int { return $this->amount; }
    public function setAmount(?int $cents): static { $this->amount = $cents; return $this; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function setOrderId(?int $id): static { $this->orderId = $id; return $this; }
    public function getReturnId(): ?int { return $this->returnId; }
    public function setReturnId(?int $id): static { $this->returnId = $id; return $this; }
    public function getCaisseId(): ?int { return $this->caisseId; }
    public function setCaisseId(?int $id): static { $this->caisseId = $id; return $this; }
    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $id): static { $this->userId = $id; return $this; }
    public function getApprovedBy(): ?array { return $this->approvedBy; }
    public function setApprovedBy(?array $by): static { $this->approvedBy = $by; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $r): static { $this->reason = $r !== null ? mb_substr(trim($r), 0, 255) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
