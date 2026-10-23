<?php

namespace App\Entity;

use App\Repository\PosGiftCardTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Mouvement d'une carte-cadeau ou d'un avoir (F9) : émission, utilisation, recrédit (retour), remboursement en espèces, ajustement, annulation, remplacement */
#[ORM\Entity(repositoryClass: PosGiftCardTransactionRepository::class)]
#[ORM\Table(name: 'pos_gift_card_transaction')]
#[ORM\Index(name: 'idx_pos_gift_card_tx_card', columns: ['gift_card_id'])]
#[ORM\Index(name: 'idx_pos_gift_card_tx_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_gift_card_tx_session', columns: ['caisse_id'])]
class PosGiftCardTransaction
{
    public const ISSUE = 'issue';
    public const REDEEM = 'redeem';
    public const REFUND = 'refund'; // recrédit lors d'un retour
    public const CASH_OUT = 'cash_out';
    public const ADJUST = 'adjust';
    public const CANCEL = 'cancel';
    public const REPLACE_OUT = 'replace_out';
    public const REPLACE_IN = 'replace_in';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PosGiftCard::class)]
    #[ORM\JoinColumn(name: 'gift_card_id', nullable: false, onDelete: 'CASCADE')]
    private PosGiftCard $giftCard;

    #[ORM\Column(length: 12)]
    private string $kind;

    /** Signé : positif quand le solde augmente */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private int $balanceAfter;

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column(nullable: true)]
    private ?int $returnId = null;

    #[ORM\Column(nullable: true)]
    private ?int $caisseId = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(nullable: true)]
    private ?int $terminalId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $approvedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(PosGiftCard $card, string $kind, int $amount)
    {
        $this->giftCard = $card;
        $this->kind = $kind;
        $this->amount = $amount;
        $this->balanceAfter = $card->getBalance();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getGiftCard(): PosGiftCard { return $this->giftCard; }
    public function getKind(): string { return $this->kind; }
    public function getAmount(): int { return $this->amount; }
    public function getBalanceAfter(): int { return $this->balanceAfter; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function setOrderId(?int $id): static { $this->orderId = $id; return $this; }
    public function getReturnId(): ?int { return $this->returnId; }
    public function setReturnId(?int $id): static { $this->returnId = $id; return $this; }
    public function getCaisseId(): ?int { return $this->caisseId; }
    public function setCaisseId(?int $id): static { $this->caisseId = $id; return $this; }
    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $id): static { $this->userId = $id; return $this; }
    public function getTerminalId(): ?int { return $this->terminalId; }
    public function setTerminalId(?int $id): static { $this->terminalId = $id; return $this; }
    public function getApprovedBy(): ?array { return $this->approvedBy; }
    public function setApprovedBy(?array $by): static { $this->approvedBy = $by; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason !== null ? mb_substr(trim($reason), 0, 255) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
