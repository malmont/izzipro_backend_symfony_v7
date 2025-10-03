<?php
// src/Services/DeepLTranslateService/DeepLTranslateService.php

namespace App\Services\DeepLTranslateService;

use DeepL\Translator;
use DeepL\DeepLException;
use Psr\Log\LoggerInterface;

final class DeepLTranslateService
{
    private ?Translator $translator = null;
    private LoggerInterface $logger;

    public function __construct(?string $deepLApiKey, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $key = $deepLApiKey ? trim($deepLApiKey) : null;

        if (!empty($key) && $key !== 'VOTRE_CLÉ_ICI') {
            $this->translator = new Translator($key);
        } else {
            $this->logger->warning('DeepL API Key is not configured. The translation service will return original texts.');
        }
    }

    public function translate(?string $text, string $targetLocale, ?string $sourceLocale = 'fr'): ?string
    {
        if ($this->translator === null || $text === null) {
            return $text;
        }
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $targetLocaleUpper = strtoupper($targetLocale);
        if ($targetLocaleUpper === 'EN') {
            $targetLocaleUpper = 'EN-US';
        }

        try {
            $result = $this->translator->translateText($text, $sourceLocale, $targetLocaleUpper);
            return $result->text;
        } catch (DeepLException $e) {
            $this->logger->error('DeepL API error: ' . $e->getMessage());
            return $text;
        }
    }
}