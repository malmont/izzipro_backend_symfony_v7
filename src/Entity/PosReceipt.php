<?php

namespace App\Entity;

use App\Repository\PosReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Numéro de ticket ou d'avoir d'une opération de caisse (chantier C2, 19/10/2026) : deux suites continues sans trou par
 * poste (tickets : ventes au comptoir ; avoirs : remboursements et retours), attribuées sous verrou de la ligne du
 * poste dans la transaction de l'opération. Aucun accesseur d'écriture.
 */
#[ORM\Entity(repositoryClass: PosReceiptRepository::class)]
#[ORM\Table(name: 'pos_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_pos_receipt_number', columns: ['cash_register_id', 'kind', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_pos_receipt_order', columns: ['order_id', 'kind'])]
class PosReceipt
{
    public const TICKET = 'ticket';
    public const CREDIT_NOTE = 'credit_note';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CashRegister::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CashRegister $cashRegister;

    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\Column]
    private int $number;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Caisse::class)]
    #[ORM\JoinColumn(name: 'caisse_id', nullable: true)]
    private ?Caisse $session;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // Ticket numérique (F3, 24/10/2026) : réimpressions, version du lien public (révocation = version suivante)
    #[ORM\Column(options: ['default' => 0])]
    private int $reprintCount = 0;

    #[ORM\Column(options: ['default' => 1])]
    private int $linkVersion = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $linkRevokedAt = null;

    public function __construct(CashRegister $cashRegister, string $kind, int $number, Order $order, ?Caisse $session, int $amount)
    {
        $this->cashRegister = $cashRegister;
        $this->kind = $kind;
        $this->number = $number;
        $this->order = $order;
        $this->session = $session;
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCashRegister(): CashRegister { return $this->cashRegister; }
    public function getKind(): string { return $this->kind; }
    public function getNumber(): int { return $this->number; }
    public function getOrder(): Order { return $this->order; }
    public function getSession(): ?Caisse { return $this->session; }
    public function getAmount(): int { return $this->amount; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReprintCount(): int { return $this->reprintCount; }
    public function addReprint(): static { $this->reprintCount++; return $this; }
    public function getLinkVersion(): int { return $this->linkVersion; }
    public function getLinkRevokedAt(): ?\DateTimeImmutable { return $this->linkRevokedAt; }
    /** Révoque les liens déjà imprimés (version suivante) ; le prochain lien émis sera valide */
    public function revokeLink(): static { $this->linkVersion++; $this->linkRevokedAt = new \DateTimeImmutable(); return $this; }
}
