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
    public function __construct(private readonly SiteAssistantService $assistant, private readonly UpdateSiteFeaturesUseCase $update, private readonly RequestSiteFeatureUseCase $request, private readonly CustomDomainService $domains, private readonly TenantConnectionManager $tenants, private readonly ?\App\Services\ShippingService\ProductDimensionsService $dimensions = null,
        // opérations sur les commandes (console IA, lot 6, 07/11/2026)
        private readonly ?\App\Services\OrderService\OrderOperationsService $operations = null, private readonly ?\App\Services\OrderService\OrderShipmentService $shipments = null, private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null,
        private readonly ?\App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase $entreprise = null) // fiche Entreprise (cheminement complet, 07/11/2026)
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
            // poids estimés par l'IA (lot 1 bis, 07/11/2026) : mêmes règles que la grille « Poids et dimensions »
            'apply_product_dimensions' => $this->dimensions?->save(is_array($action['input']['rows'] ?? null) ? $action['input']['rows'] : [], 'assistant') ?? throw new \OutOfBoundsException('Outil indisponible'),
            // opérations de support (lot 6) : mêmes services et mêmes refus que les pages de la commande ; un refus devient 422
            'refund_order', 'cancel_order', 'capture_order', 'resend_order_email', 'ship_order' => $this->operation($action['tool'], $action['input'], $host, $user),
            'set_entreprise_fields' => $this->entrepriseFields(is_array($action['input']['fields'] ?? null) ? $action['input']['fields'] : [], $host),
            default => throw new \OutOfBoundsException('Outil inconnu'),
        };
        $this->assistant->forgetAction($tenant, $id);

        return ['tool' => $action['tool'], 'input' => $action['input'], 'result' => $result];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function operation(string $tool, array $input, string $host, ?string $user): array
    {
        if ($this->operations === null || $this->shipments === null || $this->emProvider === null) {
            throw new \OutOfBoundsException('Outil indisponible');
        }
        $order = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Order::class)->find((int) ($input['orderId'] ?? 0));
        if ($order === null) {
            throw new \InvalidArgumentException('Commande introuvable.');
        }
        $domain = preg_replace('#^https?://#', '', $host) ?: $host;
        $result = match ($tool) {
            'refund_order' => $this->operations->refund($order, (int) ($input['amountCents'] ?? 0), (string) ($input['reason'] ?? ''), (bool) ($input['notify'] ?? true), $user, $domain),
            'cancel_order' => $this->operations->cancel($order, (string) ($input['reason'] ?? ''), (bool) ($input['notify'] ?? true), $user, $domain),
            'capture_order' => $this->operations->capture($order, $user),
            'resend_order_email' => $this->operations->resend($order, (string) ($input['kind'] ?? ''), $domain),
            'ship_order' => $this->shipments->shipBlocker($order) === null
                ? ['status' => 200, 'body' => $this->shipments->ship($order, (string) ($input['carrier'] ?? ''), $input['trackingNumber'] ?? null, $input['trackingUrl'] ?? null, (bool) ($input['notify'] ?? true), $domain)]
                : ['status' => 409, 'body' => ['message' => $this->shipments->shipBlocker($order)]],
        };
        if (($result['status'] ?? 500) >= 400) {
            throw new \InvalidArgumentException((string) ($result['body']['message'] ?? 'Opération refusée.'));
        }

        return $result['body'] + ['orderId' => (int) $order->getId(), 'reference' => (string) $order->getReference()];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function entrepriseFields(array $fields, string $host): array
    {
        if ($this->entreprise === null || $this->emProvider === null) {
            throw new \OutOfBoundsException('Outil indisponible');
        }
        $fields = array_intersect_key($fields, array_flip(\App\Services\SiteAssistantService\SiteAssistantTools::ENTREPRISE_FIELDS));
        $id = (int) ($this->emProvider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([], ['id' => 'ASC'])?->getId() ?? 0);
        if ($id === 0 || $fields === []) {
            throw new \InvalidArgumentException('Fiche Entreprise introuvable ou aucun champ.');
        }
        $dto = $this->entreprise->execute($id, $fields, $host, 'fr');
        if ($dto === null) {
            throw new \InvalidArgumentException('Fiche Entreprise introuvable.');
        }

        return ['applied' => array_keys($fields), 'entrepriseId' => $id];
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values, string $host): array
    {
        $this->update->execute($values, $host, 'fr');

        return ['applied' => array_keys($values)];
    }
}
