<?php

namespace App\Tests\Fake;

use App\Services\OpenAiService;

/** Whisper simulé (aucun appel réseau) : le texte rendu est fixé par les tests ; chaîne vide = rien d'audible (422 noSpeech) */
class FakeOpenAiService extends OpenAiService
{
    public static string $transcription = 'Texte transcrit par la fausse IA de test.';
    /** @var list<string> fichiers reçus */
    public static array $files = [];

    public function transcribe(string $filePath): string
    {
        self::$files[] = $filePath;

        return self::$transcription;
    }

    public static function reset(): void
    {
        self::$transcription = 'Texte transcrit par la fausse IA de test.';
        self::$files = [];
    }
}
