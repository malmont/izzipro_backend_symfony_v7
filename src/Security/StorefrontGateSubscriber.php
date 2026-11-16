<?php

namespace App\Security;

use App\Services\Http\RequestPath;
use App\Services\Storefront\StorefrontException;
use App\Services\Storefront\StorefrontService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Un site « en construction » (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`) : le serveur ferme lui-même ce que
 * la page « Bientôt ouvert » cache.
 *
 * - `ORDERS` : commander, payer, s'inscrire, écrire par un formulaire public. Fermé à TOUS, aperçu et administrateur compris (la
 *   commande d'essai viendra plus tard). Seule exception : l'équipe de la caisse (`ROLE_USER_POS`, `ROLE_POS_MANAGER`) sur les deux
 *   routes qu'elle partage avec la boutique : la vente en magasin n'est pas la boutique publique.
 * - `CONTENT` : les lectures du site public et de la boutique. Fermées au visiteur et au compte client ; servies à un compte d'équipe
 *   connecté, et, pour les lectures seulement, à qui présente un jeton d'aperçu valable (en-tête `X-Storefront-Preview`).
 * - Tout le reste est servi : connexion, mot de passe, fiche du site réduite aux mentions légales (`EntrepriseController`), état du
 *   site, applications de l'équipe, Mémoires Vivantes, Boussole, webhooks.
 *
 * Refus : 403 `STOREFRONT_UNDER_CONSTRUCTION { name }`. Après le pare-feu (priorité 8) : le compte est connu ; sur les routes que le
 * pare-feu de l'API ne couvre pas, `StorefrontViewer` lit lui-même le jeton du site. Le chemin est lu
 * décodé (`RequestPath`), jamais brut. Le jeton d'aperçu n'est écrit nulle part. Un site « ouvert » ne passe par aucune de ces règles.
 *
 * `tests/Functional/Storefront/StorefrontGuardTest` relit la règle des lectures publiques de `security.yaml` : chaque route publique
 * y est classée fermée ou ouverte en construction, pour qu'une route nouvelle ne passe pas sans avoir été rangée.
 */
class StorefrontGateSubscriber implements EventSubscriberInterface
{
    public const CODE = 'STOREFRONT_UNDER_CONSTRUCTION';
    /** Écritures de la boutique publique et des formulaires publics : fermées à tous */
    public const ORDERS = '#^/api/(order/create|order/create-guest|payment|cart/quote|stripe/create-intent|contact/submit|contacts|contacts/create|newsletter/subscribe|form-submissions|reservations|booking(/.*)?|candidatures|financement/submit|register|resend-verification|subscriptions|subscriptions/\d+/(change-plan|resume)|apps/join|products/\d+/reviews)$#';
    /** Les deux routes d'écriture que la caisse partage avec la boutique */
    public const POS_SHARED = '#^/api/(order/create|payment)$#';
    /** Lectures du site public et de la boutique : fermées au visiteur d'un site en construction */
    public const CONTENT = '#^/api/(products|category|homeslider|explore-cards|bannieres|emplois|candidatures|marques|categories-marque|multiliens|recherches|service-offers|service-families|videos|components-config|landingpage-settings|boutique-settings|embeds|baniere-statiques|presentations|landingpage|presentation-groups|subscription-plans|stripe-config|Carrier|shipping|team|vehicles|vehicle_products|vehicle-products|financement|shared-media|features|customization|offers|admin-settings|adresses|booking|reservations|contacts|newsletter|order-statuses|orders|apps)(/|$)#';
    /** Routes publiques de `security.yaml` qui restent servies en construction (connexion, découverte du site, mentions légales, déploiement) */
    public const KEPT = ['login', 'health', 'currencies', 'login-web', 'token/refresh', 'password-reset/request', 'password-reset/confirm', 'password-reset/verify', 'password-reset/form',
        'entreprise', 'otp-verify', 'tenant/check', 'tenant/hosts'];

    public function __construct(private readonly StorefrontService $storefront, private readonly StorefrontViewer $viewer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = RequestPath::of($request);
        if (!$event->isMainRequest() || !str_starts_with($path, '/api/')) {
            return;
        }
        $write = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        $orders = $write && preg_match(self::ORDERS, $path) === 1;
        $content = preg_match(self::CONTENT, $path) === 1;
        if ((!$orders && !$content) || !$this->storefront->isUnderConstruction()) {
            return;
        }
        // les rôles du compte, même sur une route hors du pare-feu de l'API (`StorefrontViewer`)
        $roles = $this->viewer->roles($request);
        if ($orders) {
            if (preg_match(self::POS_SHARED, $path) === 1 && array_intersect(['ROLE_USER_POS', 'ROLE_POS_MANAGER'], $roles) !== []) {
                return;
            }
            $event->setResponse($this->refusal());

            return;
        }
        if (self::isTeam($roles) || (!$write && $this->storefront->previewValid($request->headers->get(StorefrontService::PREVIEW_HEADER)))) {
            return;
        }
        $event->setResponse($this->refusal());
    }

    /** Un compte d'équipe du site (pas un client) : il voit la vraie boutique sans lien d'aperçu @param list<string> $roles */
    public static function isTeam(array $roles): bool
    {
        return $roles !== [] && AppFamilies::isTeam($roles);
    }

    private function refusal(): JsonResponse
    {
        $error = new StorefrontException(self::CODE, 403, ['name' => $this->storefront->current()['name']]);

        return new JsonResponse($error->body(), $error->status, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store']);
    }
}
