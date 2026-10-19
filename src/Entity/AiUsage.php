<?php

namespace App\Entity;

use App\Repository\AiUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique et réservations de crédits de l'assistant IA des landing pages.
 * Ne contient jamais la composition envoyée ou produite : seulement un extrait de la demande et, depuis le 19/10/2026,
 * le résumé de la réponse (1 à 3 phrases du modèle, SUMMARY_LENGTH caractères au plus) avec la clé de mémoire de la
 * section ou de la page et l'issue indiquée par la console : mémoire de l'assistant (LandingAiMemoryService).
 */
#[ORM\Entity(repositoryClass: AiUsageRepository::class)]
#[ORM\Table(name: 'ai_usage')]
#[ORM\Index(columns: ['created_at'], name: 'idx_ai_usage_created_at')]
#[ORM\Index(columns: ['status'], name: 'idx_ai_usage_status')]
#[ORM\Index(columns: ['memory_key'], name: 'idx_ai_usage_memory_key')]
class AiUsage
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    public const PROMPT_EXCERPT_LENGTH = 500;
    public const SUMMARY_LENGTH = 1000;
    public const MEMORY_KEY_LENGTH = 120;
    public const OUTCOMES = ['applied', 'rejected', 'undone'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tenant = '';

    #[ORM\Column(name: 'user_identifier', length: 180, nullable: true)]
    private ?string $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Fin de validité d'une réservation (reserved) : au-delà, processus supposé interrompu et crédits libérés */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reservedUntil = null;

    #[ORM\Column(length: 10)]
    private string $mode = 'edit';

    #[ORM\Column(length: 64)]
    private string $componentKey = '';

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_RESERVED;

    #[ORM\Column]
    private int $credits = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private int $inputTokens = 0;

    #[ORM\Column]
    private int $outputTokens = 0;

    #[ORM\Column]
    private int $cacheReadTokens = 0;

    /** Jetons écrits dans le cache (compris dans inputTokens, facturés plus cher) ; 0 avant le 02/10/2026 */
    #[ORM\Column(options: ['default' => 0])]
    private int $cacheWriteTokens = 0;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(length: self::PROMPT_EXCERPT_LENGTH)]
    private string $promptExcerpt = '';

    /** Clé de mémoire envoyée par la console (section ou page), ou null */
    #[ORM\Column(name: 'memory_key', length: self::MEMORY_KEY_LENGTH, nullable: true)]
    private ?string $memoryKey = null;

    /** Résumé de la réponse du modèle (demande réussie avec une clé de mémoire), ou null */
    #[ORM\Column(length: self::SUMMARY_LENGTH, nullable: true)]
    private ?string $summary = null;

    /** Issue indiquée par la console : applied, rejected, undone, ou null */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $outcome = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): string { return $this->tenant; }
    public function setTenant(string $tenant): static { $this->tenant = $tenant; return $this; }

    public function getUser(): ?string { return $this->user; }
    public function setUser(?string $user): static { $this->user = $user; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getReservedUntil(): ?\DateTimeImmutable { return $this->reservedUntil; }
    public function setReservedUntil(?\DateTimeImmutable $reservedUntil): static { $this->reservedUntil = $reservedUntil; return $this; }

    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): static { $this->mode = $mode; return $this; }

    public function getComponentKey(): string { return $this->componentKey; }
    public function setComponentKey(string $componentKey): static { $this->componentKey = $componentKey; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCredits(): int { return $this->credits; }
    public function setCredits(int $credits): static { $this->credits = $credits; return $this; }

    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): static { $this->model = $model; return $this; }

    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $attempts): static { $this->attempts = $attempts; return $this; }

    public function getInputTokens(): int { return $this->inputTokens; }
    public function setInputTokens(int $inputTokens): static { $this->inputTokens = $inputTokens; return $this; }

    public function getOutputTokens(): int { return $this->outputTokens; }
    public function setOutputTokens(int $outputTokens): static { $this->outputTokens = $outputTokens; return $this; }

    public function getCacheReadTokens(): int { return $this->cacheReadTokens; }
    public function setCacheReadTokens(int $cacheReadTokens): static { $this->cacheReadTokens = $cacheReadTokens; return $this; }

    public function getCacheWriteTokens(): int { return $this->cacheWriteTokens; }
    public function setCacheWriteTokens(int $cacheWriteTokens): static { $this->cacheWriteTokens = $cacheWriteTokens; return $this; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function setDurationMs(?int $durationMs): static { $this->durationMs = $durationMs; return $this; }

    public function getMemoryKey(): ?string { return $this->memoryKey; }
    public function setMemoryKey(?string $memoryKey): static { $this->memoryKey = $memoryKey; return $this; }
    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): static
    {
        $this->summary = $summary !== null && trim($summary) !== '' ? mb_substr(trim($summary), 0, self::SUMMARY_LENGTH) : null;

        return $this;
    }
    public function getOutcome(): ?string { return $this->outcome; }
    public function setOutcome(?string $outcome): static { $this->outcome = $outcome; return $this; }
    public function getPromptExcerpt(): string { return $this->promptExcerpt; }
    public function setPromptExcerpt(string $prompt): static
    {
        $this->promptExcerpt = mb_substr($prompt, 0, self::PROMPT_EXCERPT_LENGTH);
        return $this;
    }
}
