<?php

namespace App\UseCase\SiteFeaturesUseCase;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeaturePolicyRepository;
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
    public function __construct(private readonly EntitlementService $entitlements, private readonly FeatureRequestRepository $requests, private readonly FeaturePolicyRepository $policies, private readonly TenantConnectionManager $tenants, private readonly MessageBusInterface $bus, private readonly LoggerInterface $logger)
    {
    }

    /**
     * Essai gratuit possible (politique de la plateforme : essai autorisé, et le site n'en a jamais eu pour cette fonctionnalité)
     * @return array{allowed: bool, days: int}
     */
    public function trialOffer(string $key, ?string $tenantCode = null): array
    {
        $policy = $this->policies->get($key);
        if (!$policy['trial_allowed']) {
            return ['allowed' => false, 'days' => 0];
        }
        $code = $tenantCode ?? (string) $this->tenants->getCurrentTenantCode();
        foreach ($this->entitlements->listForTenant($code) as $row) {
            if ($row['feature_key'] === $key && $row['source'] === 'trial') {
                return ['allowed' => false, 'days' => 0]; // un seul essai par fonctionnalité et par site
            }
        }

        return ['allowed' => true, 'days' => (int) $policy['trial_days']];
    }

    /**
     * « Demander » (règle du 05/11/2026) : si un essai gratuit est possible, il s'ouvre aussitôt (droit `trial`, courriel) ; sinon une
     * demande part à la plateforme.
     * @return array{kind: 'trial'|'request', entitlement?: array<string, mixed>, request?: array<string, mixed>}
     */
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
        $offer = $this->trialOffer($key, $code);
        if ($offer['allowed']) {
            $entitlement = $this->entitlements->trial($code, $key, null, $by, 'essai demandé depuis Mon site' . ($message !== '' ? ' : ' . $message : ''));

            return ['kind' => 'trial', 'entitlement' => $entitlement];
        }
        $row = $this->requests->insert($code, $key, $by, $message !== '' ? $message : null);
        // courriels (05/11/2026) : accusé au contact du site, avis au super administrateur de la plateforme
        foreach (['request_received', 'request_new'] as $event) {
            try {
                $this->bus->dispatch(new EntitlementChangedMessage($code, $key, $event, (int) $row['id'], ['by' => $by, 'message' => $message !== '' ? $message : null]));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Entitlements] courriel %s de la demande n° %d impossible : %s', $event, (int) $row['id'], $e->getMessage()));
            }
        }

        return ['kind' => 'request', 'request' => $row];
    }
}
