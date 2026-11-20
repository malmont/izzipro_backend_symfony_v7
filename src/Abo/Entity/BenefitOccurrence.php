<?php

namespace App\Abo\Entity;

use App\Abo\Repository\BenefitOccurrenceRepository;
use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un droit à profiter d'une prestation (lot ABO-1, 19/11/2026, `docs/plan-abo-universel.md`) : né d'une facture payée (`origin` period,
 * une fois par facture : unicité abonnement, ligne de formule, facture, rang) ou offert par l'équipe (`gift`). `available` jusqu'à
 * `valid_until`, puis reporté une fois (`carried`) ou perdu (`expired`) ; `done` quand il est utilisé. Lot ABO-2 : `scheduled` (une date
 * planifiée : créneau ou date libre, jamais perdue par le passage) et `late_cancelled` (annulé après le délai : compte comme utilisé) ; ABO-3
 * ajoutera `missed`.
 */
#[ORM\Entity(repositoryClass: BenefitOccurrenceRepository::class)]
#[ORM\Table(name: 'benefit_occurrence')]
class BenefitOccurrence
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_DONE = 'done';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LATE_CANCELLED = 'late_cancelled';
    /** Ce qui compte dans « utilisé » */
    public const USED = [self::STATUS_DONE, self::STATUS_LATE_CANCELLED];
    public const SOURCE_CLIENT = 'client';
    public const SOURCE_TEAM = 'team';
    public const ORIGIN_PERIOD = 'period';
    public const ORIGIN_GIFT = 'gift';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', nullable: false, onDelete: 'CASCADE')]
    private ?Subscription $subscription = null;

    #[ORM\ManyToOne(targetEntity: PlanBenefit::class)]
    #[ORM\JoinColumn(name: 'plan_benefit_id', nullable: true, onDelete: 'SET NULL')]
    private ?PlanBenefit $planBenefit = null;

    #[ORM\ManyToOne(targetEntity: Benefit::class)]
    #[ORM\JoinColumn(name: 'benefit_id', nullable: false, onDelete: 'RESTRICT')]
    private ?Benefit $benefit = null;

    #[ORM\Column(name: 'invoice_id', length: 255, nullable: true)]
    private ?string $invoiceId = null;

    #[ORM\Column]
    private int $rank = 1;

    #[ORM\Column(length: 10)]
    private string $origin = self::ORIGIN_PERIOD;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_AVAILABLE;

    #[ORM\Column(name: 'period_start', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(name: 'period_end', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodEnd = null;

    #[ORM\Column(name: 'valid_until', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column]
    private bool $carried = false;

    #[ORM\Column]
    private bool $trial = false;

    #[ORM\Column(name: 'done_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\Column(name: 'done_by', length: 180, nullable: true)]
    private ?string $doneBy = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: BenefitSlot::class)]
    #[ORM\JoinColumn(name: 'slot_id', nullable: true, onDelete: 'SET NULL')]
    private ?BenefitSlot $slot = null;

    #[ORM\Column(name: 'scheduled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(name: 'scheduled_end', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledEnd = null;

    #[ORM\Column(name: 'scheduled_by', length: 180, nullable: true)]
    private ?string $scheduledBy = null;

    #[ORM\Column(name: 'scheduled_source', length: 10, nullable: true)]
    private ?string $scheduledSource = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->validUntil = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $s): static { $this->subscription = $s; return $this; }
    public function getPlanBenefit(): ?PlanBenefit { return $this->planBenefit; }
    public function setPlanBenefit(?PlanBenefit $pb): static { $this->planBenefit = $pb; return $this; }
    public function getBenefit(): ?Benefit { return $this->benefit; }
    public function setBenefit(?Benefit $b): static { $this->benefit = $b; return $this; }
    public function getInvoiceId(): ?string { return $this->invoiceId; }
    public function setInvoiceId(?string $id): static { $this->invoiceId = $id; return $this; }
    public function getRank(): int { return $this->rank; }
    public function setRank(int $rank): static { $this->rank = $rank; return $this; }
    public function getOrigin(): string { return $this->origin; }
    public function setOrigin(string $origin): static { $this->origin = $origin; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getPeriodStart(): ?\DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(?\DateTimeImmutable $at): static { $this->periodStart = $at; return $this; }
    public function getPeriodEnd(): ?\DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(?\DateTimeImmutable $at): static { $this->periodEnd = $at; return $this; }
    public function getValidUntil(): \DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(\DateTimeImmutable $at): static { $this->validUntil = $at; return $this; }
    public function isCarried(): bool { return $this->carried; }
    public function setCarried(bool $carried): static { $this->carried = $carried; return $this; }
    public function isTrial(): bool { return $this->trial; }
    public function setTrial(bool $trial): static { $this->trial = $trial; return $this; }
    public function getDoneAt(): ?\DateTimeImmutable { return $this->doneAt; }
    public function setDoneAt(?\DateTimeImmutable $at): static { $this->doneAt = $at; return $this; }
    public function getDoneBy(): ?string { return $this->doneBy; }
    public function setDoneBy(?string $by): static { $this->doneBy = $by !== null ? mb_substr($by, 0, 180) : null; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $n = trim(strip_tags((string) $note)); $this->note = $n !== '' ? mb_substr($n, 0, 300) : null; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $r = trim(strip_tags((string) $reason)); $this->reason = $r !== '' ? mb_substr($r, 0, 300) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSlot(): ?BenefitSlot { return $this->slot; }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function getScheduledEnd(): ?\DateTimeImmutable { return $this->scheduledEnd; }
    public function getScheduledBy(): ?string { return $this->scheduledBy; }
    public function getScheduledSource(): ?string { return $this->scheduledSource; }
}
