<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cache HTTP des lectures publiques de la boutique (09/10/2026, demande de performance du frontend) : ETag et réponse
 * 304 sur If-None-Match, Cache-Control public avec max-age et stale-while-revalidate, Vary sur le site (X-Tenant-Host)
 * et la langue. Seulement : GET/HEAD, routes listées ci-dessous (aucune donnée de client), réponse 200 en JSON.
 *
 * Requête d'un utilisateur connecté (jeton auth_token_<site> ou en-tête Authorization : éditeur, administrateur) :
 * private, no-cache, avec ETag (revalidée à chaque appel, 304 si rien n'a changé), pour qu'un enregistrement dans
 * l'éditeur se voie aussitôt. Une session ouverte rend de toute façon la réponse privée (écouteur de session de Symfony).
 */
final class PublicReadCacheSubscriber implements EventSubscriberInterface
{
    /** Route => max-age en secondes */
    public const ROUTES = [
        'api_boutique_settings_get' => 60,
        'api_tenant_check' => 300,
        'get_products_by_offer' => 60,
        'get_products_by_category' => 60,
        'get_product_by_slug' => 60,
        'get_product_detail' => 60,
        'api_product_reviews' => 60,
        'api_subscription_plans' => 60,
        'get_categories' => 60,
        'get_home_slider' => 60,
        'api_explore_cards' => 60,
        'api_features' => 60,
    ];

    private const STALE_WHILE_REVALIDATE = 600;

    public static function getSubscribedEvents(): array
    {
        // Avant l'écouteur de session (-1000) : si une session a servi, il repasse la réponse en privée
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $maxAge = self::ROUTES[(string) $request->attributes->get('_route')] ?? null;
        if (!$event->isMainRequest() || $maxAge === null || !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || $response->getStatusCode() !== 200 || !str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return;
        }

        $response->setEtag(hash('xxh128', (string) $response->getContent()), true);
        $response->setVary(['X-Tenant-Host', 'Accept-Language'], false);
        if (self::isAuthenticated($request)) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
        } else {
            $response->setPublic();
            $response->setMaxAge($maxAge);
            $response->headers->addCacheControlDirective('stale-while-revalidate', (string) self::STALE_WHILE_REVALIDATE);
            // Routes servies par le pare-feu à session (tenant/check, subscription-plans) : l'objet de session y est
            // initialisé sans être démarré, et Symfony passerait la réponse en privée. Sans cookie de session reçu, session
            // non démarrée et aucun cookie posé, la réponse reste publique (aucun cookie ne peut entrer dans un cache).
            if (!$request->hasPreviousSession() && !($request->hasSession() && $request->getSession()->isStarted()) && $response->headers->getCookies() === []) {
                $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
            }
        }
        $response->isNotModified($request);
    }

    private static function isAuthenticated(Request $request): bool
    {
        if ($request->headers->has('Authorization')) {
            return true;
        }
        foreach (array_keys($request->cookies->all()) as $name) {
            if (str_starts_with((string) $name, 'auth_token_')) {
                return true;
            }
        }

        return false;
    }
}
