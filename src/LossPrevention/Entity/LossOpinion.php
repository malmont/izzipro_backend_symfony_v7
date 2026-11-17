<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossOpinionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'avis COURANT de l'IA sur un constat (lot L5-c, `docs/prevention-pertes.md` § 18.10) : un par constat, remplacé par « Actualiser ».
 * Le pourcentage est calculé par le SERVEUR (part des constats semblables confirmés parmi ceux qui ont été tranchés) ; l'IA ne rédige que
 * le résumé, les facteurs et les autres explications (`content`). Lu et écrit en SQL par `LossOpinionRepository`.
 */
#[ORM\Entity(repositoryClass: LossOpinionRepository::class)]
#[ORM\Table(name: 'loss_opinion')]
#[ORM\Index(name: 'idx_loss_opinion_pending', columns: ['requested_at'], options: ['where' => "state = 'pending'"])]
class LossOpinion
{
    public const PENDING = 'pending';
    public const DONE = 'done';
    public const FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(name: 'finding_id')]
    private int $findingId;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;

    #[ORM\Column(length: 10)]
    private string $state = self::PENDING;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $percent = null;

    /** @var array<string, int> */
    #[ORM\Column(type: 'json')]
    private array $measures = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $content = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $example = false;

    #[ORM\Column(name: 'requested_by', nullable: true)]
    private ?int $requestedBy = null;

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'generated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(name: 'evidence_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $evidenceAt = null;

    #[ORM\Column(name: 'credits_used', type: 'smallint', options: ['default' => 0])]
    private int $creditsUsed = 0;

    #[ORM\Column(name: 'usage_id', nullable: true)]
    private ?int $usageId = null;

    /** Le numéro de la demande (relecture du 17/11/2026) : un passage du worker n'écrit que sur la demande qu'il a lue */
    #[ORM\Column(options: ['default' => 1])]
    private int $attempt = 1;

    public function getFindingId(): int { return $this->findingId; }
    public function isExercise(): bool { return $this->exercise; }
    public function getState(): string { return $this->state; }
    public function getPercent(): ?int { return $this->percent; }
    /** @return array<string, int> */
    public function getMeasures(): array { return $this->measures; }
    /** @return array<string, mixed>|null */
    public function getContent(): ?array { return $this->content; }
    public function getError(): ?string { return $this->error; }
    public function isExample(): bool { return $this->example; }
    public function getRequestedBy(): ?int { return $this->requestedBy; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getGeneratedAt(): ?\DateTimeImmutable { return $this->generatedAt; }
    public function getEvidenceAt(): ?\DateTimeImmutable { return $this->evidenceAt; }
    public function getCreditsUsed(): int { return $this->creditsUsed; }
    public function getUsageId(): ?int { return $this->usageId; }
    public function getAttempt(): int { return $this->attempt; }
}
