<?php

namespace App\EventSubscriber;

use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosSettingsService;
use App\Services\PosAuditService\PosTerminalService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Garde du terminal de caisse (lot R1, 23/10/2026). Toute requête portant un X-Pos-Terminal dont la clé est révoquée
 * reçoit 403 TERMINAL_REVOKED (le POS revient à l'écran d'activation) ; un en-tête valide met à jour la dernière
 * activité du terminal (au plus toutes les 5 minutes). Si le site l'exige (réglage « exiger le terminal pour la
 * caisse », désactivé par défaut), les routes de caisse sans terminal valide reçoivent 403 TERMINAL_REQUIRED.
 * Ne concerne que l'en-tête X-Pos-Terminal des clés « pos » : LivingMemory, boutique, MAUI ne l'envoient jamais.
 */
class PosTerminalGuardSubscriber implements EventSubscriberInterface
{
    /** Routes de caisse soumises au réglage « terminal exigé » (pin-login et users l'exigent déjà par elles-mêmes) */
    private const POS_ROUTES = '#^/api/(caisse|pos|cash-registers)(/|$)|^/api/order/create-multi-payment$#';

    public function __construct(private readonly PosTerminalService $terminals, private readonly PosSettingsService $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 1]]; // après le pare-feu (8), avant l'idempotence (0)
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $hasHeader = $request->headers->has(PosTerminalService::HEADER);
        $posRoute = (bool) preg_match(self::POS_ROUTES, $request->getPathInfo());
        if (!$hasHeader && !$posRoute) {
            return;
        }
        $state = $hasHeader ? $this->terminals->state() : ['status' => 'none'];
        if ($state['status'] === 'revoked') {
            $event->setResponse(new JsonResponse((new CashRegisterException('TERMINAL_REVOKED', 'Ce terminal a été révoqué : réactivez l\'application avec une nouvelle clé', 403, [], ['terminalId' => $state['id']]))->toBody(), 403));

            return;
        }
        $this->terminals->touchIfStale($state);
        if ($posRoute && $state['status'] !== 'valid' && !preg_match('#^/api/pos/(pin-login|users)$#', $request->getPathInfo()) && $this->settings->terminalRequired()) {
            $event->setResponse(new JsonResponse((new CashRegisterException('TERMINAL_REQUIRED', 'Ce site exige un terminal de caisse identifié (X-Pos-Terminal) : activez l\'application avec une clé', 403))->toBody(), 403));
        }
    }
}
