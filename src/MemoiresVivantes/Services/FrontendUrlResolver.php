<?php

namespace App\MemoiresVivantes\Services;

use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adresse du site frontend (Next.js) du tenant courant, pour les liens envoyés aux utilisateurs : activation de
 * compte, partage de chapitre, retour de paiement.
 *
 * Ordre : l'origine de la requête (le frontend qui appelle l'API, y compris en développement local), sinon le domaine
 * propre du tenant, sinon `<code>.<FRONTEND_BASE_DOMAIN>`, sinon MEMOIRES_FRONTEND_URL. Avant, MEMOIRES_FRONTEND_URL
 * servait à tous les sites : les liens du site « demo » renvoyaient vers le site Mémoires Vivantes.
 */
class FrontendUrlResolver
{
    public function __construct(
        private readonly TenantConnectionManager $tenantManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function baseUrl(?Request $request = null): string
    {
        if ($request !== null) {
            foreach (['Origin', 'Referer'] as $header) {
                $origin = $this->originOf((string) $request->headers->get($header));
                // Une page du backend lui-même (EasyAdmin, page de debug) n'est pas le frontend
                if ($origin !== null && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $request->getHost()) !== 0) {
                    return $origin;
                }
            }
        }

        $code = (string) $this->tenantManager->getCurrentTenantCode();
        try {
            $stmt = $this->tenantManager->getPdoMaster()->prepare('SELECT custom_domain FROM tenants WHERE code = :code');
            $stmt->execute(['code' => $code]);
            $customDomain = trim((string) $stmt->fetchColumn());
            if ($customDomain !== '') {
                return 'https://' . $customDomain;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FrontendUrlResolver : domaine du site non lu : ' . $e->getMessage());
        }

        if ($code !== '' && !empty($_ENV['FRONTEND_BASE_DOMAIN'])) {
            return 'https://' . $code . '.' . $_ENV['FRONTEND_BASE_DOMAIN'];
        }

        return rtrim($_ENV['MEMOIRES_FRONTEND_URL'] ?? ('https://memoiresvivantes.' . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com')), '/');
    }

    /** Origine d'un frontend : http(s), pas le backend lui-même */
    private function originOf(string $value): ?string
    {
        if ($value === '' || !preg_match('#^(https?://[^/\s]+)#i', $value, $m)) {
            return null;
        }
        $origin = $m[1];
        $host = (string) parse_url($origin, PHP_URL_HOST);
        $backendDomain = $_ENV['BACKEND_BASE_DOMAIN'] ?? 'backend-strapi.online';
        if ($host === '' || $host === $backendDomain || str_ends_with($host, '.' . $backendDomain)) {
            return null;
        }

        return $origin;
    }
}
