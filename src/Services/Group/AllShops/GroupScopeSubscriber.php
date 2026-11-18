<?php

namespace App\Services\Group\AllShops;

use App\Entity\User;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Group\GroupRepository;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Lot G-2 : garde de « Toutes les boutiques » (`GroupScope`). Elle ne fait rien sans l'en-tête. Avec lui, dans cet ordre :
 * 1. valeur autre que `all` : 400 `GROUP_SCOPE_INVALID` ;
 * 2. méthode autre que GET (ou HEAD) : 403 `GROUP_SCOPE_READ_ONLY` — rien ne s'écrit dans ce mode ;
 * 3. route hors de la liste blanche, ou paramètre refusé (`sections` des statistiques) : 400 `GROUP_SCOPE_UNSUPPORTED` ;
 * 4. compte qui n'est pas le directeur : 403 `GROUP_SCOPE_FORBIDDEN`. Le directeur est un administrateur du siège connecté avec SON
 *    compte (jamais un opérateur entré, jamais un compte de la plateforme), sur le site du siège, groupe actif, module Multi-boutiques
 *    actif au siège. Tout est relu à chaque requête (base maître et modules du site).
 * Le groupe relu est posé dans l'attribut `GroupScope::ATTRIBUTE`, que lisent les contrôleurs des routes qui savent fusionner.
 *
 * Priorité 6 : après le pare-feu (8, le compte est connu, `access_control` appliqué) et après la garde du mode d'entrée (7).
 */
class GroupScopeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly GroupRepository $groups,
        private readonly ModuleAccessService $modules,
        private readonly TenantConnectionProvider $tenants,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $event->hasResponse() || !$request->headers->has(GroupScope::HEADER)) {
            return;
        }
        if ($request->headers->get(GroupScope::HEADER) !== GroupScope::VALUE) {
            $event->setResponse($this->refuse(GroupScope::INVALID, 'Valeur de l\'en-tête X-Group-Scope inconnue : seule « all » est permise.', 400));

            return;
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $event->setResponse($this->refuse(GroupScope::READ_ONLY, 'Toutes les boutiques : lecture seule. Choisissez une boutique pour écrire.', 403));

            return;
        }
        $route = $request->attributes->get('_route');
        $config = is_string($route) ? (GroupScope::ROUTES[$route] ?? null) : null;
        if ($config === null || array_intersect($config['refused'], array_keys(array_filter($request->query->all(), static fn ($v) => $v !== '' && $v !== null))) !== []) {
            $event->setResponse($this->refuse(GroupScope::UNSUPPORTED, 'Cet écran ne sait pas réunir toutes les boutiques. Choisissez une boutique.', 400));

            return;
        }
        if (isset($config['maxDepth'])) {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
            if ($page * $limit > $config['maxDepth']) {
                $event->setResponse($this->refuse(GroupScope::PAGE_TOO_DEEP, sprintf('Toutes les boutiques : au plus %d lignes par boutique (page × limite). Filtrez, ou choisissez une boutique.', $config['maxDepth']), 400));

                return;
            }
        }
        $group = $this->directedGroup();
        if ($group === null) {
            $event->setResponse($this->refuse(GroupScope::FORBIDDEN, 'Toutes les boutiques : réservé au directeur du groupe, sur le site du siège.', 403));

            return;
        }
        $request->attributes->set(GroupScope::ATTRIBUTE, $group);
    }

    /** @return array<string, mixed>|null le groupe que dirige le compte connecté, relu ; null sinon */
    private function directedGroup(): ?array
    {
        $token = $this->tokens->getToken();
        $user = $token?->getUser();
        // un compte du site, jamais un opérateur entré (GroupOperator, ExternalOperator) ni un compte de la plateforme
        if (!$user instanceof User || !in_array('ROLE_ADMIN', $token->getRoleNames(), true) || PlatformAccessJournal::isPlatformAccount($token->getRoleNames())) {
            return null;
        }
        $current = (string) $this->tenants->getTenantCode();
        if ($current === '') {
            return null;
        }
        try {
            $group = $this->groups->ofSite($current);
            if ($group === null || $group['head_tenant_code'] !== $current || !$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
                return null;
            }
        } catch (\Throwable) {
            return null; // base maître ou indicateurs illisibles : fermé
        }

        return $group;
    }

    private function refuse(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'code' => $code, 'message' => $message], $status);
    }
}
