<?php

namespace App\UseCase\PosFavoriteUseCase;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosFavoriteService\PosFavoriteService;

/** Grille de favoris de la caisse (F10) : poste facultatif (sans poste : épinglés du site), orchestration pour /api/pos/favorites */
class PosFavoriteUseCase
{
    public function __construct(private readonly PosFavoriteService $favorites, private readonly CashRegisterResolver $registers)
    {
    }

    /** @return array{body: array, etag: string} */
    public function grid(mixed $cashRegisterId, string $productsBaseUrl): array
    {
        return $this->favorites->grid($this->register($cashRegisterId), $productsBaseUrl);
    }

    public function pins(User $user, mixed $cashRegisterId): array
    {
        return $this->favorites->pins($user, $this->register($cashRegisterId));
    }

    /** {cashRegisterId?: int|null, pins: [{productId, variantId?}]} */
    public function replacePins(User $user, array $body): array
    {
        return $this->favorites->replacePins($user, $this->register($body['cashRegisterId'] ?? null), $body['pins'] ?? null);
    }

    private function register(mixed $cashRegisterId): ?CashRegister
    {
        return is_numeric($cashRegisterId) ? $this->registers->resolve((int) $cashRegisterId) : null;
    }
}
