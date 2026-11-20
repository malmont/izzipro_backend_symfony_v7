<?php

namespace App\Abo\Entity;

use App\Abo\Repository\BenefitSlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un créneau daté d'une prestation (lot ABO-2, 20/11/2026) : né d'une règle (unicité règle + début) ou ponctuel (règle vide) ; début et fin
 * en UTC ; capacité ; lieu et intervenant ; `open` ou `cancelled` (annulé par la boutique : ses droits redeviennent à planifier).
 */
#[ORM\Entity(repositoryClass: BenefitSlotRepository::class)]
#[ORM\Table(name: 'benefit_slot')]
class BenefitSlot
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Benefit::class)]
    #[ORM\JoinColumn(name: 'benefit_id', nullable: false, onDelete: 'CASCADE')]
    private ?Benefit $benefit = null;

    #[ORM\ManyToOne(targetEntity: BenefitSlotRule::class)]
    #[ORM\JoinColumn(name: 'rule_id', nullable: true, onDelete: 'SET NULL')]
    private ?BenefitSlotRule $rule = null;

    #[ORM\Column(name: 'starts_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column]
    private int $capacity = 1;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(name: 'staff_user_id', nullable: true)]
    private ?int $staffUserId = null;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'cancel_reason', length: 300, nullable: true)]
    private ?string $cancelReason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->startsAt = new \DateTimeImmutable();
        $this->endsAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBenefit(): ?Benefit { return $this->benefit; }
    public function setBenefit(?Benefit $b): static { $this->benefit = $b; return $this; }
    public function getRule(): ?BenefitSlotRule { return $this->rule; }
    public function setRule(?BenefitSlotRule $r): static { $this->rule = $r; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeInterface $at): static { $this->startsAt = \DateTimeImmutable::createFromInterface($at); return $this; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeInterface $at): static { $this->endsAt = \DateTimeImmutable::createFromInterface($at); return $this; }
    public function getCapacity(): int { return $this->capacity; }
    public function setCapacity(int $c): static { $this->capacity = $c; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $l): static { $l = trim(strip_tags((string) $l)); $this->location = $l !== '' ? mb_substr($l, 0, BenefitSlotRule::LOCATION_LENGTH) : null; return $this; }
    public function getStaffUserId(): ?int { return $this->staffUserId; }
    public function setStaffUserId(?int $id): static { $this->staffUserId = $id; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeInterface $at): static { $this->cancelledAt = $at !== null ? \DateTimeImmutable::createFromInterface($at) : null; return $this; }
    public function getCancelReason(): ?string { return $this->cancelReason; }
    public function setCancelReason(?string $r): static { $r = trim(strip_tags((string) $r)); $this->cancelReason = $r !== '' ? mb_substr($r, 0, 300) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string
    {
        return sprintf('%s, %s UTC', $this->benefit?->getName('fr') ?? '?', $this->startsAt->format('Y-m-d H:i'));
    }
}
