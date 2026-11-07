<?php

namespace App\Services\ProductAiService;

/**
 * Générateur d'images produit (mannequin ou personne portant le vêtement photographié, 07/11/2026). Fournisseur branché derrière cette
 * interface (Gemini 2.5 Flash Image prévu, clé de la plateforme) ; tant qu'aucun n'est configuré, `NullProductImageGenerator` répond
 * « non disponible » et les lots d'images sont refusés sans rien débiter.
 */
interface ProductImageGeneratorInterface
{
    public const COST = 5;

    public function isConfigured(): bool;

    /**
     * @param array{data?: string, mime?: string, url?: string} $photo photo du vêtement
     * @param array{model?: string, background?: string, pose?: string, comment?: string} $options
     * @return list<array{data: string, mime: string}> images générées
     */
    public function generate(array $photo, array $options): array;
}
