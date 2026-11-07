<?php

namespace App\Entity;

use App\Repository\OrderReturnRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Retour web (lot 7, 07/11/2026) : un ou plusieurs articles d'une commande web rendus par le client, remboursés chez Stripe au prix payé
 * (taxes au prorata) depuis le back-office ; `lines` = [{orderItemId, name, quantity, amountCents}]. Les retours de caisse ont `pos_return`.
 */
#[ORM\Entity(repositoryClass: OrderReturnRepository::class)]
#[ORM\Table(name: 'order_return')]
class OrderReturn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    /** @var list<array{orderItemId: int, name: string, quantity: int, amountCents: int}> */
    #[ORM\Column(type: 'json')]
    private array $lines = [];

    #[ORM\Column(type: 'integer')]
    private int $amount = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $restock = true;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reason = null;

    /** @var list<array<string, mixed>>|null remboursements Stripe [{refundId, amountCents, intentId}] */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $refunds = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $createdBy = null;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getLines(): array { return $this->lines; }
    public function setLines(array $lines): static { $this->lines = array_values($lines); return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): static { $this->amount = $amount; return $this; }
    public function isRestock(): bool { return $this->restock; }
    public function setRestock(bool $restock): static { $this->restock = $restock; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null; return $this; }
    public function getRefunds(): ?array { return $this->refunds; }
    public function setRefunds(?array $refunds): static { $this->refunds = $refunds; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $by): static { $this->createdBy = $by; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => (int) $this->id, 'createdAt' => $this->createdAt->format('Y-m-d H:i'), 'amountCents' => $this->amount, 'restock' => $this->restock, 'reason' => $this->reason, 'lines' => $this->lines, 'by' => $this->createdBy];
    }
}
