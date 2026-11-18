<?php

namespace App\Incident\UseCase;

use App\Incident\Services\PlatformIncidentService;

/** Incidents de la plateforme (lot INC-1a, 18/11/2026) : passage du contrôleur au service, sans règle propre */
class PlatformIncidentUseCase
{
    public function __construct(private readonly PlatformIncidentService $incidents)
    {
    }

    /** @param array<string, mixed> $body */
    public function report(array $body, ?int $userId, string $ip, ?string $requestId): array
    {
        return $this->incidents->report($body, $userId, $ip, $requestId);
    }

    /** @param array{status?: ?string, app?: ?string, tenant?: ?string, level?: ?string} $filters */
    public function list(array $filters, int $page, int $limit): array
    {
        return $this->incidents->list($filters, $page, $limit);
    }

    public function get(int $id): array
    {
        return $this->incidents->get($id);
    }

    /** @param array<string, mixed> $body */
    public function update(int $id, array $body, string $by): array
    {
        return $this->incidents->update($id, $body, $by);
    }
}
