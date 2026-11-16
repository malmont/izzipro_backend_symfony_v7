<?php

namespace App\Services\Agency;

/**
 * Le code d'un site demandé par une agence (lot AG-2) : c'est son adresse (`<code>.<domaine de la plateforme>`). L'agence le choisit
 * librement, dans ces règles de forme : 3 à 40 caractères, lettres minuscules, chiffres et tirets, une lettre d'abord, pas de tiret à
 * la fin ni deux tirets de suite, pas de « -v2 » final. Les noms d'usage technique et les sites qui ne se confient pas sont refusés. La base du site
 * s'appelle `db_<code>`, les tirets devenus des soulignés.
 */
final class AgencySiteCode
{
    public const PATTERN = '/^[a-z](?:[a-z0-9]|-(?!-)){1,38}[a-z0-9]$/';
    /** Noms qui ne désignent jamais un site : hôtes techniques, sites réservés, noms trompeurs */
    public const RESERVED = [
        'www', 'api', 'admin', 'mail', 'backend', 'app', 'apps', 'demo', 'test', 'tests', 'staging', 'preprod', 'prod', 'dev', 'static', 'cdn', 'assets', 'media',
        'ftp', 'smtp', 'imap', 'pop', 'mx', 'ns', 'ns1', 'ns2', 'dns', 'status', 'support', 'help', 'aide', 'docs', 'blog', 'setup', 'login', 'auth', 'account',
        'plateforme', 'platform', 'agence', 'agency', 'agences', 'agencies', 'master', 'postgres', 'template', 'gmasuite', 'tenantdefaut', 'default', 'root', 'system',
    ];

    public static function valid(string $code): bool
    {
        // « -v2 » en fin de code : la résolution d'un hôte retire ce suffixe (`TenantConnectionManager::findTenantConfigByHost`),
        // l'adresse `<code>.<domaine>` mènerait donc à un autre site, ou à rien
        return preg_match(self::PATTERN, $code) === 1 && !str_ends_with($code, '-v2');
    }

    public static function reserved(string $code): bool
    {
        return in_array($code, self::RESERVED, true) || in_array($code, AgencyService::RESERVED_SITES, true);
    }

    /** Le nom de la base du site */
    public static function database(string $code): string
    {
        return 'db_' . str_replace('-', '_', $code);
    }
}
