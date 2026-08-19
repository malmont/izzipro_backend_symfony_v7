<?php

namespace App\Services;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Psr\Log\LoggerInterface;

class OpenAiService
{
    private const API_URL = 'https://api.openai.com/v1/audio/transcriptions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Transcribes an audio file using OpenAI Whisper API.
     *
     * @param string $filePath Absolute path to the audio file on the server.
     * @return string Transcribed text.
     * @throws \RuntimeException If API returns an error or configuration is missing.
     */
    public function transcribe(string $filePath): string
    {
        $apiKey = trim($this->openAiApiKey, " \t\n\r\0\x0B\"");

        if (empty($apiKey)) {
            $this->logger->error("OpenAiService: OPENAI_API_KEY is empty. Cannot transcribe.");
            throw new \RuntimeException("OpenAI API Key is not configured.");
        }

        if (!file_exists($filePath)) {
            $this->logger->error("OpenAiService: File does not exist at path: " . $filePath);
            throw new \InvalidArgumentException("Audio file not found.");
        }

        try {
            $this->logger->info("OpenAiService: Starting Whisper transcription for file: " . $filePath);

            $formData = new FormDataPart([
                'file' => DataPart::fromPath($filePath),
                'model' => 'whisper-1',
                'language' => 'fr',
            ]);

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => array_merge(
                    $formData->getPreparedHeaders()->toArray(),
                    [
                        'Authorization' => 'Bearer ' . $apiKey,
                    ]
                ),
                'body' => $formData->bodyToIterable(),
                'timeout' => 120,
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorContent = $response->getContent(false);
                $this->logger->error("OpenAiService Whisper Error: Code " . $response->getStatusCode() . " | " . $errorContent);
                throw new \RuntimeException("Failed to transcribe audio file. API error.");
            }

            $data = $response->toArray();
            $text = $data['text'] ?? '';
            $this->logger->info("OpenAiService Whisper Success. Transcribed text length: " . strlen($text));

            return $text;
        } catch (\Exception $e) {
            $this->logger->error("OpenAiService Exception: " . $e->getMessage());
            throw $e;
        }
    }
}
