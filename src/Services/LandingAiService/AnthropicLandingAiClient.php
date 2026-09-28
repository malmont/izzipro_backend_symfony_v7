<?php

namespace App\Services\LandingAiService;

use App\Services\AnthropicService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Client réel : réutilise le client Anthropic de Mémoires Vivantes, avec la clé dédiée ANTHROPIC_API_KEY_LANDING
 * (coûts et limites séparés). La clé n'apparaît jamais dans une réponse ni dans un journal.
 */
final class AnthropicLandingAiClient implements LandingAiClientInterface
{
    public function __construct(
        private readonly AnthropicService $anthropic,
        #[Autowire('%env(default::ANTHROPIC_API_KEY_LANDING)%')]
        private readonly ?string $apiKey
    ) {
    }

    public function createMessage(array $payload, float $timeoutSeconds): object
    {
        if (trim((string) $this->apiKey) === '') {
            throw new \RuntimeException('Clé ANTHROPIC_API_KEY_LANDING absente : assistant IA indisponible.');
        }

        $start = microtime(true);
        try {
            return $this->anthropic->sendMessagesRequest($payload, (string) $this->apiKey, $timeoutSeconds);
        } catch (TransportExceptionInterface $e) {
            if (microtime(true) - $start >= $timeoutSeconds * 0.95 || str_contains(strtolower($e->getMessage()), 'timeout') || str_contains($e->getMessage(), 'duration')) {
                throw new LandingAiTimeoutException('Délai de réponse de l\'IA dépassé.', 0, $e);
            }
            throw new \RuntimeException('API Anthropic injoignable.', 0, $e);
        }
    }
}
