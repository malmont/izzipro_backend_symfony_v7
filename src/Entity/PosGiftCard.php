<?php

namespace App\Entity;

use App\Repository\PosGiftCardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Carte-cadeau ou avoir réutilisable (lot F9, 27/10/2026). Seule l'empreinte SHA-256 du code est gardée (code affiché une
 * fois à l'émission, 4 derniers caractères ensuite). Le solde est un passif du site : la vente d'une carte n'est pas un
 * chiffre d'affaires (commande de type 3 « CarteCadeau »), les taxes sont perçues à l'utilisation.
 */
#[ORM\Entity(repositoryClass: PosGiftCardRepository::class)]
#[ORM\Table(name: 'pos_gift_card')]
#[ORM\UniqueConstraint(name: 'uniq_pos_gift_card_code', columns: ['code_hash'])]
#[ORM\Index(name: 'idx_pos_gift_card_order', columns: ['issued_order_id'])]
#[ORM\Index(name: 'idx_pos_gift_card_status', columns: ['status'])]
class PosGiftCard
{
    public const GIFT_CARD = 'gift_card';
    public const STORE_CREDIT = 'store_credit';
    public const ACTIVE = 'active';
    public const DEPLETED = 'depleted';
    public const CANCELED = 'canceled';
    public const REPLACED = 'replaced';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column(length: 4)]
    private string $last4;

    #[ORM\Column]
    private int $initialAmount;

    #[ORM\Column]
    private int $balance;

    #[ORM\Column(length: 12)]
    private string $status = self::ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** Commande d'émission (vente de la carte, type 3) ; null pour un avoir */
    #[ORM\Column(nullable: true)]
    private ?int $issuedOrderId = null;

    /** Retour d'émission (avoir) */
    #[ORM\Column(nullable: true)]
    private ?int $issuedReturnId = null;

    #[ORM\Column(nullable: true)]
    private ?int $issuedById = null;

    #[ORM\Column(nullable: true)]
    private ?int $cashRegisterId = null;

    /** Carte remplacée par celle-ci (carte perdue) / carte qui remplace celle-ci */
    #[ORM\Column(nullable: true)]
    private ?int $replacesId = null;

    #[ORM\Column(nullable: true)]
    private ?int $replacedById = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    /** Client de caisse rattaché (F8) */
    #[ORM\Column(nullable: true)]
    private ?int $customerId = null;

    public function __construct(string $kind, string $codeHash, string $last4, int $amount)
    {
        $this->kind = $kind;
        $this->codeHash = $codeHash;
        $this->last4 = $last4;
        $this->initialAmount = $amount;
        $this->balance = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getCodeHash(): string { return $this->codeHash; }
    public function getLast4(): string { return $this->last4; }
    public function getInitialAmount(): int { return $this->initialAmount; }
    public function getBalance(): int { return $this->balance; }
    public function setBalance(int $balance): static { $this->balance = max(0, $balance); if ($this->status === self::ACTIVE || $this->status === self::DEPLETED) { $this->status = $this->balance > 0 ? self::ACTIVE : self::DEPLETED; } return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $at): static { $this->expiresAt = $at; return $this; }
    public function isExpired(?\DateTimeImmutable $now = null): bool { return $this->expiresAt !== null && $this->expiresAt <= ($now ?? new \DateTimeImmutable()); }
    public function getIssuedOrderId(): ?int { return $this->issuedOrderId; }
    public function setIssuedOrderId(?int $id): static { $this->issuedOrderId = $id; return $this; }
    public function getIssuedReturnId(): ?int { return $this->issuedReturnId; }
    public function setIssuedReturnId(?int $id): static { $this->issuedReturnId = $id; return $this; }
    public function getIssuedById(): ?int { return $this->issuedById; }
    public function setIssuedById(?int $id): static { $this->issuedById = $id; return $this; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function setCashRegisterId(?int $id): static { $this->cashRegisterId = $id; return $this; }
    public function getReplacesId(): ?int { return $this->replacesId; }
    public function setReplacesId(?int $id): static { $this->replacesId = $id; return $this; }
    public function getReplacedById(): ?int { return $this->replacedById; }
    public function setReplacedById(?int $id): static { $this->replacedById = $id; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCanceledAt(): ?\DateTimeImmutable { return $this->canceledAt; }
    public function getCustomerId(): ?int { return $this->customerId; }
    public function setCustomerId(?int $id): static { $this->customerId = $id; return $this; }
    public function setCanceledAt(?\DateTimeImmutable $at): static { $this->canceledAt = $at; return $this; }
    /** Utilisable comme moyen de paiement : active, solde positif, non expirée */
    public function isUsable(?\DateTimeImmutable $now = null): bool { return $this->status === self::ACTIVE && $this->balance > 0 && !$this->isExpired($now); }
}
