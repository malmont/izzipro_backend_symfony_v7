<?php

namespace App\Dto;

/**
 * Corps de PUT /api/boutique-settings : la configuration décodée en objets (un {} reste {}) et son JSON réencodé,
 * tel qu'il sera enregistré et inscrit au journal.
 */
final class BoutiqueSettingsInputDto
{
    public function __construct(
        public readonly object $configuration,
        public readonly string $json
    ) {
    }
}
