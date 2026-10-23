<?php

namespace App\Entity;

use App\Repository\PosReturnRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Retour partiel ou échange en caisse (F2, 24/10/2026) : une commande de retour (orderType RetourClient, lignes liées aux
 * lignes vendues) rattachée à sa vente d'origine (null : retour sans ticket), motif, remise en stock, moyen et montants du
 * remboursement, paiement terminal remboursé, vente d'échange créée dans la même opération.
 */
#[ORM\Entity(repositoryClass: PosReturnRepository::class)]
#[ORM\Table(name: 'pos_return')]
#[ORM\Index(name: 'idx_pos_return_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_return_session', columns: ['caisse_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pos_return_return_order', columns: ['return_order_id'])]
class PosReturn
{
    public const METHOD_ORIGINAL = 'original';
    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_DECLARED_CARD = 'declared_card';
    public const METHOD_STORE_CREDIT = 'store_credit'; // avoir réutilisable (F9, 27/10/2026)
    public const METHODS = [self::METHOD_ORIGINAL, self::METHOD_CASH, self::METHOD_CARD, self::METHOD_DECLARED_CARD, self::METHOD_STORE_CREDIT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'return_order_id', nullable: false, onDelete: 'RESTRICT')]
    private Order $returnOrder;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'exchange_order_id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $exchangeOrder = null;

    #[ORM\ManyToOne(targetEntity: CashRegister::class)]
    #[ORM\JoinColumn(name: 'cash_register_id', nullable: false, onDelete: 'RESTRICT')]
    private CashRegister $cashRegister;

    #[ORM\Column(nullable: true)]
    private ?int $caisseId = null;

    #[ORM\Column]
    private int $userId = 0;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $reasonCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reasonText = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $restock = true;

    #[ORM\Column(length: 20)]
    private string $refundMethod = self::METHOD_ORIGINAL;

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $cashRefund = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $cardRefund = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $cashRounding = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $creditApplied = 0;

    #[ORM\Column(nullable: true)]
    private ?int $terminalPaymentId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $terminalRefundId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // Cartes-cadeaux et avoirs (F9, 27/10/2026) : part recréditée sur les cartes de la vente d'origine, avoir émis par ce retour
    #[ORM\Column(options: ['default' => 0])]
    private int $giftCardRefund = 0;

    #[ORM\Column(nullable: true)]
    private ?int $storeCreditId = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): static { $this->order = $order; return $this; }
    public function getReturnOrder(): Order { return $this->returnOrder; }
    public function setReturnOrder(Order $order): static { $this->returnOrder = $order; return $this; }
    public function getExchangeOrder(): ?Order { return $this->exchangeOrder; }
    public function setExchangeOrder(?Order $order): static { $this->exchangeOrder = $order; return $this; }
    public function getCashRegister(): CashRegister { return $this->cashRegister; }
    public function setCashRegister(CashRegister $register): static { $this->cashRegister = $register; return $this; }
    public function getCaisseId(): ?int { return $this->caisseId; }
    public function setCaisseId(?int $id): static { $this->caisseId = $id; return $this; }
    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $id): static { $this->userId = $id; return $this; }
    public function getReasonCode(): ?string { return $this->reasonCode; }
    public function setReasonCode(?string $code): static { $this->reasonCode = $code !== null ? mb_substr($code, 0, 40) : null; return $this; }
    public function getReasonText(): ?string { return $this->reasonText; }
    public function setReasonText(?string $text): static { $t = $text !== null ? trim(strip_tags($text)) : ''; $this->reasonText = $t === '' ? null : mb_substr($t, 0, 500); return $this; }
    public function isRestock(): bool { return $this->restock; }
    public function setRestock(bool $v): static { $this->restock = $v; return $this; }
    public function getRefundMethod(): string { return $this->refundMethod; }
    public function setRefundMethod(string $m): static { $this->refundMethod = $m; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $a): static { $this->amount = $a; return $this; }
    public function getCashRefund(): int { return $this->cashRefund; }
    public function setCashRefund(int $a): static { $this->cashRefund = $a; return $this; }
    public function getCardRefund(): int { return $this->cardRefund; }
    public function setCardRefund(int $a): static { $this->cardRefund = $a; return $this; }
    public function getCashRounding(): int { return $this->cashRounding; }
    public function setCashRounding(int $a): static { $this->cashRounding = $a; return $this; }
    public function getCreditApplied(): int { return $this->creditApplied; }
    public function setCreditApplied(int $a): static { $this->creditApplied = $a; return $this; }
    public function getTerminalPaymentId(): ?int { return $this->terminalPaymentId; }
    public function setTerminalPaymentId(?int $id): static { $this->terminalPaymentId = $id; return $this; }
    public function getGiftCardRefund(): int { return $this->giftCardRefund; }
    public function setGiftCardRefund(int $cents): static { $this->giftCardRefund = max(0, $cents); return $this; }
    public function getStoreCreditId(): ?int { return $this->storeCreditId; }
    public function setStoreCreditId(?int $id): static { $this->storeCreditId = $id; return $this; }
    public function getTerminalRefundId(): ?string { return $this->terminalRefundId; }
    public function setTerminalRefundId(?string $id): static { $this->terminalRefundId = $id; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
