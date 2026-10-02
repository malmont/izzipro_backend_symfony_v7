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
    /** @var list<float> délai accordé à chaque appel */
    public static array $timeouts = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
        self::$timeouts = [];
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

    /** Réponse type : un appel de l'outil de création */
    public static function createResponse(object|array $composition, string|int|null $dataType, string $summary = 'Section créée.', array $warnings = []): object
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
                'name' => 'creer_composition',
                'input' => ['dataType' => $dataType, 'composition' => $composition, 'summary' => $summary, 'warnings' => $warnings],
            ]],
            'usage' => ['input_tokens' => 2500, 'output_tokens' => 3000, 'cache_read_input_tokens' => 30000, 'cache_creation_input_tokens' => 0],
        ], JSON_PRESERVE_ZERO_FRACTION), false);
    }

    /** Réponse type : un appel de l'outil du prompt de vidéo */
    public static function videoPromptResponse(array $input): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(6)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(6)), 'name' => 'ecrire_prompt_video', 'input' => $input]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 400, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    /**
     * Réponse type : un appel de l'outil de page
     *
     * @param list<array{componentKey: string, dataType: string|int|null, composition: object}> $sections
     */
    public static function pageResponse(array $sections, string $summary = 'Page composée.', array $warnings = []): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(6)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_' . bin2hex(random_bytes(6)),
                'name' => 'composer_page',
                'input' => ['sections' => $sections, 'summary' => $summary, 'warnings' => $warnings],
            ]],
            'usage' => ['input_tokens' => 9000, 'output_tokens' => 8000, 'cache_read_input_tokens' => 60000, 'cache_creation_input_tokens' => 0],
        ], JSON_PRESERVE_ZERO_FRACTION), false);
    }

    public function createMessage(array $payload, float $timeoutSeconds): object
    {
        self::$requests[] = json_decode(json_encode($payload), true);
        self::$timeouts[] = $timeoutSeconds;
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
