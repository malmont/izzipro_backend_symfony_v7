<?php

namespace App\Dto;

/**
 * Corps de POST /api/landingpage-ai/video-prompt : l'administrateur décrit en français la vidéo d'une scène au
 * défilement ; la réponse est un prompt en anglais pour un outil de génération de vidéo.
 */
final class LandingAiVideoPromptInputDto
{
    public const MAX_PROMPT_LENGTH = 2000;
    public const MAX_BODY_BYTES = 16384;
    public const FORMATS = ['landscape', 'portrait', 'both'];
    public const DURATIONS = [5, 8, 10];
    public const TEXT_SIDES = ['left', 'right', 'center'];

    /** @param list<array{path: string, message: string}> $errors erreurs de forme relevées à la lecture */
    private function __construct(
        public readonly string $prompt,
        public readonly string $format,
        public readonly int $duration,
        public readonly string $textSide,
        public readonly string $background,
        public readonly string $locale,
        private readonly array $errors
    ) {
    }

    public static function fromRequestBody(mixed $body): self
    {
        $errors = [];
        $read = function (string $key, mixed $default, callable $valid, string $message) use ($body, &$errors) {
            $value = is_object($body) && property_exists($body, $key) ? $body->$key : $default;
            if (!$valid($value)) {
                $errors[] = ['path' => $key, 'message' => $message];

                return $default;
            }

            return $value;
        };
        if (!is_object($body)) {
            $errors[] = ['path' => '', 'message' => 'objet JSON attendu'];
        }

        $prompt = $read('prompt', '', fn ($v) => is_string($v) && trim($v) !== '' && mb_strlen($v) <= self::MAX_PROMPT_LENGTH, sprintf('texte de 1 à %d caractères attendu', self::MAX_PROMPT_LENGTH));
        $format = $read('format', 'landscape', fn ($v) => in_array($v, self::FORMATS, true), 'landscape, portrait ou both attendu');
        $duration = $read('duration', 8, fn ($v) => in_array($v, self::DURATIONS, true), '5, 8 ou 10 attendu');
        $textSide = $read('textSide', 'left', fn ($v) => in_array($v, self::TEXT_SIDES, true), 'left, right ou center attendu');
        $background = $read('background', '#000000', fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1, 'couleur au format #rrggbb attendue');
        $locale = $read('locale', 'fr', fn ($v) => is_string($v) && preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $v) === 1, 'code de langue attendu (fr, en…)');

        return new self(trim((string) $prompt), (string) $format, (int) $duration, (string) $textSide, strtolower((string) $background), (string) $locale, $errors);
    }

    /** @return list<array{path: string, message: string}> */
    public function validate(): array
    {
        return $this->errors;
    }
}
