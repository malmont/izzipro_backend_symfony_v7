<?php

namespace App\Services\Http;

/**
 * La FORME d'un site demandé (en-tête `X-Tenant-Host`, paramètre `?tenant=`) : un nom d'hôte, avec au plus un port. Train de
 * sécurité du 16/11/2026 : `victime:@pirate.example` était résolu en site `victime` (la résolution coupe au « : »), et des liens de
 * courriel reprenaient la valeur brute de l'en-tête : le lien de réinitialisation menait chez `pirate.example`, avec le jeton.
 * Une valeur qui n'a pas la forme d'un nom d'hôte est refusée à l'entrée, avant toute résolution (`TenantDoctrineSwitcherListener`).
 */
final class TenantHostFormat
{
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9])?';

    public static function valid(string $host): bool
    {
        return strlen($host) <= 253 && preg_match('/^' . self::LABEL . '(?:\.' . self::LABEL . ')*\.?(?::\d{1,5})?$/', $host) === 1;
    }
}
