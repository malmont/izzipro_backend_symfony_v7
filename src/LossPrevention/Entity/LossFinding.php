<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossFindingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Constat (lot L1-a, `docs/prevention-pertes.md` § 5) : ce qu'une règle a relevé dans l'activité de caisse, avec son dossier de preuves
 * (`LossEvidence`) et la décision du gérant. Jamais supprimé. `facts` ne porte que des nombres et des identifiants (aucun nom, aucun
 * courriel) : le résumé est rédigé à la lecture, dans la langue demandée. `onDuty` : identifiants et rôles ; les noms sont lus à la lecture.
 * Écrit en SQL par son dépôt (l'analyse) ; unique par `dedupKey` (règle + sujet + fenêtre).
 */
#[ORM\Entity(repositoryClass: LossFindingRepository::class)]
#[ORM\Table(name: 'loss_finding')]
#[ORM\UniqueConstraint(name: 'uniq_loss_finding_number', columns: ['number'])]
#[ORM\UniqueConstraint(name: 'uniq_loss_finding_dedup', columns: ['dedup_key'])]
#[ORM\Index(name: 'idx_loss_finding_status', columns: ['status', 'store_day'])]
#[ORM\Index(name: 'idx_loss_finding_subject', columns: ['rule_code', 'variant', 'subject_type', 'subject_id'])]
class LossFinding
{
    public const OPEN = 'open';
    public const WATCH = 'watch';
    public const EXPLAINED = 'explained';
    public const DISMISSED = 'dismissed';
    public const CONFIRMED = 'confirmed';
    public const STATUSES = [self::OPEN, self::WATCH, self::EXPLAINED, self::DISMISSED, self::CONFIRMED];
    /** États dont la décision exige une note */
    public const NOTE_REQUIRED = [self::EXPLAINED, self::CONFIRMED];

    public const VARIANT_RULE = 'rule';
    public const VARIANT_PERSON = 'person';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(name: 'rule_code', length: 40)]
    private string $ruleCode;

    #[ORM\Column(length: 10, options: ['default' => self::VARIANT_RULE])]
    private string $variant = self::VARIANT_RULE;

    #[ORM\Column(name: 'dedup_key', length: 160)]
    private string $dedupKey;

    #[ORM\Column(length: 12, options: ['default' => self::OPEN])]
    private string $status = self::OPEN;

    #[ORM\Column(name: 'store_day', type: 'date_immutable')]
    private \DateTimeImmutable $storeDay;

    #[ORM\Column(name: 'occurred_from', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredFrom;

    #[ORM\Column(name: 'occurred_to', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredTo;

    #[ORM\Column(name: 'cash_register_id', nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(name: 'session_id', nullable: true)]
    private ?int $sessionId = null;

    /** Montant concerné, en cents : pas une perte prouvée */
    #[ORM\Column(name: 'amount_at_stake', nullable: true)]
    private ?int $amountAtStake = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'subject_type', length: 20)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id')]
    private int $subjectId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $facts = [];

    /** @var list<array{id: int, role: string}> */
    #[ORM\Column(name: 'on_duty', type: 'json')]
    private array $onDuty = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $backfill = false;

    #[ORM\Column(name: 'seen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $seenAt = null;

    #[ORM\Column(name: 'seen_by', nullable: true)]
    private ?int $seenBy = null;

    #[ORM\Column(name: 'decision_note', type: 'text', nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(name: 'decided_by', nullable: true)]
    private ?int $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'last_evidence_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastEvidenceAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function getNumber(): int { return $this->number; }
    public function getRuleCode(): string { return $this->ruleCode; }
    public function getVariant(): string { return $this->variant; }
    public function getDedupKey(): string { return $this->dedupKey; }
    public function getStatus(): string { return $this->status; }
    public function getStoreDay(): \DateTimeImmutable { return $this->storeDay; }
    public function getOccurredFrom(): \DateTimeImmutable { return $this->occurredFrom; }
    public function getOccurredTo(): \DateTimeImmutable { return $this->occurredTo; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function getSessionId(): ?int { return $this->sessionId; }
    public function getAmountAtStake(): ?int { return $this->amountAtStake; }
    public function getCurrency(): string { return $this->currency; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): int { return $this->subjectId; }
    /** @return array<string, mixed> */
    public function getFacts(): array { return $this->facts; }
    /** @return list<array{id: int, role: string}> */
    public function getOnDuty(): array { return $this->onDuty; }
    public function isBackfill(): bool { return $this->backfill; }
    public function getSeenAt(): ?\DateTimeImmutable { return $this->seenAt; }
    public function getDecisionNote(): ?string { return $this->decisionNote; }
    public function getDecidedBy(): ?int { return $this->decidedBy; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getLastEvidenceAt(): \DateTimeImmutable { return $this->lastEvidenceAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
