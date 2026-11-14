<?php

namespace App\Support\UseCase;

use App\Entity\User;
use App\Support\Services\SupportAgentService;
use App\Support\Services\SupportDirectoryService;
use App\Support\Services\SupportOrderSummary;
use App\Support\Services\SupportSettingsService;
use App\Support\Services\SupportTicketService;

/** Module Assistance, lot 1 (12/11/2026, `docs/assistance.md`) : boîte de demandes et réglages */
class SupportUseCase
{
    public function __construct(private readonly SupportTicketService $tickets, private readonly SupportSettingsService $settings,
        private readonly SupportDirectoryService $directory, private readonly SupportOrderSummary $orders, private readonly SupportAgentService $agentsAdmin)
    {
    }

    // Lot SUPPORT-WEB : l'administrateur du site invite un agent, relance son invitation, lui retire l'accès
    public function agentsForAdmin(User $user): array { return $this->directory->agents($user) + ['pending' => $this->agentsAdmin->pending()]; }
    public function inviteAgent(array $body, User $by): array { return $this->agentsAdmin->invite($body, $by); }
    public function resendInvitation(int $id, User $by, ?string $locale): array { return $this->agentsAdmin->resend($id, $by, $locale); }
    public function revokeAgent(int $id): array { return $this->agentsAdmin->revoke($id); }

    // Lot SUPPORT-WEB (14/11/2026) : le compte connecté et l'état du module, les agents, une commande par sa référence
    public function me(User $user): array { return $this->directory->me($user); }
    public function agents(User $user): array { return $this->directory->agents($user); }

    /** Le résumé d'une commande (liste blanche de la fiche d'une demande) par sa référence exacte ; 404 ou 409 sinon */
    public function orderByReference(string $reference): array
    {
        $id = $this->orders->idByReference($reference);

        return ['order' => $this->orders->brief($id), 'orderSummary' => $this->orders->summary($id)];
    }

    public function list(array $query, User $agent): array { return $this->tickets->list($query, (int) $agent->getId()); }
    public function create(array $body, User $agent): array { return $this->tickets->create($body, $agent); }
    public function detail(int $id): array { return $this->tickets->detail($id); }
    public function patch(int $id, array $body, User $agent): array { return $this->tickets->patch($id, $body, $agent); }
    public function message(int $id, array $body, array $files, User $agent, string $host): array { return $this->tickets->addMessage($id, $body, $files, $agent, $host); }
    public function attachment(int $id, int $attachmentId): array { return $this->tickets->attachment($id, $attachmentId); }
    public function settings(): array { return $this->settings->toArray(); }
    public function saveSettings(array $body, ?string $user): array { return $this->settings->save($body, $user); }
}
