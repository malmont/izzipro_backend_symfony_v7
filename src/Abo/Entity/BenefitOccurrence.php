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
 * ajoutera `missed` (fait : lot ABO-3a, `STATUS_MISSED`).
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
    /** Lot ABO-3a : l'abonné ne s'est pas présenté (« absent ») ; compte comme utilisé (décision 1 du propriétaire) */
    public const STATUS_MISSED = 'missed';
    /** Ce qui compte dans « utilisé » */
    public const USED = [self::STATUS_DONE, self::STATUS_LATE_CANCELLED, self::STATUS_MISSED];
    /** Lot ABO-3a : l'avis de l'abonné sur un « fait » ou un « absent », et la décision de l'administrateur sur une contestation */
    public const ACK_CONFIRMED = 'confirmed';
    public const ACK_CONTESTED = 'contested';
    public const RESOLUTION_KEPT = 'kept';
    public const RESOLUTION_RETURNED = 'returned';
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

    // ---- lot ABO-3a : preuve, avis de l'abonné, contestation (écrits par AboDeliveryService, en SQL sous verrou) ----------------------

    #[ORM\Column(name: 'proof_path', length: 80, nullable: true)]
    private ?string $proofPath = null;

    #[ORM\Column(name: 'proof_sha256', length: 64, nullable: true)]
    private ?string $proofSha256 = null;

    #[ORM\Column(name: 'proof_mime', length: 20, nullable: true)]
    private ?string $proofMime = null;

    #[ORM\Column(name: 'proof_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $proofAt = null;

    #[ORM\Column(name: 'done_notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneNotifiedAt = null;

    #[ORM\Column(name: 'customer_ack', length: 12, nullable: true)]
    private ?string $customerAck = null;

    #[ORM\Column(name: 'customer_ack_comment', length: 1000, nullable: true)]
    private ?string $customerAckComment = null;

    #[ORM\Column(name: 'customer_ack_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $customerAckAt = null;

    #[ORM\Column(name: 'ack_ticket_ref', length: 20, nullable: true)]
    private ?string $ackTicketRef = null;

    #[ORM\Column(name: 'ack_resolution', length: 12, nullable: true)]
    private ?string $ackResolution = null;

    #[ORM\Column(name: 'ack_resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ackResolvedAt = null;

    #[ORM\Column(name: 'ack_resolved_by', length: 180, nullable: true)]
    private ?string $ackResolvedBy = null;

    public function getProofPath(): ?string { return $this->proofPath; }
    public function getProofSha256(): ?string { return $this->proofSha256; }
    public function getProofMime(): ?string { return $this->proofMime; }
    public function getProofAt(): ?\DateTimeImmutable { return $this->proofAt; }
    public function getDoneNotifiedAt(): ?\DateTimeImmutable { return $this->doneNotifiedAt; }
    public function getCustomerAck(): ?string { return $this->customerAck; }
    public function getCustomerAckComment(): ?string { return $this->customerAckComment; }
    public function getCustomerAckAt(): ?\DateTimeImmutable { return $this->customerAckAt; }
    public function getAckTicketRef(): ?string { return $this->ackTicketRef; }
    public function getAckResolution(): ?string { return $this->ackResolution; }
    public function getAckResolvedAt(): ?\DateTimeImmutable { return $this->ackResolvedAt; }
    public function getAckResolvedBy(): ?string { return $this->ackResolvedBy; }

    // ---- lot ABO-3b : l'intervenant de cette date (prime sur celui du créneau, puis sur celui de la prestation) -------------------------

    #[ORM\Column(name: 'staff_user_id', nullable: true)]
    private ?int $staffUserId = null;

    public function getStaffUserId(): ?int { return $this->staffUserId; }
}
