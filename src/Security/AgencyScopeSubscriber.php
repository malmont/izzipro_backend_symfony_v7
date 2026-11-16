<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Agences, lot AG-1 (15/11/2026) : un compte d'agence SEUL (rôle d'agence sans aucun autre rôle d'accès) n'ouvre que l'espace de
 * l'agence. Plusieurs routes de l'API ne demandent qu'un utilisateur connecté (IS_AUTHENTICATED_FULLY, ou ROLE_USER que
 * `User::getRoles` ajoute à tout compte) : sans cette garde, il y entrerait. Même mécanique que `SupportScopeSubscriber`, après le
 * pare-feu (priorité 8) : hors `/api/agency`, toute route qui n'est pas publique dans l'access_control répond 403
 * `AGENCY_SCOPE_ONLY` ; sur une route publique, l'authentification est retirée : la route est servie comme à un anonyme.
 *
 * Un compte qui porte aussi `ROLE_SUPPORT` garde `/api/support` (les deux gardes se complètent). Un compte qui porte un autre rôle
 * d'accès (administrateur du site d'attache, client…) n'est pas « agence seul » : ses autres rôles valent comme avant.
 */
class AgencyScopeSubscriber implements EventSubscriberInterface
{
    public const CODE = 'AGENCY_SCOPE_ONLY';
    /** Rôles qui donnent un autre accès que l'agence (hors assistance, traitée à part) : un compte qui en a un n'est pas « agence seul » */
    private const OTHER_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_INTERNET', 'ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_COMPANY', 'ROLE_CONSULTANT'];

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
        if (!$event->isMainRequest() || !str_starts_with($path, '/api/') || preg_match('#^/api/agency(/|$)#', $path)) {
            return;
        }
        if ($path === \App\Services\Screens\ScreenRegistry::CONTEXTS_PATH || $path === \App\Services\Screens\ScreenRegistry::CONTEXTS_PATH . '/enter') {
            return; // lot AG-3a : le compte lit SES contextes (son mode, ses sites, ses écrans) ; lot AG-3b : il entre chez un client d'ici
        }
        $roles = $this->tokens->getToken()?->getRoleNames() ?? [];
        if (!self::agencyOnly($roles)) {
            return;
        }
        if (in_array('ROLE_SUPPORT', $roles, true) && preg_match('#^/api/support(/|$)#', $path)) {
            return; // le compte est aussi un agent d'assistance : son module lui reste ouvert
        }
        [$attributes] = $this->accessMap->getPatterns($request);
        if ($attributes === null || $attributes === [] || in_array('PUBLIC_ACCESS', $attributes, true)) {
            $this->tokens->setToken(null); // route publique : servie EXACTEMENT comme à un anonyme (le contrôleur ne voit aucun utilisateur)

            return;
        }
        $event->setResponse(new JsonResponse(['error' => self::CODE, 'code' => self::CODE, 'message' => 'Un compte d\'agence n\'accède qu\'à l\'espace de l\'agence.'], 403));
    }

    /** Un compte d'agence SEUL : un rôle d'agence sans aucun autre rôle d'accès (l'assistance mise à part) */
    public static function agencyOnly(array $roles): bool
    {
        return AgencyRoles::has($roles) && array_intersect(self::OTHER_ROLES, $roles) === [];
    }
}
