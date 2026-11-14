<?php

namespace App\LossPrevention;

use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\FeatureAccess\ModuleRequestHint;
use App\Services\Http\RequestPath;
use App\Services\Platform\PlatformModeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Garde du module Prévention des pertes (lot L1-a, 14/11/2026). Ses routes sont sous `/api/gestion/pertes` : la garde commune
 * (`ModuleAccessSubscriber`, priorité 15) exige déjà la gestion (`app.management`) ; celle-ci, juste après (priorité 14), exige EN PLUS le
 * droit `module.loss_prevention`. Même réponse que la garde commune : 403 `MODULE_INACTIVE` avec `module`, `feature` (la clé refusée, toujours
 * présente : les écrans s'en servent pour ne pas fermer toute la gestion), `requestable`, `request`, `trial`. Même règle du mode de la
 * plateforme : en mode « demo », rien n'est refusé. Indicateurs illisibles : la requête passe, la suite de la chaîne répond. Le chemin est
 * lu par `RequestPath::of` (lot CHEMIN), comme toute garde.
 */
class LossPreventionModuleSubscriber implements EventSubscriberInterface
{
    public const CODE = 'MODULE_INACTIVE';
    public const PATH = '#^/api/gestion/pertes(/|$)#';

    public function __construct(private readonly ModuleAccessService $modules, private readonly PlatformModeService $platform, private readonly LoggerInterface $logger,
        private readonly ?ModuleRequestHint $hints = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 14]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // chemin tel que le routeur le lit (décodé) : « /api/gestion/%70ertes » est la même route
        $path = RequestPath::of($event->getRequest());
        if (!$event->isMainRequest() || $event->hasResponse() || !preg_match(self::PATH, $path)) {
            return;
        }
        $module = ModuleAccessService::LOSS_PREVENTION;
        try {
            if ($this->platform->isDemo() || $this->modules->isEnabled($module)) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('LossPreventionModuleSubscriber : indicateurs de modules illisibles, requête laissée passer : ' . $e->getMessage());

            return;
        }
        $this->logger->info(sprintf('Module %s inactif : %s %s refusé', $module, $event->getRequest()->getMethod(), $path));
        $event->setResponse(new JsonResponse([
            'error' => self::CODE,
            'code' => self::CODE,
            'module' => $module,
            'message' => sprintf('Le module %s n\'est pas activé sur ce site.', ModuleAccessService::LABELS[$module]),
            'feature' => ModuleAccessService::FEATURE_KEYS[$module],
        ] + ($this->hints?->for($module) ?? []), 403));
    }
}
