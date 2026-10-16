<?php

namespace App\MemoiresVivantes\Dto;

use App\MemoiresVivantes\Entity\AiQuotaSetting;

/**
 * Corps de PUT /api/memoires/admin/ai-quotas et du formulaire EasyAdmin : champs facultatifs (absent = inchangé).
 * Validation : entiers ≥ 0 (0 = illimité), durée entre 30 et 1800 s, fuseau horaire connu de PHP.
 */
class AiQuotaSettingsInputDto
{
    public ?int $transcriptionsPerDay = null;
    public ?int $improvementsPerDay = null;
    public ?int $maxAudioSeconds = null;
    public ?bool $applyToWebBooks = null;
    public ?string $timezone = null;
    /** @var list<array{path: string, message: string}> */
    public array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        foreach (['transcriptionsPerDay' => 'transcriptions_per_day', 'improvementsPerDay' => 'improvements_per_day'] as $field => $alt) {
            $value = $data[$field] ?? $data[$alt] ?? null;
            if ($value === null || $value === '') continue;
            if (!is_numeric($value) || (int) $value < 0 || (int) $value > 100000 || (string) (int) $value !== (string) $value && !is_int($value)) {
                $this->errors[] = ['path' => $field, 'message' => 'entier entre 0 (illimité) et 100 000 attendu'];
            } else {
                $this->{$field} = (int) $value;
            }
        }
        $value = $data['maxAudioSeconds'] ?? $data['max_audio_seconds'] ?? null;
        if ($value !== null && $value !== '') {
            if (!is_numeric($value) || (int) $value < AiQuotaSetting::MIN_AUDIO_SECONDS || (int) $value > AiQuotaSetting::MAX_AUDIO_SECONDS) {
                $this->errors[] = ['path' => 'maxAudioSeconds', 'message' => sprintf('entre %d et %d secondes', AiQuotaSetting::MIN_AUDIO_SECONDS, AiQuotaSetting::MAX_AUDIO_SECONDS)];
            } else {
                $this->maxAudioSeconds = (int) $value;
            }
        }
        $value = $data['applyToWebBooks'] ?? $data['apply_to_web_books'] ?? null;
        if ($value !== null && $value !== '') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                $this->errors[] = ['path' => 'applyToWebBooks', 'message' => 'booléen attendu'];
            } else {
                $this->applyToWebBooks = $bool;
            }
        }
        $value = $data['timezone'] ?? null;
        if ($value !== null && $value !== '') {
            if (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                $this->errors[] = ['path' => 'timezone', 'message' => 'fuseau horaire inconnu (ex. America/Martinique, Europe/Paris)'];
            } else {
                $this->timezone = $value;
            }
        }
    }

    public function applyTo(AiQuotaSetting $settings): AiQuotaSetting
    {
        if ($this->transcriptionsPerDay !== null) $settings->setTranscriptionsPerDay($this->transcriptionsPerDay);
        if ($this->improvementsPerDay !== null) $settings->setImprovementsPerDay($this->improvementsPerDay);
        if ($this->maxAudioSeconds !== null) $settings->setMaxAudioSeconds($this->maxAudioSeconds);
        if ($this->applyToWebBooks !== null) $settings->setApplyToWebBooks($this->applyToWebBooks);
        if ($this->timezone !== null) $settings->setTimezone($this->timezone);

        return $settings;
    }
}
