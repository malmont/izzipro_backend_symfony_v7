<?php

namespace App\Services\SettingsVersion;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verrou de version des réglages du site et de la boutique (09/11/2026, demande de l'éditeur Next) : la version est l'empreinte du JSON
 * enregistré, rendue en lecture dans `X-Settings-Version` et reprise à l'écriture dans le même en-tête. Une version qui ne correspond plus à
 * l'enregistrement → 409 SETTINGS_CHANGED, rien n'est écrit. Sans en-tête : écriture comme avant. L'`ETag` des réponses reste celui de la
 * couche de cache HTTP (empreinte du corps rendu, clés de lecture seule comprises) : il ne sert pas au verrou.
 */
final class SettingsVersion
{
    public const HEADER = 'X-Settings-Version';
    public const CODE = 'SETTINGS_CHANGED';

    /** Version d'un JSON enregistré (null ou « null » = aucun réglage) */
    public static function of(?string $rawJson): string
    {
        return $rawJson === null || $rawJson === '' || $rawJson === 'null' ? 'empty' : substr(hash('sha256', $rawJson), 0, 32);
    }

    /** @return array<string, string> en-têtes de réponse */
    public static function headers(string $version): array
    {
        return [self::HEADER => $version];
    }

    /** Version attendue par l'appelant (X-Settings-Version: v, guillemets tolérés), ou null sans en-tête */
    public static function expected(Request $request): ?string
    {
        $raw = $request->headers->get(self::HEADER);
        if ($raw === null || trim($raw) === '' || trim($raw) === '*') {
            return null;
        }

        return trim(trim($raw), " \t\"");
    }

    /** @return array<string, mixed> corps du refus 409 */
    public static function conflictBody(string $current): array
    {
        return ['error' => self::CODE, 'code' => self::CODE, 'message' => 'Les réglages ont changé depuis votre lecture : rechargez-les avant d\'enregistrer.', 'currentVersion' => $current];
    }
}
