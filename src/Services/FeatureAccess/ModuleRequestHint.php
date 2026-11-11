<?php

namespace App\Services\FeatureAccess;

use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeaturePolicyRepository;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;

/**
 * Indications jointes au 403 MODULE_INACTIVE (lot MOD, 11/11/2026, demande de l'application de gestion) : la clé du registre à demander,
 * si l'écran peut proposer « Demander » (pas de demande déjà en attente), l'état de la demande en cours et l'essai encore possible (un seul
 * essai par fonctionnalité et par site, politique de la plateforme) : l'écran n'affiche jamais « Demander » deux fois. Même règle que
 * `RequestSiteFeatureUseCase::trialOffer` ; illisible (base maître indisponible) : seule la clé est rendue.
 */
class ModuleRequestHint
{
    public function __construct(
        private readonly FeatureRequestRepository $requests,
        private readonly FeaturePolicyRepository $policies,
        private readonly EntitlementService $entitlements,
        private readonly TenantConnectionManager $tenants,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{feature?: string, requestable?: bool, request?: array{status: string}|null, trial?: array{allowed: bool, days: int}} */
    public function for(string $module): array
    {
        $key = ModuleAccessService::FEATURE_KEYS[$module] ?? null;
        if ($key === null) {
            return [];
        }
        try {
            $code = (string) $this->tenants->getCurrentTenantCode();
            $pending = $this->requests->pendingFor($code)[$key] ?? null;
            $policy = $this->policies->get($key);
            $trialUsed = false;
            foreach ($this->entitlements->listForTenant($code) as $row) {
                $trialUsed = $trialUsed || ($row['feature_key'] === $key && $row['source'] === 'trial');
            }
            $trialAllowed = (bool) $policy['trial_allowed'] && !$trialUsed;

            return ['feature' => $key, 'requestable' => $pending === null, 'request' => $pending === null ? null : ['status' => (string) $pending],
                'trial' => ['allowed' => $trialAllowed, 'days' => $trialAllowed ? (int) $policy['trial_days'] : 0]];
        } catch (\Throwable $e) {
            $this->logger->warning('ModuleRequestHint : état de la demande illisible : ' . $e->getMessage());

            return ['feature' => $key];
        }
    }
}
