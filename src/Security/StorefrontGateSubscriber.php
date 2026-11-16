<?php

namespace App\Security;

use App\Services\Http\RequestPath;
use App\Services\Storefront\StorefrontException;
use App\Services\Storefront\StorefrontService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Un site « en construction » (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`) : le serveur ferme lui-même ce que
 * la page « Bientôt ouvert » cache. FERMÉ PAR DÉFAUT : sur un site en construction, TOUTE route est refusée au visiteur et au compte
 * client, qu'elle soit de l'API ou une page du serveur, sauf la liste courte `KEPT`. Une route nouvelle est donc fermée sans qu'on y
 * pense ; il n'y a pas de liste des contenus à tenir à jour.
 *
 * - `KEPT` : ce qui reste servi à tous. La connexion et le mot de passe, la découverte du site, son état, sa fiche réduite aux
 *   mentions légales, les fichiers (logo), l'espace d'administration (qui a son propre contrôle d'accès), l'entrée des applications
 *   de l'équipe, les appels de machines (webhooks, déploiement, boîtiers).
 * - Un compte d'ÉQUIPE connecté (`StorefrontViewer`) passe partout, sauf sur `ORDERS`.
 * - Un jeton d'aperçu valable (en-tête `X-Storefront-Preview`) ouvre les LECTURES (`GET`, `HEAD`), jamais une écriture.
 * - `ORDERS` : commander, payer, s'inscrire, écrire par un formulaire public. Fermé à TOUS, équipe et aperçu compris (la commande
 *   d'essai viendra plus tard). La vente en magasin (`/api/order/create-multi-payment`, routes `/api/pos`) n'en fait pas partie.
 *
 * Refus : 403 `STOREFRONT_UNDER_CONSTRUCTION { name }`. Après le routage et le pare-feu : le compte est connu. Le chemin est lu décodé
 * (`RequestPath`), jamais brut. Le jeton d'aperçu n'est écrit nulle part. Toute réponse d'un site en construction part en
 * `private, no-store` : une lecture servie à l'équipe ou par un lien d'aperçu n'est jamais gardée par un cache partagé. Un site
 * « ouvert » ne passe par aucune de ces règles.
 */
class StorefrontGateSubscriber implements EventSubscriberInterface
{
    public const CODE = 'STOREFRONT_UNDER_CONSTRUCTION';

    /**
     * Ce qui reste servi à tous sur un site en construction : chemin décodé, expressions ancrées. `StorefrontTest` relit TOUTES les
     * routes de l'application : la liste des routes gardées ouvertes y est écrite en toutes lettres, une route qui y entrerait par
     * accident fait échouer l'essai.
     */
    public const KEPT = [
        // connexion, mot de passe, session
        '#^/api/(login|login-web|logout|otp-verify|token/refresh)$#',
        '#^/api/password-reset/(request|confirm|verify|form)$#',
        '#^/(|logout|account/otp|password-reset/(confirm|form))$#', // « / » : le formulaire de connexion de l'espace d'administration
        // découverte et état du site, fiche réduite aux mentions légales (`EntrepriseController`)
        '#^/api/(health|currencies)$#',
        '#^/api/tenant/(check|check-mobile|hosts)$#',
        '#^/api/site/storefront(/preview)?$#',
        '#^/api/entreprise/\d+$#',
        // fichiers : le logo de la page « Bientôt ouvert », les médias de l'espace d'administration
        '#^/(assets/uploads|uploads|bucket-simulator|media/secure)/#',
        // espace d'administration et création de site : leur propre contrôle d'accès
        '#^/admin(/|$)#',
        '#^/setup/#',
        '#^/stripe/(connect|disconnect|success)$#',
        // entrée des applications de l'équipe (caisse, gestion) et profils d'application
        '#^/api/app/activate$#',
        '#^/api/app-profile/#',
        '#^/api/mobile/app-theme$#',
        '#^/api/pos/(pin-login|users)$#',
        // appels de machines : paiements, déploiement des réglages, boîtiers
        '#^/(api/)?stripe/webhook$#',
        '#^/api/webhooks/#',
        '#^/api/landingpage-config(/|$)#',
        '#^/api/pertes/boitier/#',
        // outils du développement
        '#^/_(profiler|wdt|error)(/|$)#',
    ];

    /** Écritures de la boutique publique et des formulaires publics : fermées à TOUS, équipe et aperçu compris */
    public const ORDERS = [
        '#^/api/(order/create|order/create-guest|payment|cart/quote|stripe/create-intent|contact/submit|contacts|contacts/create|newsletter/subscribe|form-submissions|reservations|candidatures|financement/submit|register|resend-verification|subscriptions|subscriptions/\d+/(change-plan|resume)|apps/join|products/\d+/reviews|import-invitation/accept)$#',
        '#^/api/booking(/|$)#',
        '#^/(checkout|cart|create-checkout-session|register|contact)(/|$)#',
    ];

    public function __construct(private readonly StorefrontService $storefront, private readonly StorefrontViewer $viewer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // après le routage (32) et le pare-feu (8) ; la réponse est corrigée après les autres abonnés (cache des lectures publiques)
        return [KernelEvents::REQUEST => ['onKernelRequest', 5], KernelEvents::RESPONSE => ['onKernelResponse', -200]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->storefront->isUnderConstruction()) {
            return;
        }
        $request = $event->getRequest();
        $path = RequestPath::of($request);
        $write = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($write && self::matches(self::ORDERS, $path)) {
            $event->setResponse($this->refusal($path));

            return;
        }
        if (self::kept($path)) {
            return;
        }
        if ($this->viewer->isTeam($request) || (!$write && $this->storefront->previewValid($request->headers->get(StorefrontService::PREVIEW_HEADER)))) {
            return;
        }
        $event->setResponse($this->refusal($path));
    }

    /** Sur un site en construction, aucune réponse ne se garde dans un cache partagé (lecture d'équipe, lecture d'aperçu, refus) */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->storefront->isUnderConstruction()) {
            return;
        }
        $path = RequestPath::of($event->getRequest());
        $response = $event->getResponse();
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        if (preg_match('#^/api/site/storefront$#', $path) === 1 || preg_match('#^/(assets/uploads|uploads|bucket-simulator|media/secure)/#', $path) === 1) {
            return; // l'état du site porte son propre cache court ; les fichiers gardent le leur
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary(array_values(array_unique([...$response->getVary(), StorefrontService::PREVIEW_HEADER, 'Authorization', 'Cookie'])));
    }

    /** Ce chemin (décodé) reste-t-il servi à tous sur un site en construction ? */
    public static function kept(string $path): bool
    {
        return self::matches(self::KEPT, $path);
    }

    /** Ce chemin est-il une écriture de la boutique publique ou d'un formulaire public ? */
    public static function order(string $path): bool
    {
        return self::matches(self::ORDERS, $path);
    }

    /** Un compte d'équipe du site (pas un client) : il voit la vraie boutique sans lien d'aperçu @param list<string> $roles */
    public static function isTeam(array $roles): bool
    {
        return $roles !== [] && AppFamilies::isTeam($roles);
    }

    /** @param list<string> $patterns */
    private static function matches(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function refusal(string $path): Response
    {
        $error = new StorefrontException(self::CODE, 403, ['name' => $this->storefront->current()['name']]);
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store'];
        if (str_starts_with($path, '/api/')) {
            return new JsonResponse($error->body(), $error->status, $headers);
        }

        // une page du serveur : une page sobre, sans rien du site que son nom
        return new Response('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars((string) $error->extra['name'], ENT_QUOTES)
            . '</title></head><body><h1>' . htmlspecialchars((string) $error->extra['name'], ENT_QUOTES) . '</h1><p>Bientôt ouvert.</p></body></html>', $error->status, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
