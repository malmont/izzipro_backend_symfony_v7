<?php

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Garde-fou : toute route d'écriture (POST, PUT, PATCH, DELETE) exige un rôle dans access_control (security.yaml),
 * sauf les écritures publiques par nature, listées ci-dessous avec leur protection. Jusqu'au 29/09/2026,
 * VideoApiController répondait aussi sous /videos (hors /api) : n'importe qui pouvait créer, modifier ou supprimer
 * les vidéos d'un site.
 */
class AnonymousWriteAccessTest extends WebTestCase
{
    /**
     * Écritures ouvertes sans rôle, volontairement : motif de chemin => raison (protection ailleurs).
     * Toute nouvelle entrée doit être justifiée.
     */
    private const PUBLIC_WRITES = [
        '#^/api/(login|logout|token/refresh|register|password-reset/|otp-verify|resend-verification)#' => 'authentification (limites de débit, jetons)',
        '#^/(logout|register|password-reset/confirm|contact/)$#' => 'anciennes pages Twig (formulaires avec jeton CSRF)',
        '#^/$#' => 'page de connexion (LoginAuthenticator, limite de tentatives)',
        '#^/cart#' => 'panier de l\'ancienne boutique (session du visiteur)',
        '#^/(create-checkout-session|stripe-payment-(cancel|succes))/#' => 'retours de paiement Stripe',
        '#^/(api/)?stripe/(webhook|create-intent)#' => 'Stripe (signature du webhook, intention de paiement)',
        '#^/api/(stripe-config|webhooks/)#' => 'webhooks externes (signature)',
        '#^/api/(order/create-guest|order/create$|payment)#' => 'tunnel de commande invité',
        '#^/api/cart/quote$#' => 'devis du panier : lecture seule, rien n\'est réservé ni enregistré (le visiteur n\'est pas forcément connecté)',
        '#^/api/(contact/submit|contacts|candidatures|newsletter/subscribe|financement|booking|reservations)#' => 'formulaires publics (limite par IP)',
        '#^/api/(adresses|shipping|transporteurs|Carrier)#' => 'calculs d\'adresse et de livraison',
        '#^/api/memoires/#' => 'Mémoires Vivantes : lien de partage (UUID) et voters',
        '#^/api/boussole/auth/#' => 'Boussole ESG : inscription et connexion',
        '#^/booking-setup/#' => 'configuration des réservations : authentification propre au contrôleur (à vérifier)',
        '#^/setup/new-store$#' => 'création de boutique : jeton de création (à vérifier)',
        '#^/api/landingpage-config/#' => 'ROLE_SUPER_ADMIN ou jeton de déploiement, vérifiés par LandingConfigController',
        '#^/api/tenant/#' => 'vérification de tenant',
        '#^/(cgu/|product/|shop$|setup/status/)#' => 'pages d\'affichage sans restriction de méthode (aucune écriture)',
    ];

    public function testEveryWriteRouteRequiresARoleOrIsAnIntendedPublicWrite(): void
    {
        $container = static::getContainer();
        $accessMap = $container->get('security.access_map');
        $unprotected = [];

        foreach ($container->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $path = $route->getPath();
            if (str_starts_with($name, '_') || str_starts_with($path, '/admin')) {
                continue; // outils de développement ; EasyAdmin (^/admin : ROLE_ADMIN, vérifié ci-dessous)
            }
            $sample = preg_replace('/\{\w+[^}]*\}/', '1', $path);
            foreach (array_intersect($route->getMethods() ?: ['POST'], ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                [$roles] = $accessMap->getPatterns(Request::create($sample, $method));
                $public = $roles === null || $roles === [] || $roles === ['PUBLIC_ACCESS'];
                if ($public && !$this->intended($path) && !$this->grantedInCode((string) $route->getDefault('_controller'))) {
                    $unprotected[] = "$method $path ($name)";
                }
            }
        }

        $this->assertSame([], $unprotected, "Écritures accessibles sans rôle : ajouter une règle access_control, ou justifier l'exception dans PUBLIC_WRITES");
        [$adminRoles] = $accessMap->getPatterns(Request::create('/admin/x', 'POST'));
        $this->assertSame(['ROLE_ADMIN'], $adminRoles);
    }

    public function testAnonymousCannotWriteVideosOutsideApi(): void
    {
        $client = static::createClient();
        foreach ([['POST', '/videos'], ['PUT', '/videos/1'], ['DELETE', '/videos/1']] as [$method, $path]) {
            $client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], '{}');
            $status = $client->getResponse()->getStatusCode();
            $this->assertTrue(in_array($status, [302, 401, 403], true), "$method $path : $status (le contrôleur ne doit pas être atteint)");
        }
    }

    /** Rôle exigé par #[IsGranted] sur l'action ou sa classe */
    private function grantedInCode(string $controller): bool
    {
        if (!str_contains($controller, '::')) {
            return false;
        }
        [$class, $method] = explode('::', $controller, 2);
        if (!method_exists($class, $method)) {
            return false;
        }
        $action = new \ReflectionMethod($class, $method);

        return $action->getAttributes(IsGranted::class) !== [] || $action->getDeclaringClass()->getAttributes(IsGranted::class) !== [];
    }

    private function intended(string $path): bool
    {
        foreach (array_keys(self::PUBLIC_WRITES) as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
