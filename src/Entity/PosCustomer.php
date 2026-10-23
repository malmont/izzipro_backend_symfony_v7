<?php

namespace App\Entity;

use App\Repository\PosCustomerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fiche client de caisse (lot F8, 28/10/2026) : distincte du compte web (`user`), rattachable à un compte existant
 * (user_id) avec le consentement du client ; données minimales (nom, téléphone, courriel), code de fidélité, points.
 * Effacement = anonymisation (erased_at), les ventes restent rattachées.
 */
#[ORM\Entity(repositoryClass: PosCustomerRepository::class)]
#[ORM\Table(name: 'pos_customer')]
#[ORM\Index(name: 'idx_pos_customer_phone', columns: ['phone'])]
#[ORM\Index(name: 'idx_pos_customer_email', columns: ['email'])]
#[ORM\Index(name: 'idx_pos_customer_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pos_customer_loyalty_code', columns: ['loyalty_code'])]
class PosCustomer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $lastName = null;

    /** Téléphone normalisé : chiffres seulement */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    /** Courriel en minuscules */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** Compte web rattaché (consentement account_link) ; rien n'est écrit sur ce compte */
    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $loyaltyCode = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $loyaltyPoints = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $loyaltyLastActivityAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdById = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $erasedAt = null;

    /** Fiche fusionnée dans une autre (doublon) */
    #[ORM\Column(nullable: true)]
    private ?int $mergedIntoId = null;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $v): static { $this->firstName = self::clean($v, 80); return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $v): static { $this->lastName = self::clean($v, 80); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $digits): static { $this->phone = $digits !== null && $digits !== '' ? mb_substr($digits, 0, 20) : null; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email !== null && $email !== '' ? mb_substr(mb_strtolower(trim($email)), 0, 180) : null; return $this; }
    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $id): static { $this->userId = $id; return $this; }
    public function getLoyaltyCode(): ?string { return $this->loyaltyCode; }
    public function setLoyaltyCode(?string $code): static { $this->loyaltyCode = $code; return $this; }
    public function getLoyaltyPoints(): int { return $this->loyaltyPoints; }
    public function setLoyaltyPoints(int $points): static { $this->loyaltyPoints = max(0, $points); return $this; }
    public function getLoyaltyLastActivityAt(): ?\DateTimeImmutable { return $this->loyaltyLastActivityAt; }
    public function setLoyaltyLastActivityAt(?\DateTimeImmutable $at): static { $this->loyaltyLastActivityAt = $at; return $this; }
    public function getCreatedById(): ?int { return $this->createdById; }
    public function setCreatedById(?int $id): static { $this->createdById = $id; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getErasedAt(): ?\DateTimeImmutable { return $this->erasedAt; }
    public function setErasedAt(?\DateTimeImmutable $at): static { $this->erasedAt = $at; return $this; }
    public function isErased(): bool { return $this->erasedAt !== null; }
    public function getMergedIntoId(): ?int { return $this->mergedIntoId; }
    public function setMergedIntoId(?int $id): static { $this->mergedIntoId = $id; return $this; }
    public function isActive(): bool { return $this->erasedAt === null && $this->mergedIntoId === null; }
    public function displayName(): string { return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? '')) ?: ($this->isErased() ? 'Client effacé' : 'Client'); }

    private static function clean(?string $v, int $max): ?string
    {
        $v = $v !== null ? trim(strip_tags($v)) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
