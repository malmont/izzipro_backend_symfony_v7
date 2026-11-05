<?php

namespace App\UseCase\SiteAssistantUseCase;

use App\Services\Platform\CustomDomainService;
use App\Services\SiteAssistantService\SiteAssistantService;
use App\Services\TenantConnectionManager;
use App\UseCase\SiteFeaturesUseCase\RequestSiteFeatureUseCase;
use App\UseCase\SiteFeaturesUseCase\UpdateSiteFeaturesUseCase;

/**
 * POST /api/site/assistant/actions/{id}/confirm : applique une action proposée par l'assistant, par les mêmes cas d'usage que la
 * page Mon site et PUT /api/site/features (validation, rôles, journal). L'action est oubliée après exécution (ou refus).
 */
class ConfirmSiteAssistantActionUseCase
{
    public function __construct(private readonly SiteAssistantService $assistant, private readonly UpdateSiteFeaturesUseCase $update, private readonly RequestSiteFeatureUseCase $request, private readonly CustomDomainService $domains, private readonly TenantConnectionManager $tenants)
    {
    }

    /**
     * @return array{tool: string, input: array<string, mixed>, result: array<string, mixed>}
     * @throws \OutOfBoundsException action inconnue ou expirée
     */
    public function execute(string $id, string $host, ?string $user): array
    {
        $tenant = (string) $this->tenants->getCurrentTenantCode();
        $action = $this->assistant->pendingAction($tenant, $id);
        if ($action === null) {
            throw new \OutOfBoundsException('Action inconnue ou expirée : demandez à nouveau à l\'assistant.');
        }
        $result = match ($action['tool']) {
            'set_site_settings' => $this->settings($action['input']['values'] ?? [], $host),
            'request_feature' => $this->request->execute((string) ($action['input']['feature'] ?? ''), $action['input']['message'] ?? null, $user),
            'set_custom_domain' => ['domain' => $this->domains->set($tenant, (string) ($action['input']['domain'] ?? ''), $user)],
            default => throw new \OutOfBoundsException('Outil inconnu'),
        };
        $this->assistant->forgetAction($tenant, $id);

        return ['tool' => $action['tool'], 'input' => $action['input'], 'result' => $result];
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values, string $host): array
    {
        $this->update->execute($values, $host, 'fr');

        return ['applied' => array_keys($values)];
    }
}
