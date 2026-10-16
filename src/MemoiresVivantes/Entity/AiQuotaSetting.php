<?php

namespace App\MemoiresVivantes\Entity;

use App\MemoiresVivantes\Repository\AiQuotaSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quotas des appels IA de Mémoires Vivantes pour le site (16/10/2026) : une seule ligne par base tenant ; sans ligne,
 * les valeurs par défaut ci-dessous. Réglable dans EasyAdmin (« Quotas IA Mémoires Vivantes ») ou par
 * GET/PUT /api/memoires/admin/ai-quotas. Les quotas s'appliquent aux livres de l'application mobile (channel =
 * mobile) ; aux livres du web seulement si applyToWebBooks est vrai. 0 = illimité.
 */
#[ORM\Entity(repositoryClass: AiQuotaSettingRepository::class)]
#[ORM\Table(name: 'mv_ai_quota_setting')]
class AiQuotaSetting
{
    public const DEFAULT_TRANSCRIPTIONS_PER_DAY = 40;
    public const DEFAULT_IMPROVEMENTS_PER_DAY = 60;
    public const DEFAULT_MAX_AUDIO_SECONDS = 300;
    /** Reprises de rédaction d'un chapitre déjà rédigé, par chapitre et par jour (livres soumis aux quotas) */
    public const DEFAULT_REGENERATIONS_PER_DAY = 3;
    public const MIN_AUDIO_SECONDS = 30;
    public const MAX_AUDIO_SECONDS = 1800;
    /** Aucun site ne porte de fuseau horaire : celui des quotas se règle ici (jour de minuit à minuit) */
    public const DEFAULT_TIMEZONE = 'America/Martinique';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => self::DEFAULT_TRANSCRIPTIONS_PER_DAY])]
    private int $transcriptionsPerDay = self::DEFAULT_TRANSCRIPTIONS_PER_DAY;

    #[ORM\Column(options: ['default' => self::DEFAULT_IMPROVEMENTS_PER_DAY])]
    private int $improvementsPerDay = self::DEFAULT_IMPROVEMENTS_PER_DAY;

    #[ORM\Column(options: ['default' => self::DEFAULT_MAX_AUDIO_SECONDS])]
    private int $maxAudioSeconds = self::DEFAULT_MAX_AUDIO_SECONDS;

    #[ORM\Column(options: ['default' => self::DEFAULT_REGENERATIONS_PER_DAY])]
    private int $regenerationsPerDay = self::DEFAULT_REGENERATIONS_PER_DAY;

    #[ORM\Column(options: ['default' => false])]
    private bool $applyToWebBooks = false;

    #[ORM\Column(length: 64, options: ['default' => self::DEFAULT_TIMEZONE])]
    private string $timezone = self::DEFAULT_TIMEZONE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTranscriptionsPerDay(): int { return $this->transcriptionsPerDay; }
    public function setTranscriptionsPerDay(int $value): static { $this->transcriptionsPerDay = max(0, $value); return $this->touch(); }
    public function getImprovementsPerDay(): int { return $this->improvementsPerDay; }
    public function setImprovementsPerDay(int $value): static { $this->improvementsPerDay = max(0, $value); return $this->touch(); }
    public function getRegenerationsPerDay(): int { return $this->regenerationsPerDay; }
    public function setRegenerationsPerDay(int $value): static { $this->regenerationsPerDay = max(0, $value); return $this->touch(); }
    public function getMaxAudioSeconds(): int { return $this->maxAudioSeconds; }
    public function setMaxAudioSeconds(int $value): static { $this->maxAudioSeconds = $value; return $this->touch(); }
    public function isApplyToWebBooks(): bool { return $this->applyToWebBooks; }
    public function setApplyToWebBooks(bool $value): static { $this->applyToWebBooks = $value; return $this->touch(); }
    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(string $timezone): static { $this->timezone = $timezone; return $this->touch(); }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Limite d'un type d'appel : null = illimité (0 en base) */
    public function limitFor(string $kind): ?int
    {
        $limit = match ($kind) {
            AiUsage::KIND_TRANSCRIPTION => $this->transcriptionsPerDay,
            AiUsage::KIND_IMPROVEMENT => $this->improvementsPerDay,
            AiUsage::KIND_REGENERATION => $this->regenerationsPerDay,
            default => 0,
        };

        return $limit > 0 ? $limit : null;
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
