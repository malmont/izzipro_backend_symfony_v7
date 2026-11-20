<?php

namespace App\Support;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Assistance, lot 1 (décision du propriétaire du 11/11/2026) : un compte Assistance SEUL (ROLE_SUPPORT sans aucun autre rôle d'accès) n'ouvre que
 * le module. Plusieurs routes de l'API ne demandent qu'un utilisateur connecté (IS_AUTHENTICATED_FULLY, ou ROLE_USER que User::getRoles ajoute à
 * tout compte) : sans cette garde, il y entrerait. Après le pare-feu (priorité 8) : hors /api/support, toute route qui n'est pas publique dans
 * l'access_control (security.yaml, lu par la carte d'accès de Symfony) répond 403 SUPPORT_SCOPE_ONLY ; sur une route publique, l'authentification
 * est retirée : la route est servie comme à un anonyme (profil d'apparence de l'application, offres…), jamais avec les données du compte.
 */
class SupportScopeSubscriber implements EventSubscriberInterface
{
    public const CODE = 'SUPPORT_SCOPE_ONLY';
    /** Rôles qui donnent un autre accès que l'assistance : un compte qui en a un n'est pas « Assistance seul » */
    private const OTHER_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_INTERNET', 'ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_COMPANY', 'ROLE_CONSULTANT'];
    /**
     * Lot CMD-WEB-1 (20/11/2026, matrice validée par le chef) : hors du module, les SEULES routes ouvertes au compte Assistance, [méthode,
     * chemin exact] : les commandes en lecture (vue limitée, `OrderDeskView`), y compris depuis un ticket ; les rendez-vous de la boutique
     * (lire, confirmer, annuler : son profil). Toute autre route garde le refus SUPPORT_SCOPE_ONLY.
     */
    public const OPEN = [
        ['GET', '#^/api/site/commandes/(a-traiter|\d+)\z#'],
        ['GET', '#^/api/site/reservations-boutique(/\d+)?\z#'],
        ['POST', '#^/api/site/reservations-boutique/\d+/(confirmer|annuler)\z#'],
    ];

    public function __construct(private readonly TokenStorageInterface $tokens, #[Autowire(service: 'security.access_map')] private readonly AccessMapInterface $accessMap)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = \App\Services\Http\RequestPath::of($request);
        if (!$event->isMainRequest() || !str_starts_with($path, '/api/') || preg_match('#^/api/support(/|$)#', $path)) {
            return;
        }
        if (!self::supportOnly($this->tokens->getToken()?->getRoleNames() ?? [])) {
            return;
        }
        foreach (self::OPEN as [$method, $pattern]) {
            if ($request->getMethod() === $method && preg_match($pattern, $path)) {
                return; // lot CMD-WEB-1 : la règle d'accès de security.yaml exige encore ROLE_SUPPORT ou ROLE_ADMIN
            }
        }
        if ($path === \App\Services\Screens\ScreenRegistry::CONTEXTS_PATH) {
            return; // lot AG-3a : le compte lit SES contextes (son mode, ses sites, ses écrans) ; rien d'autre ne s'ouvre
        }
        // Lot AG-1 : un compte d'assistance qui porte aussi un rôle d'agence garde l'espace de son agence (sa règle d'accès exige ce rôle)
        if (preg_match('#^/api/agency(/|$)#', $path) && \App\Security\AgencyRoles::has($this->tokens->getToken()?->getRoleNames() ?? [])) {
            return;
        }
        [$attributes] = $this->accessMap->getPatterns($request);
        if ($attributes === null || $attributes === [] || in_array('PUBLIC_ACCESS', $attributes, true)) {
            $this->tokens->setToken(null); // route publique : servie EXACTEMENT comme à un anonyme (le contrôleur ne voit aucun utilisateur ; pare-feu sans état)
            return;
        }
        $event->setResponse(self::refusal());
    }

    /** Un compte Assistance SEUL : ROLE_SUPPORT sans aucun autre rôle d'accès */
    public static function supportOnly(array $roles): bool
    {
        return in_array('ROLE_SUPPORT', $roles, true) && array_intersect(self::OTHER_ROLES, $roles) === [];
    }

    /**
     * Le refus rendu à un compte Assistance seul hors du module. Rendu ici quand la route ne demande qu'un compte connecté, et par
     * `ApiExceptionSubscriber` quand c'est la règle d'accès du pare-feu qui refuse (elle passe avant cette garde) : le même code des
     * deux côtés (15/11/2026 ; avant, le second cas rendait FORBIDDEN, et la fiche annonçait SUPPORT_SCOPE_ONLY).
     */
    public static function refusal(): JsonResponse
    {
        return new JsonResponse(['error' => self::CODE, 'code' => self::CODE, 'message' => 'Un compte Assistance n\'accède qu\'aux demandes d\'assistance.'], 403);
    }
}
