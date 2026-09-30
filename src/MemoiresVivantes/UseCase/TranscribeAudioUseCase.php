<?php

namespace App\MemoiresVivantes\UseCase;

use App\Services\OpenAiService;

class TranscribeAudioUseCase
{
    /**
     * Phrases que Whisper invente sur un silence ou un bruit de fond (génériques de sous-titrage appris à
     * l'entraînement). Elles ne valent que pour une transcription courte : dans un long récit, ces mots peuvent être
     * réellement prononcés.
     */
    private const SILENCE_PHRASES = '/amara\.org|sous-?titr(es?|age)\b|soustitreur|merci d\'avoir regard[ée]|abonnez-vous/iu';
    private const SILENCE_MAX_LENGTH = 120;

    public function __construct(
        private readonly OpenAiService $openAiService
    ) {}

    /**
     * Transcrit un fichier audio en texte via Whisper (OpenAI).
     *
     * @param string $filePath Chemin absolu vers le fichier audio
     * @return string Texte transcrit
     *
     * @throws NoSpeechDetectedException enregistrement vide ou silencieux
     */
    public function execute(string $filePath): string
    {
        $text = trim($this->openAiService->transcribe($filePath));

        if (self::isSilence($text)) {
            throw new NoSpeechDetectedException();
        }

        return $text;
    }

    public static function isSilence(string $text): bool
    {
        $text = trim($text);

        return $text === '' || (mb_strlen($text) <= self::SILENCE_MAX_LENGTH && preg_match(self::SILENCE_PHRASES, $text) === 1);
    }
}
