<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossShipmentService;

/** Les boîtiers vendus et expédiés (lot L6-b, `docs/prevention-pertes.md` § 19.5) : la plateforme réserve et annule, le site confirme */
class LossShipmentUseCase
{
    public function __construct(private readonly LossShipmentService $shipments)
    {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> l'aperçu (sans `confirm: true`) ou { shipment } */
    public function reserveOrPreview(array $body, string $by): array
    {
        return ($body['confirm'] ?? null) === true ? $this->shipments->reserve($body, $by) : ['preview' => $this->shipments->preview($body)];
    }

    public function codeFingerprint(mixed $code): ?string
    {
        return $this->shipments->codeFingerprint($code);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->shipments->all();
    }

    /** @param array<string, mixed> $body @return array{codeSentTo: int} lot L6-c */
    public function resendCode(int $id, array $body, string $by): array
    {
        return $this->shipments->resendCode($id, $body, $by);
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, string $by): array
    {
        return $this->shipments->cancel($id, $by);
    }

    /** @return array<string, mixed> */
    public function pending(string $locale, string $ip): array
    {
        return $this->shipments->pending($locale, $ip);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function confirm(int $id, array $body, string $locale, int $userId, string $ip, bool $platformAccount = false): array
    {
        return $this->shipments->confirm($id, $body, $locale, $userId, $ip, $platformAccount);
    }
}
