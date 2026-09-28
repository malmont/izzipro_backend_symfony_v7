<?php

namespace App\Tests\Fake;

use App\Services\LandingAiService\LandingAiClientInterface;

/**
 * Faux client de l'API Messages pour l'assistant IA : réponses scriptées, requêtes enregistrées, aucun appel réseau.
 */
class FakeLandingAiClient implements LandingAiClientInterface
{
    /** @var list<object|\Throwable|callable> */
    public static array $queue = [];
    /** @var list<array> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    /** Réponse type : un appel de l'outil de retouche avec ces opérations */
    public static function editResponse(array|object $operations, string $summary = 'Modification effectuée.', array $warnings = [], array $usage = []): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(6)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_' . bin2hex(random_bytes(6)),
                'name' => 'retoucher_composition',
                'input' => ['operations' => $operations, 'summary' => $summary, 'warnings' => $warnings],
            ]],
            'usage' => $usage + ['input_tokens' => 1200, 'output_tokens' => 150, 'cache_read_input_tokens' => 30000, 'cache_creation_input_tokens' => 0],
        ], JSON_PRESERVE_ZERO_FRACTION), false);
    }

    public function createMessage(array $payload, float $timeoutSeconds): object
    {
        self::$requests[] = json_decode(json_encode($payload), true);
        $next = array_shift(self::$queue);
        if ($next === null) {
            throw new \RuntimeException('FakeLandingAiClient : aucune réponse prévue');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_callable($next) ? $next($payload) : $next;
    }
}
