<?php

namespace App\MemoiresVivantes\Services;

/**
 * Lien d'itinéraire Google Maps vers une adresse postale saisie librement. Ouvert sur un téléphone, il lance
 * directement la navigation dans l'application Google Maps.
 */
final class MapsLink
{
    public static function directionsUrl(?string $address): ?string
    {
        $address = trim((string) preg_replace('/\s+/u', ' ', (string) $address));

        return $address === '' ? null : 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($address);
    }
}
