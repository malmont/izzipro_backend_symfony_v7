<?php
// src/Services/GoogleTranslateService.php

namespace App\Services\GoogleTranslateService;

use Google\Cloud\Translate\V2\TranslateClient;
use Psr\Log\LoggerInterface;

class GoogleTranslateService
{
    private ?TranslateClient $translateClient = null;
    private LoggerInterface $logger;

    public function __construct(?string $googleApiKey, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $key = $googleApiKey ? trim($googleApiKey) : null;

        if (!empty($key) && $key !== 'VOTRE_CLÉ_ICI') {
            $this->translateClient = new TranslateClient(['key' => $key]);
        } else {
            $this->logger->warning('Google API Key is not configured. The translation service will return original texts.');
        }
    }

    public function translate(?string $text, string $targetLocale, ?string $sourceLocale = 'fr'): ?string
    {
        if ($this->translateClient === null || $text === null) {
            return $text;
        }

        $text = trim($text);
        if ($text === '') {
            return '';
        }

        try {
            $result = $this->translateClient->translate($text, [
                'source' => $sourceLocale,
                'target' => $targetLocale,
            ]);
            return $result['text'];
        } catch (\Exception $e) {
            $this->logger->error('Google Translate API error: ' . $e->getMessage());
            return $text;
        }
    }
}