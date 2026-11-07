<?php

namespace App\Services\ProductAiService;

/** Aucun fournisseur d'images configuré : les lots d'images sont annoncés « non disponibles » */
final class NullProductImageGenerator implements ProductImageGeneratorInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function generate(array $photo, array $options): array
    {
        throw new \RuntimeException('Aucun fournisseur d\'images configuré sur la plateforme.');
    }
}
