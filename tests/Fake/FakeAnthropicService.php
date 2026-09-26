<?php

namespace App\Tests\Fake;

use App\Services\AnthropicService;

/**
 * Remplace l'API Anthropic pendant les tests : aucun appel réseau, réponses déterministes.
 * Les appels sont enregistrés pour vérifier ce que le backend a demandé à l'IA.
 */
class FakeAnthropicService extends AnthropicService
{
    /** @var array<int, array{prompt: string, maxTokens: int, system: ?string, model: ?string}> */
    public static array $calls = [];

    public function complete(string $prompt, int $maxTokens = 32000, ?string $system = null, ?string $model = null): string
    {
        self::$calls[] = ['prompt' => $prompt, 'maxTokens' => $maxTokens, 'system' => $system, 'model' => $model];

        // Réponses fictives demandées par le bouton « Tester » : un tableau JSON, une réponse par question
        if (str_contains($prompt, 'tableau JSON de chaînes')) {
            preg_match_all('/^\d+\. /m', $prompt, $m);
            return json_encode(array_map(fn ($i) => "Réponse fictive n° " . ($i + 1) . '.', array_keys($m[0] ?: [0])), JSON_UNESCAPED_UNICODE);
        }
        // Amélioration de consigne
        if ($system !== null && str_contains($system, 'consignes')) {
            return "Consigne améliorée par la fausse IA de test.";
        }
        // Extrait de test ou génération de chapitre
        return "===Un sous-titre de test===\n\nUn paragraphe généré par la fausse IA de test.";
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
