<?php

namespace App\Support\UseCase;

use App\Entity\User;
use App\Support\Services\SupportSettingsService;
use App\Support\Services\SupportTicketService;

/** Module Assistance, lot 1 (12/11/2026, `docs/assistance.md`) : boîte de demandes et réglages */
class SupportUseCase
{
    public function __construct(private readonly SupportTicketService $tickets, private readonly SupportSettingsService $settings)
    {
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
