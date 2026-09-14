<?php

namespace App\MemoiresVivantes\UseCase;

use App\Services\OpenAiService;

class TranscribeAudioUseCase
{
    public function __construct(
        private readonly OpenAiService $openAiService
    ) {}

    /**
     * Transcrit un fichier audio en texte via Whisper (OpenAI).
     *
     * @param string $filePath Chemin absolu vers le fichier audio
     * @return string Texte transcrit
     */
    public function execute(string $filePath): string
    {
        return $this->openAiService->transcribe($filePath);
    }
}
