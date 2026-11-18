<?php

namespace App\Incident\UseCase;

use App\Entity\User;
use App\Incident\Services\PlatformBugReportService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Signalements de la plateforme (lot INC-3, 18/11/2026) : passage du contrôleur au service, sans règle propre */
class PlatformBugReportUseCase
{
    public function __construct(private readonly PlatformBugReportService $reports)
    {
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields, ?UploadedFile $screenshot, User $user): array
    {
        return $this->reports->create($fields, $screenshot, $user);
    }

    public function mine(User $user): array
    {
        return $this->reports->mine($user);
    }

    /** @param array{status?: ?string, tenant?: ?string, app?: ?string} $filters */
    public function list(array $filters, int $page, int $limit): array
    {
        return $this->reports->list($filters, $page, $limit);
    }

    public function get(int $id): array
    {
        return $this->reports->get($id);
    }

    /** @param array<string, mixed> $body */
    public function update(int $id, array $body, string $by): array
    {
        return $this->reports->update($id, $body, $by);
    }

    public function screenshotFile(int $id): string
    {
        return $this->reports->screenshotFile($id);
    }

    public function screenshotDays(): int
    {
        return $this->reports->screenshotDays();
    }

    public function setScreenshotDays(mixed $days, ?string $by): void
    {
        $this->reports->setScreenshotDays($days, $by);
    }
}
