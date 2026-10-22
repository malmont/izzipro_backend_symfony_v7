<?php

namespace App\Entity;

use App\Repository\PosOfflineSaleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vente hors ligne reçue d'un terminal (C5 partie 2, 25/10/2026) : clé d'idempotence liée au terminal, numéro provisoire,
 * résultat rendu (accepted | rejected) rejoué à l'identique pour une clé déjà vue, chaînage du journal local.
 */
#[ORM\Entity(repositoryClass: PosOfflineSaleRepository::class)]
#[ORM\Table(name: 'pos_offline_sale')]
#[ORM\UniqueConstraint(name: 'uniq_pos_offline_sale_key', columns: ['terminal_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_pos_offline_sale_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_offline_sale_terminal', columns: ['terminal_id', 'created_at'])]
class PosOfflineSale
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $terminalId = 0;

    #[ORM\Column(length: 36)]
    private string $idempotencyKey = '';

    #[ORM\Column(length: 40)]
    private string $provisionalNumber = '';

    #[ORM\Column(nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column(length: 12)]
    private string $status = 'rejected';

    #[ORM\Column(type: Types::JSON)]
    private array $result = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $packVersion = null;

    #[ORM\Column(nullable: true)]
    private ?int $chainSeq = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $chainHash = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $priceDrift = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $stockShortfall = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $lateEntry = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $terminalId, string $idempotencyKey, string $provisionalNumber)
    {
        $this->terminalId = $terminalId;
        $this->idempotencyKey = $idempotencyKey;
        $this->provisionalNumber = $provisionalNumber;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTerminalId(): int { return $this->terminalId; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getProvisionalNumber(): string { return $this->provisionalNumber; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function setCashRegisterId(?int $id): static { $this->cashRegisterId = $id; return $this; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function setOrderId(?int $id): static { $this->orderId = $id; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getResult(): array { return $this->result; }
    public function setResult(array $r): static { $this->result = $r; return $this; }
    public function getPackVersion(): ?string { return $this->packVersion; }
    public function setPackVersion(?string $v): static { $this->packVersion = $v !== null ? mb_substr($v, 0, 64) : null; return $this; }
    public function getChainSeq(): ?int { return $this->chainSeq; }
    public function setChainSeq(?int $s): static { $this->chainSeq = $s; return $this; }
    public function getChainHash(): ?string { return $this->chainHash; }
    public function setChainHash(?string $h): static { $this->chainHash = $h !== null ? substr($h, 0, 64) : null; return $this; }
    public function getPriceDrift(): int { return $this->priceDrift; }
    public function setPriceDrift(int $d): static { $this->priceDrift = $d; return $this; }
    public function getStockShortfall(): int { return $this->stockShortfall; }
    public function setStockShortfall(int $n): static { $this->stockShortfall = $n; return $this; }
    public function isLateEntry(): bool { return $this->lateEntry; }
    public function setLateEntry(bool $l): static { $this->lateEntry = $l; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
