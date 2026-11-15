<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossHardwareCatalog;
use App\LossPrevention\Services\LossInstallationService;
use App\LossPrevention\Services\LossPieceLabelService;

/**
 * Étiquettes des pièces, assistant d'installation et gamme de matériel (lot L2-b, `docs/prevention-pertes.md` § 13.10).
 * `$exercise` : les mêmes gestes sur des pièces d'exercice et des puces simulées, par les routes d'exercice.
 */
class LossInstallationUseCase
{
    public function __construct(
        private readonly LossInstallationService $installation,
        private readonly LossPieceLabelService $labels,
        private readonly LossHardwareCatalog $hardware,
    ) {
    }

    /** @return array<string, mixed> */
    public function steps(string $locale, bool $exercise = false): array
    {
        return $this->installation->steps($locale, $exercise);
    }

    /** @return array{code: string, verified: bool, hint: ?string} */
    public function verify(string $code, string $locale, bool $exercise = false): array
    {
        return $this->installation->verify($code, $locale, $exercise);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function testRead(array $body, string $locale, int $userId, bool $exercise = false): array
    {
        return $this->installation->testRead($body, $locale, $userId, $exercise);
    }

    /** @param array<string, mixed> $body @return array{content: string, format: string} */
    public function testLabel(array $body, string $locale, int $userId, bool $exercise = false): array
    {
        return $this->installation->testLabel($body, $locale, $userId, $exercise);
    }

    /** @param array<string, mixed> $body @return array{content: string, count: int, format: string} */
    public function pieceLabels(array $body, string $locale, bool $exercise = false): array
    {
        return $this->labels->pdf($body, $locale, $exercise);
    }

    /** @return array<string, mixed> */
    public function hardware(string $locale): array
    {
        return $this->hardware->catalogue($locale);
    }
}
