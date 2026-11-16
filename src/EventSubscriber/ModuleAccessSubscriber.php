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
        $path = \App\Services\Http\RequestPath::of($request);
        if (!str_starts_with($path, '/api/')) {
            return;
        }
        $module = ModuleAccessService::moduleForPath($path, $request->getMethod()) ?? self::appRightModule($request, $path);
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
            'message' => self::ENDED[$module] ?? sprintf('Le module %s n\'est pas activé sur ce site.', ModuleAccessService::LABELS[$module] ?? $module),
        ] + ($this->hints?->for($module) ?? []), 403)); // lot MOD : feature, requestable, request, trial (l'écran ne propose pas « Demander » deux fois)
    }

    /** Lot ENT-1 : message d'une application dont le droit a pris fin (affiché par la caisse à l'ouverture, par l'application mobile) */
    private const ENDED = [
        ModuleAccessService::POS => 'Le droit à la caisse de ce site a pris fin.',
        ModuleAccessService::MOBILE_SHOP => 'Le droit à l\'application mobile de ce site a pris fin.',
    ];

    /**
     * Lot ENT-1 : le module d'une route de la caisse (un DÉBUT : ouverture de session, nouvelle vente… ; une suite n'est jamais gardée)
     * ou d'un début de parcours de l'application mobile (en-tête `X-App: mobile`). Null : la route n'est pas gardée par ces droits.
     */
    private static function appRightModule(\Symfony\Component\HttpFoundation\Request $request, string $path): ?string
    {
        if (preg_match(\App\Services\FeatureAccess\AppRightRoutes::POS_PATHS, $path) === 1) {
            $body = json_decode((string) $request->getContent(), true);

            return \App\Services\FeatureAccess\AppRightRoutes::pos($request->getMethod(), $path, is_array($body) ? $body : null) === \App\Services\FeatureAccess\AppRightRoutes::START
                ? ModuleAccessService::POS : null;
        }

        return \App\Services\FeatureAccess\AppRightRoutes::mobileStart($request->getMethod(), $path, $request->headers->get(\App\Services\FeatureAccess\AppRightRoutes::MOBILE_HEADER))
            ? ModuleAccessService::MOBILE_SHOP : null;
    }
}
