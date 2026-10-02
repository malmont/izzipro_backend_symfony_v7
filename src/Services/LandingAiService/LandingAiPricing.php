<?php

namespace App\Services\LandingAiService;

use App\Entity\AiUsage;

/**
 * Coût estimé d'une demande à l'assistant, en dollars US, d'après les jetons enregistrés et les prix publics des
 * modèles. Une estimation : la facture fait foi (console Anthropic). Les jetons écrits dans le cache sont compris dans
 * les jetons d'entrée et facturés 1,25 fois (cache de 5 minutes) ou 2 fois (1 heure) ; ceux lus dans le cache sont
 * comptés à part, au tarif réduit.
 */
final class LandingAiPricing
{
    /** Dollars US par million de jetons (API Anthropic, relevés le 30/09/2026) : entrée, sortie, lecture du cache. À mettre à jour avec les tarifs. */
    public const PRICES = [
        'claude-sonnet-5' => [2.0, 10.0, 0.20],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.20],
        'claude-opus-5-5' => [4.0, 20.0, 0.20],
        'claude-opus-5' => [5.0, 25.0, 0.50],
        'claude-fable-5-1' => [10.0, 50.0, 0.25],
    ];
    public const CACHE_WRITE_FACTOR = 1.25;
    public const LONG_CACHE_WRITE_FACTOR = 2.0;

    public function __construct(private readonly LandingAiTuning $tuning)
    {
    }

    /** Coût estimé (USD) ; 0 si le modèle est inconnu. $longCache : contexte écrit dans le cache d'une heure */
    public function cost(?string $model, int $inputTokens, int $outputTokens, int $cacheReadTokens, int $cacheWriteTokens = 0, bool $longCache = false): float
    {
        [$input, $output, $read] = self::PRICES[$model ?? ''] ?? [0.0, 0.0, 0.0];
        $write = min($cacheWriteTokens, $inputTokens);

        return (($inputTokens - $write) * $input
            + $write * $input * ($longCache ? self::LONG_CACHE_WRITE_FACTOR : self::CACHE_WRITE_FACTOR)
            + $cacheReadTokens * $read
            + $outputTokens * $output) / 1000000;
    }

    /** Coût estimé d'une ligne de l'historique (les demandes d'avant le 02/10/2026 n'ont pas les jetons écrits en cache) */
    public function usageCost(AiUsage $usage): float
    {
        $kind = $usage->getMode() === 'page' ? 'page' : ($usage->getCredits() === LandingAiQuotaService::IMAGES_COST ? 'images' : $usage->getMode());

        return $this->cost(
            $usage->getModel(),
            $usage->getInputTokens(),
            $usage->getOutputTokens(),
            $usage->getCacheReadTokens(),
            $usage->getCacheWriteTokens(),
            $this->tuning->cacheTtl($kind) !== null
        );
    }

    /** @param iterable<AiUsage> $usages */
    public function total(iterable $usages): float
    {
        $total = 0.0;
        foreach ($usages as $usage) {
            $total += $this->usageCost($usage);
        }

        return $total;
    }
}
