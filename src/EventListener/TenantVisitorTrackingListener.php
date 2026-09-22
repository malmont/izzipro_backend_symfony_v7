<?php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use App\Services\TenantVisitorTrackingService;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class TenantVisitorTrackingListener
{
    private const EXCLUDED_PREFIXES = [
        '/_profiler',
        '/_wdt',
        '/_error',
        '/admin',
        '/bucket-simulator',
        '/assets',
        '/bundles',
        '/favicon.ico',
        '/robots.txt',
        '/api/tenant/check',
        '/setup/new-store',
    ];

    private const EXCLUDED_EXTENSIONS = [
        'ico', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'css', 'js', 'map'
    ];

    public function __construct(
        private TenantVisitorTrackingService $trackingService,
        private TenantConnectionProvider $tenantConnectionProvider
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // 1. Filtrer les routes exclues
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // 2. Filtrer les extensions de fichiers statiques
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension && in_array(strtolower($extension), self::EXCLUDED_EXTENSIONS, true)) {
            return;
        }

        // 3. Récupérer le code du tenant courant
        $tenantCode = $this->tenantConnectionProvider->getTenantCode();

        // Fallback: chercher dans le header X-Tenant-Host
        if (!$tenantCode) {
            $host = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
            $parts = explode('.', explode(':', $host)[0]);
            if (!empty($parts[0]) && !in_array($parts[0], ['api', 'admin', 'backend', 'localhost', 'www'], true)) {
                $tenantCode = preg_replace('/-v2$/i', '', $parts[0]);
            }
        }

        if (!$tenantCode) {
            return;
        }

        // 4. Déterminer la page réelle vue par l'internaute (les appels API internes sont rattachés à la Landing Page ou à la page appelante)
        $trackedPath = '/'; // Par défaut, la Landing Page

        $referer = $request->headers->get('Referer');
        if (!empty($referer)) {
            $parsedReferer = parse_url($referer);
            $refererPath = !empty($parsedReferer['path']) ? $parsedReferer['path'] : '/';

            if (!str_starts_with($refererPath, '/admin') && !str_starts_with($refererPath, '/api')) {
                $trackedPath = $refererPath;
            }
        } elseif (!str_starts_with($path, '/api/')) {
            // Requête web directe (non-API)
            $trackedPath = $path ?: '/';
        } else {
            // Appel API direct sans Referer (ex: SSR Next.js au premier chargement)
            // Si c'est une ressource spécifique à une page (ex: services, contact), on la rattache à cette page
            if (preg_match('#^/api/(services|contact|tarifs|apropos|about)#i', $path, $m)) {
                $trackedPath = '/' . strtolower($m[1]);
            } else {
                // Tout le reste (features, homeslider, explore-cards, entreprise, carrier...) compose la Landing Page
                $trackedPath = '/';
            }
        }

        $ip = $request->getClientIp() ?: '127.0.0.1';
        $userAgent = $request->headers->get('User-Agent', '');

        $this->trackingService->trackVisit($tenantCode, $ip, $userAgent, $trackedPath);
    }
}
