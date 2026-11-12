<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Variante d'hôte du cache d'un site (IMGHOST, 13/11/2026). Beaucoup de lectures mises en cache fabriquent des adresses (images, médias)
 * avec l'hôte de la requête ; la clé ne portait que le code du site : le premier appelant fixait l'hôte de toutes les adresses jusqu'à
 * l'expiration. La clé porte désormais le schéma et l'hôte de la requête, NORMALISÉ parmi les noms connus du site :
 *  - <code>.<domaine de base> (frontend ou backend, sous-domaines compris), le domaine de base lui-même (hôte partagé du backend, le site
 *    venant de X-Tenant-Host), le domaine propre du site (avec ou sans www.), localhost ;
 *  - tout autre hôte retombe sur la variante canonique du site (https://<code>.<domaine du backend>), en lecture seule : rien n'est
 *    enregistré sous un hôte inconnu. Le nombre de variantes est donc borné par le code, et un Host inattendu ne peut ni multiplier les
 *    entrées, ni faire servir à d'autres des adresses fabriquées avec son nom.
 * Hors requête (workers, commandes) : aucune variante, clé du site seule.
 */
class TenantHostVariant
{
    /** @var \WeakMap<Request, array<string, array{0: string, 1: bool}|null>> */
    private \WeakMap $memo;

    public function __construct(private readonly RequestStack $requests, private readonly TenantConnectionManager $tenants, private readonly TenantConnectionProvider $tcp)
    {
        $this->memo = new \WeakMap();
    }

    /** @return array{0: string, 1: bool}|null [variante (schéma et hôte), enregistrable] ; null hors requête ou hors site */
    public function current(): ?array
    {
        $request = $this->requests->getMainRequest();
        $code = (string) $this->tcp->getTenantCode();
        if ($request === null || $code === '') {
            return null;
        }
        $memo = $this->memo[$request] ?? [];
        if (!array_key_exists($code, $memo)) {
            $memo[$code] = $this->resolve($request, $code);
            $this->memo[$request] = $memo;
        }

        return $memo[$code];
    }

    /** @return array{0: string, 1: bool} */
    private function resolve(Request $request, string $code): array
    {
        $variant = strtolower($request->getScheme() . '://' . $request->getHttpHost()); // getHttpHost : sans le port par défaut
        if ($this->isKnown(strtolower($request->getHost()), $code)) {
            return [$variant, true];
        }
        $canonical = $this->canonical($code);

        return $canonical !== null ? [$canonical, false] : [$variant, true]; // aucun domaine configuré (poste de développement) : hôte tel quel
    }

    public function isKnown(string $host, string $code): bool
    {
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if (in_array($bare, ['localhost', '127.0.0.1'], true) || in_array($bare, $this->tenants->getSiteBaseDomains(), true)) {
            return true;
        }
        $slug = str_replace('_', '-', strtolower($code));
        if (str_contains($bare, '.') && str_replace('_', '-', (string) $this->tenants->codeFromHost($host)) === $slug) {
            return true;
        }
        $custom = $this->tenants->customDomainOf($code);

        return $custom !== null && $bare === $custom;
    }

    /** Variante canonique du site : son hôte du backend, sinon son domaine propre */
    public function canonical(string $code): ?string
    {
        $bases = $this->tenants->getSiteBaseDomains();
        if ($bases !== []) {
            return 'https://' . str_replace('_', '-', strtolower($code)) . '.' . end($bases);
        }
        $custom = $this->tenants->customDomainOf($code);

        return $custom !== null ? 'https://' . $custom : null;
    }
}
