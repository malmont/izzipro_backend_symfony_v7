<?php

namespace App\Services\Site;

use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;

/**
 * Adresse d'un écran de l'espace d'administration du web, pour les liens des courriels et des notifications du serveur (lot LIEN,
 * 14/11/2026, `docs/liens-espace-administration.md`). Une seule forme : `<adresse>/admin/ouvrir?screen=<code>&<paramètres>` ; le web
 * la traduit vers son écran. L'adresse est le réglage du site « Adresse de l'espace d'administration » (fiche du site) ; sans réglage,
 * le domaine principal du site (son domaine propre, sinon son adresse sur la plateforme), en https.
 */
class AdminSpaceUrl
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
    ) {
    }

    /**
     * Le lien vers un écran, ou null : aucune adresse connue pour le site, code d'écran hors de la liste de référence
     * (`AdminSpaceLinks::LINKS`), ou paramètre obligatoire manquant. Un paramètre inconnu ou mal formé est écarté.
     *
     * @param array<string, mixed> $params
     */
    public function to(string $screen, array $params = []): ?string
    {
        $query = AdminSpaceLinks::query($screen, $params);
        $base = $query === null ? null : $this->base();
        if ($base === null) {
            return null;
        }

        return $base . AdminSpaceLinks::PATH . '?' . http_build_query(['screen' => $screen] + $query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Le lien où un compte invité choisit son mot de passe : `<adresse>/admin/activer#token=<jeton>`. Le jeton (64 caractères
     * hexadécimaux, à usage unique) est dans le fragment, jamais dans les paramètres : voir `AdminSpaceLinks::ACTIVATION_PATH`.
     * null sans adresse connue, ou pour un jeton mal formé.
     */
    public function activation(string $token): ?string
    {
        $base = preg_match('/^[0-9a-f]{64}$/', $token) ? $this->base() : null;

        return $base === null ? null : $base . AdminSpaceLinks::ACTIVATION_PATH . '#token=' . $token;
    }

    /** L'adresse de l'espace d'administration du site courant, sans barre finale ; null si le site n'a ni réglage ni domaine connu */
    public function base(): ?string
    {
        $explicit = $this->explicit();
        if ($explicit !== null) {
            return $explicit;
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        $host = $code !== '' ? ($this->hosts->describe($code)['host'] ?? null) : null;

        return is_string($host) && $host !== '' ? 'https://' . $host : null;
    }

    /** Le réglage du site, s'il est rempli et bien formé (une valeur mal formée en base est ignorée : on retombe sur le domaine du site) */
    public function explicit(): ?string
    {
        try {
            $value = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT admin_space_url FROM entreprise ORDER BY id LIMIT 1');
        } catch (\Throwable) {
            return null; // colonne absente (base pas encore migrée) ou site non résolu : pas de réglage
        }

        return self::normalize(is_string($value) ? $value : null);
    }

    /**
     * Une adresse acceptée : `https://hôte[:port][/chemin]`, sans identifiants, sans paramètres ni ancre ; rendue sans barre finale.
     * null pour une valeur vide ou refusée.
     */
    public static function normalize(?string $url): ?string
    {
        $url = $url !== null ? trim($url) : '';
        if ($url === '' || strlen($url) > 255 || preg_match('/\s/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $parts['host']) || !str_contains($parts['host'], '.')) {
            return null;
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim((string) ($parts['path'] ?? ''), '/');
    }
}
