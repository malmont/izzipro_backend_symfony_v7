<?php

namespace App\UseCase\SiteFeaturesUseCase;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeatureRequestRepository;
use App\Message\EntitlementChangedMessage;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Demande d'une fonctionnalité de la plateforme par l'administrateur du site (console de pilotage, lots 3 et 5) : partagée par
 * POST /api/site/features/{clé}/request et la page « Mon site » ; courriels d'accusé (site) et d'avis (super administrateur). Exceptions : InvalidArgumentException (clé non plateforme),
 * SiteFeatureRequestException (ALREADY_ENABLED | REQUEST_PENDING).
 */
class RequestSiteFeatureUseCase
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly FeatureRequestRepository $requests, private readonly TenantConnectionManager $tenants, private readonly MessageBusInterface $bus, private readonly LoggerInterface $logger)
    {
    }

    /** @return array<string, mixed> la demande créée */
    public function execute(string $key, ?string $message, ?string $by): array
    {
        if (!isset(FeatureCatalog::GOVERNED[$key])) {
            throw new \InvalidArgumentException('clé inconnue ou non accordée par la plateforme');
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        if ($this->entitlements->isEnabled($code, $key)) {
            throw new SiteFeatureRequestException('ALREADY_ENABLED', 'Fonctionnalité déjà active sur ce site.');
        }
        if (isset($this->requests->pendingFor($code)[$key])) {
            throw new SiteFeatureRequestException('REQUEST_PENDING', 'Une demande est déjà en attente pour cette fonctionnalité.');
        }
        $message = $message !== null ? trim($message) : '';
        $row = $this->requests->insert($code, $key, $by, $message !== '' ? $message : null);
        // courriels (05/11/2026) : accusé au contact du site, avis au super administrateur de la plateforme
        foreach (['request_received', 'request_new'] as $event) {
            try {
                $this->bus->dispatch(new EntitlementChangedMessage($code, $key, $event, (int) $row['id'], ['by' => $by, 'message' => $message !== '' ? $message : null]));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Entitlements] courriel %s de la demande n° %d impossible : %s', $event, (int) $row['id'], $e->getMessage()));
            }
        }

        return $row;
    }
}
