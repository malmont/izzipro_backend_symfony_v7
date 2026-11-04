<?php

namespace App\UseCase\SiteFeaturesUseCase;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\TenantConnectionManager;

/**
 * Demande d'une fonctionnalité de la plateforme par l'administrateur du site (console de pilotage, lots 3 et 5) : partagée par
 * POST /api/site/features/{clé}/request et la page « Mon site ». Exceptions : InvalidArgumentException (clé non plateforme),
 * SiteFeatureRequestException (ALREADY_ENABLED | REQUEST_PENDING).
 */
class RequestSiteFeatureUseCase
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly FeatureRequestRepository $requests, private readonly TenantConnectionManager $tenants)
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

        return $this->requests->insert($code, $key, $by, $message !== '' ? $message : null);
    }
}
