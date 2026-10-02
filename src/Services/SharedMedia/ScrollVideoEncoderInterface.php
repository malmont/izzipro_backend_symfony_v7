<?php

namespace App\Services\SharedMedia;

/**
 * Réencode une vidéo pour une scène au défilement : la scène cale la vidéo sur la position du défilement, ce qui
 * demande des images complètes très rapprochées (une toutes les 5 images) ; une vidéo ordinaire met 100 à 300 ms à
 * se caler, d'où des à-coups.
 */
interface ScrollVideoEncoderInterface
{
    /**
     * @throws \RuntimeException si l'encodage échoue (le fichier de sortie n'est alors pas à utiliser)
     */
    public function encode(string $sourcePath, string $targetPath): void;
}
