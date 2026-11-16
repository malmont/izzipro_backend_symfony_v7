<?php

namespace App\Security;

use App\Services\AppMembershipService\AppJoinTokens;
use App\Services\Http\RequestPath;
use App\Services\Storefront\StorefrontException;
use App\Services\Storefront\StorefrontService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Une boutique « en construction » (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`) : le serveur ferme lui-même ce
 * que la page « Bientôt ouvert » cache. La construction ne vaut que si le module Boutique est actif, et elle ne ferme que la BOUTIQUE
 * EN LIGNE et, pour un site neuf, le SITE VITRINE (décisions du propriétaire). Deux étendues (`StorefrontService::closure`) :
 * - `site` : un site né avec sa boutique, pas encore ouvert : la boutique ET le site vitrine sont fermés ;
 * - `shop` : la boutique ajoutée à un site déjà en service : la boutique seule est fermée, le site vitrine reste servi.
 *
 * FERMÉ PAR DÉFAUT sur ce périmètre : toute route est tenue pour une route de la BOUTIQUE, sauf celles que ces listes rangent ailleurs.
 * Une route nouvelle que personne n'a rangée est donc fermée en construction.
 * - `KEPT` : servi à tous, toujours (connexion, mot de passe, découverte et état du site, fiche du site, fichiers, espace
 *   d'administration, entrée des applications, appels de machines).
 * - `APPS` : Mémoires Vivantes, Living Memory, Boussole et les routes de compte qu'elles partagent. Ces applications sont
 *   indépendantes de la boutique : elles répondent comme sur un site ouvert, régies par leurs seuls droits de module. Un compte né
 *   par elles n'ouvre pas la boutique : seuls un compte d'équipe ou un lien d'aperçu la montrent.
 * - `SHOWCASE` : le site vitrine. Fermé dans l'étendue `site`, servi dans l'étendue `shop`.
 * - Tout le reste : la boutique. Refusé au visiteur et au compte client ; servi à un compte d'ÉQUIPE connecté (`StorefrontViewer`) ;
 *   servi en LECTURE (`GET`, `HEAD`) à qui présente un jeton d'aperçu valable (en-tête `X-Storefront-Preview`).
 * - Écritures fermées à TOUS, équipe et aperçu compris : commander, payer, s'inscrire à la boutique (`SHOP_ORDERS`), et, dans l'étendue
 *   `site`, écrire par un formulaire du site vitrine (`SHOWCASE_ORDERS`). La vente en magasin (`/api/order/create-multi-payment`,
 *   routes `/api/pos`) n'en fait pas partie. La commande d'essai viendra plus tard.
 *
 * Refus : 403 `STOREFRONT_UNDER_CONSTRUCTION { name, scope }`. Après le routage et le pare-feu : le compte est connu. Le chemin est lu
 * décodé (`RequestPath`), jamais brut. Le jeton d'aperçu n'est écrit nulle part. Une réponse d'une route fermée (refus, lecture
 * d'équipe, lecture d'aperçu) part en `private, no-store`. Un site ouvert, ou sans boutique, ne passe par aucune de ces règles.
 *
 * `StorefrontTest` relit les routes de l'application (leur chemin, hors `/admin` et outils du développement) et écrit en toutes
 * lettres celles de `KEPT` et de `APPS` hors Mémoires Vivantes et Boussole : une route qui deviendrait ouverte par accident le fait échouer.
 */
class StorefrontGateSubscriber implements EventSubscriberInterface
{
    public const CODE = 'STOREFRONT_UNDER_CONSTRUCTION';

    /** Servi à tous, toujours : chemin décodé, expressions ancrées */
    public const KEPT = [
        // connexion, mot de passe, session
        '#^/api/(login|login-web|logout|otp-verify|token/refresh)$#',
        '#^/api/password-reset/(request|confirm|verify|form)$#',
        '#^/(|logout|account/otp|password-reset/(confirm|form))$#', // « / » : le formulaire de connexion de l'espace d'administration
        // découverte et état du site, fiche du site (réduite aux mentions légales dans l'étendue « site » : `EntrepriseController`)
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
        // entrée des applications (activation, profil, thème, PIN de caisse)
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

    /** Applications indépendantes de la boutique, et routes de compte qu'elles partagent : comme sur un site ouvert */
    public const APPS = [
        '#^/api/(memoires|boussole)(/|$)#',
        '#^/api/(account|profile|notifications)(/|$)#',
        '#^/api/(validate-token|resend-verification|stripe-config)$#',
        '#^/api/r/#',
        '#^/api/apps/(livingmemory|pos)/terms$#',
        '#^/verify/email$#',
    ];

    /**
     * Le site vitrine : fermé pour un site neuf (`site`), servi quand seule la boutique est en construction (`shop`). Rien de la
     * boutique ici : `/api/landingpage` (les articles de la page d'accueil), `/api/customization` (options et prix d'un article) et
     * `/api/booking` (disponibilité d'un article en location) sont des routes de la boutique. L'essai écrit ces routes en toutes lettres.
     */
    public const SHOWCASE = [
        '#^/api/(landingpage-settings|components-config|presentations|presentation-groups|bannieres|baniere-statiques|homeslider|explore-cards|features|videos|shared-media|embeds|multiliens|recherches|service-offers|service-families|team|emplois|candidatures|vehicles|vehicle_products|vehicle-products|financement|offers|admin-settings|contacts|contact|newsletter|form-submissions|reservations|social-networks)(/|\.|$)#',
        '#^/(videos|shared-media|contact|cgu)(/|$)#',
    ];

    /** Écritures de la boutique : fermées à TOUS dans les deux étendues (les pages de l'ancienne boutique écrivent aussi sur GET) */
    public const SHOP_ORDERS = [
        '#^/api/(order/create|order/create-guest|payment|cart/quote|stripe/create-intent|subscriptions|subscriptions/\d+/(change-plan|resume)|products/\d+/reviews|import-invitation/accept)$#',
        '#^/register$#',
    ];
    public const SHOP_ORDER_PAGES = '#^/(checkout|cart|create-checkout-session|stripe-payment-succes|stripe-payment-cancel)(/|$)#';
    /** Inscription et adhésion : fermées quand elles visent la boutique, ouvertes pour une autre application (`app` du corps) */
    public const SHOP_SIGNUP = '#^/api/(register|apps/join)$#';
    /** Écritures par un formulaire du site vitrine : fermées à TOUS dans l'étendue `site` seulement */
    public const SHOWCASE_ORDERS = [
        '#^/api/(contact/submit|contacts|contacts/create|newsletter/subscribe|form-submissions|reservations|candidatures|financement/submit)$#',
        '#^/contact(/|$)#',
    ];

    public function __construct(private readonly StorefrontService $storefront, private readonly StorefrontViewer $viewer, private readonly AppJoinTokens $joinTokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // après le routage (32) et le pare-feu (8) ; la réponse est corrigée après les autres abonnés (cache des lectures publiques)
        return [KernelEvents::REQUEST => ['onKernelRequest', 5], KernelEvents::RESPONSE => ['onKernelResponse', -200]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $scope = $this->storefront->closure();
        if ($scope === null) {
            return;
        }
        $request = $event->getRequest();
        $path = RequestPath::of($request);
        $write = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        // 1. écritures fermées à tous
        if (preg_match(self::SHOP_ORDER_PAGES, $path) === 1 || ($write && (self::matches(self::SHOP_ORDERS, $path) || (preg_match(self::SHOP_SIGNUP, $path) === 1 && $this->signsUpForShop($path, $request))))
            || ($write && $scope === StorefrontService::SCOPE_SITE && self::matches(self::SHOWCASE_ORDERS, $path))) {
            $event->setResponse($this->refusal($path, $scope));

            return;
        }
        // 2. ce qui n'est pas la boutique (ni, pour un site neuf, le site vitrine)
        if (!self::closed($path, $scope)) {
            return;
        }
        // 3. la boutique : l'équipe, ou un lien d'aperçu en lecture
        if ($this->viewer->isTeam($request) || (!$write && $this->storefront->previewValid($request->headers->get(StorefrontService::PREVIEW_HEADER)))) {
            return;
        }
        $event->setResponse($this->refusal($path, $scope));
    }

    /** La réponse d'une route fermée (refus, lecture d'équipe, lecture d'aperçu) ne se garde dans aucun cache partagé */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $scope = $this->storefront->closure();
        $path = RequestPath::of($event->getRequest());
        if ($scope === null || !self::closed($path, $scope)) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary(array_values(array_unique([...$response->getVary(), StorefrontService::PREVIEW_HEADER, 'Authorization', 'Cookie'])));
    }

    /** Ce chemin (décodé) est-il fermé au visiteur dans cette étendue ? Tout ce qui n'est rangé nulle part est de la boutique : fermé */
    public static function closed(string $path, string $scope): bool
    {
        if (self::matches(self::KEPT, $path) || self::matches(self::APPS, $path) || preg_match(self::SHOP_SIGNUP, $path) === 1) {
            return false; // l'inscription et l'adhésion à la boutique sont refusées plus haut, à tous ; pour une autre application, elles sont servies
        }

        return $scope === StorefrontService::SCOPE_SITE || !self::matches(self::SHOWCASE, $path);
    }

    /** `kept`, `apps`, `showcase` ou `shop` : la classe d'un chemin (pour l'essai qui relit toutes les routes) */
    public static function classOf(string $path): string
    {
        return match (true) {
            self::matches(self::KEPT, $path) => 'kept',
            self::matches(self::APPS, $path) || preg_match(self::SHOP_SIGNUP, $path) === 1 => 'apps',
            self::matches(self::SHOWCASE, $path) => 'showcase',
            default => 'shop',
        };
    }

    /** Une écriture fermée à tous dans cette étendue ? (sans le cas de l'inscription, qui dépend du corps) */
    public static function order(string $path, string $scope): bool
    {
        return preg_match(self::SHOP_ORDER_PAGES, $path) === 1 || self::matches(self::SHOP_ORDERS, $path) || ($scope === StorefrontService::SCOPE_SITE && self::matches(self::SHOWCASE_ORDERS, $path));
    }

    /** Un compte d'équipe du site (pas un client) : il voit la vraie boutique sans lien d'aperçu @param list<string> $roles */
    public static function isTeam(array $roles): bool
    {
        return $roles !== [] && AppFamilies::isTeam($roles);
    }

    /**
     * L'inscription ou l'adhésion vise-t-elle la boutique ?
     * - Inscription (`POST /api/register`) : oui, sauf si le champ `app` du corps nomme une autre application (lu comme le lit
     *   l'inscription elle-même, `AppFamilies::of`) ; absent ou inconnu : la boutique.
     * - Adhésion (`POST /api/apps/join { joinToken, … }`) : la famille est celle du JETON de passage, jamais un champ du corps (un
     *   corps forgé ne déplace pas une adhésion à la boutique vers une autre application). Jeton illisible : la garde laisse la
     *   route le refuser elle-même, rien n'est créé.
     */
    private function signsUpForShop(string $path, Request $request): bool
    {
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        if (str_ends_with($path, '/apps/join')) {
            return ($this->joinTokens->read($body['joinToken'] ?? null)['app'] ?? null) === AppFamilies::BOUTIQUE;
        }

        return (AppFamilies::of($body['app'] ?? null) ?? AppFamilies::BOUTIQUE) === AppFamilies::BOUTIQUE;
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

    private function refusal(string $path, string $scope): Response
    {
        $error = new StorefrontException(self::CODE, 403, ['name' => $this->storefront->current()['name'], 'scope' => $scope]);
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store'];
        if (str_starts_with($path, '/api/')) {
            return new JsonResponse($error->body(), $error->status, $headers);
        }

        // une page du serveur : une page sobre, sans rien du site que son nom
        return new Response('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars((string) $error->extra['name'], ENT_QUOTES)
            . '</title></head><body><h1>' . htmlspecialchars((string) $error->extra['name'], ENT_QUOTES) . '</h1><p>Bientôt ouvert.</p></body></html>', $error->status, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
