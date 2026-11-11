<?php

namespace App\EventSubscriber;

use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Platform\PlatformModeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuse les routes d'API d'un module non activé sur le site : 403 `MODULE_INACTIVE` (`module`, `message`), code stable que
 * les applications traduisent (console de pilotage, lot 1 bis, 04/11/2026). Après la bascule de site (priorité 100) et le
 * limiteur de débit (20) ; avant le pare-feu, donc même réponse connecté ou non. EasyAdmin (`/admin`) n'est pas concerné.
 * Inactif tant que la plateforme est en mode `demo` (PlatformModeService, défaut).
 */
class ModuleAccessSubscriber implements EventSubscriberInterface
{
    public const CODE = 'MODULE_INACTIVE';

    public function __construct(private readonly ModuleAccessService $modules, private readonly PlatformModeService $platform, private readonly LoggerInterface $logger,
        private readonly ?\App\Services\FeatureAccess\ModuleRequestHint $hints = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 15]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }
        $module = ModuleAccessService::moduleForPath($path, $request->getMethod());
        if ($module === null) {
            return;
        }
        try {
            if ($this->platform->isDemo()) {
                return; // mode demo de la plateforme : aucune restriction (PlatformModeService)
            }
            if ($this->modules->isEnabled($module)) {
                return;
            }
        } catch (\Throwable $e) {
            // site non résolu ou base indisponible : on laisse la suite de la chaîne répondre (404 site inconnu, 500…)
            $this->logger->warning('ModuleAccessSubscriber : indicateurs de modules illisibles, requête laissée passer : ' . $e->getMessage());

            return;
        }
        $this->logger->info(sprintf('Module %s inactif : %s %s refusé', $module, $request->getMethod(), $path));
        $event->setResponse(new JsonResponse([
            'error' => self::CODE,
            'code' => self::CODE,
            'module' => $module,
            'message' => sprintf('Le module %s n\'est pas activé sur ce site.', ModuleAccessService::LABELS[$module] ?? $module),
        ] + ($this->hints?->for($module) ?? []), 403)); // lot MOD : feature, requestable, request, trial (l'écran ne propose pas « Demander » deux fois)
    }
}
