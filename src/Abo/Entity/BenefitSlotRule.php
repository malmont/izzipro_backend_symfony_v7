<?php

namespace App\Abo\Entity;

use App\Abo\Repository\BenefitSlotRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Créneaux récurrents d'une prestation (lot ABO-2, 20/11/2026) : jours de la semaine (1 = lundi … 7 = dimanche, ISO), heure LOCALE du
 * magasin (« HH:MM »), durée, capacité, lieu, intervenant (compte du site, facultatif), du / au (dates du magasin, bornes comprises).
 * Les créneaux datés (`BenefitSlot`) en naissent 8 semaines devant, convertis en UTC jour par jour (heure d'été juste).
 */
#[ORM\Entity(repositoryClass: BenefitSlotRuleRepository::class)]
#[ORM\Table(name: 'benefit_slot_rule')]
class BenefitSlotRule
{
    public const LOCATION_LENGTH = 160;
    public const CAPACITY_MAX = 500;
    public const DURATION_MAX = 1440;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Benefit::class)]
    #[ORM\JoinColumn(name: 'benefit_id', nullable: false, onDelete: 'CASCADE')]
    private ?Benefit $benefit = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [];

    #[ORM\Column(name: 'local_time', length: 5)]
    private string $localTime = '09:00';

    #[ORM\Column(name: 'duration_minutes')]
    private int $durationMinutes = 60;

    #[ORM\Column]
    private int $capacity = 1;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(name: 'staff_user_id', nullable: true)]
    private ?int $staffUserId = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(name: 'valid_until', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBenefit(): ?Benefit { return $this->benefit; }
    public function setBenefit(?Benefit $b): static { $this->benefit = $b; return $this->touch(); }
    /** @return list<int> */
    public function getWeekdays(): array { return array_values(array_map('intval', $this->weekdays)); }
    /** @param list<int|string> $days */
    public function setWeekdays(array $days): static
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        $this->weekdays = $days;

        return $this->touch();
    }
    public function getLocalTime(): string { return $this->localTime; }
    public function setLocalTime(string $t): static { $this->localTime = $t; return $this->touch(); }
    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $m): static { $this->durationMinutes = $m; return $this->touch(); }
    public function getCapacity(): int { return $this->capacity; }
    public function setCapacity(int $c): static { $this->capacity = $c; return $this->touch(); }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $l): static { $l = trim(strip_tags((string) $l)); $this->location = $l !== '' ? mb_substr($l, 0, self::LOCATION_LENGTH) : null; return $this->touch(); }
    public function getStaffUserId(): ?int { return $this->staffUserId; }
    public function setStaffUserId(?int $id): static { $this->staffUserId = $id; return $this->touch(); }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeInterface $d): static { $this->validFrom = $d !== null ? \DateTimeImmutable::createFromInterface($d) : null; return $this->touch(); }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeInterface $d): static { $this->validUntil = $d !== null ? \DateTimeImmutable::createFromInterface($d) : null; return $this->touch(); }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Pour EasyAdmin : « 1,3 » ⇄ [1, 3] */
    public function getWeekdaysText(): string { return implode(',', $this->getWeekdays()); }
    public function setWeekdaysText(?string $text): static { return $this->setWeekdays(array_filter(preg_split('/[\s,;]+/', (string) $text) ?: [], fn ($d) => $d !== '')); }

    public function __toString(): string
    {
        return sprintf('%s, %s à %s', $this->benefit?->getName('fr') ?? '?', $this->getWeekdaysText(), $this->localTime);
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
