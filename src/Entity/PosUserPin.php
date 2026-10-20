<?php

namespace App\Entity;

use App\Repository\PosUserPinRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * PIN de caisse d'un utilisateur (chantier C3, 20/10/2026), dans une table à part (la table user n'est pas touchée).
 * Empreinte = hacheur des mots de passe de User appliqué à HMAC-SHA256(PIN, secret du serveur). Blocage après 5 essais
 * faux pendant 15 minutes.
 */
#[ORM\Entity(repositoryClass: PosUserPinRepository::class)]
#[ORM\Table(name: 'pos_user_pin')]
#[ORM\UniqueConstraint(name: 'uniq_pos_user_pin_user', columns: ['user_id'])]
class PosUserPin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $pinHash;

    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $pinHash)
    {
        $this->user = $user;
        $this->pinHash = $pinHash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getPinHash(): string { return $this->pinHash; }
    public function getFailedAttempts(): int { return $this->failedAttempts; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function changePin(string $pinHash): static
    {
        $this->pinHash = $pinHash;
        $this->failedAttempts = 0;
        $this->lockedUntil = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setFailedAttempts(int $failedAttempts): static
    {
        $this->failedAttempts = $failedAttempts;

        return $this;
    }

    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): static
    {
        $this->lockedUntil = $lockedUntil;

        return $this;
    }
}
