<?php

namespace App\Abo\Entity;

use App\Abo\Repository\PlanBenefitRepository;
use App\Entity\SubscriptionPlan;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qu'une formule d'abonnement livre (lot ABO-0, 18/11/2026) : une prestation, combien par période, comment les dates se fixent, et les
 * règles (délai d'annulation, report, preuve). Valeurs par défaut : décisions du propriétaire (`docs/plan-abo-universel.md` § 7).
 */
#[ORM\Entity(repositoryClass: PlanBenefitRepository::class)]
#[ORM\Table(name: 'plan_benefit')]
#[ORM\UniqueConstraint(name: 'uniq_plan_benefit', columns: ['plan_id', 'benefit_id'])]
#[ORM\Index(columns: ['benefit_id'], name: 'idx_plan_benefit_benefit')]
class PlanBenefit
{
    public const SCHEDULING_NONE = 'none';
    public const SCHEDULING_CLIENT = 'client_at_subscription';
    public const SCHEDULING_ADMIN = 'admin_after';
    public const SCHEDULING_SLOTS = 'fixed_slots';
    public const SCHEDULINGS = [self::SCHEDULING_NONE, self::SCHEDULING_CLIENT, self::SCHEDULING_ADMIN, self::SCHEDULING_SLOTS];
    public const SLOT_CHOICES = ['client', 'admin'];
    public const PROOFS = ['none', 'note', 'photo'];
    public const MAX_QUANTITY = 999;
    public const DEFAULT_CANCEL_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'CASCADE')]
    private ?SubscriptionPlan $plan = null;

    #[ORM\ManyToOne(targetEntity: Benefit::class)]
    #[ORM\JoinColumn(name: 'benefit_id', nullable: false, onDelete: 'RESTRICT')]
    private ?Benefit $benefit = null;

    /** Par période de la formule ; null = sans limite (séance seulement) */
    #[ORM\Column(nullable: true)]
    private ?int $quantity = 1;

    #[ORM\Column(length: 30)]
    private string $scheduling = self::SCHEDULING_NONE;

    #[ORM\Column(name: 'slot_choice', length: 10, nullable: true)]
    private ?string $slotChoice = null;

    #[ORM\Column(name: 'cancel_deadline_hours')]
    private int $cancelDeadlineHours = self::DEFAULT_CANCEL_HOURS;

    /** Report au plus vers la période suivante ; null = la quantité (décision 2) */
    #[ORM\Column(name: 'carry_over_max', nullable: true)]
    private ?int $carryOverMax = null;

    #[ORM\Column(length: 10)]
    private string $proof = 'none';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return (string) $this->benefit?->getName(); }
    public function getId(): ?int { return $this->id; }
    public function getPlan(): ?SubscriptionPlan { return $this->plan; }
    public function setPlan(?SubscriptionPlan $plan): static { $this->plan = $plan; return $this; }
    public function getBenefit(): ?Benefit { return $this->benefit; }
    public function setBenefit(?Benefit $benefit): static { $this->benefit = $benefit; return $this; }
    public function getQuantity(): ?int { return $this->quantity; }
    public function setQuantity(?int $quantity): static { $this->quantity = $quantity; return $this; }
    public function getScheduling(): string { return $this->scheduling; }
    public function setScheduling(string $scheduling): static { $this->scheduling = $scheduling; return $this; }
    public function getSlotChoice(): ?string { return $this->slotChoice; }
    public function setSlotChoice(?string $choice): static { $this->slotChoice = $choice !== '' ? $choice : null; return $this; }
    public function getCancelDeadlineHours(): int { return $this->cancelDeadlineHours; }
    public function setCancelDeadlineHours(int $hours): static { $this->cancelDeadlineHours = $hours; return $this; }
    public function getCarryOverMax(): ?int { return $this->carryOverMax; }
    /** Le report effectif : la valeur réglée, sinon la quantité */
    public function getEffectiveCarryOver(): ?int { return $this->carryOverMax ?? $this->quantity; }
    public function setCarryOverMax(?int $max): static { $this->carryOverMax = $max; return $this; }
    public function getProof(): string { return $this->proof; }
    public function setProof(string $proof): static { $this->proof = $proof; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
