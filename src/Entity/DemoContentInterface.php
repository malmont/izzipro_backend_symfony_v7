<?php

namespace App\Entity;

/**
 * Fiche de contenu qui peut être un contenu d'exemple (12/10/2026) : copiée du site de référence (gmasuite) à la
 * création du site ou par POST /api/site-content/seed, et remplacée ensuite par le client. Toute modification par
 * l'éditeur ou l'administration la rend définitive (isDemo: false). Voir App\Services\SiteContentService.
 */
interface DemoContentInterface
{
    public function isDemo(): bool;

    public function setIsDemo(bool $isDemo): static;
}
