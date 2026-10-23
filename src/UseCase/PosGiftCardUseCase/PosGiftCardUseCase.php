<?php

namespace App\UseCase\PosGiftCardUseCase;

use App\Entity\PosGiftCard;
use App\Entity\User;
use App\Services\PosAuditService\PosWriteResponseBuilder;
use App\Services\PosGiftCardService\PosGiftCardService;
use App\Services\TenantEntityManagerProvider;

/** Cartes-cadeaux et avoirs (F9) : une transaction SQL par opération d'écriture (validation C3 consommée dedans), réponses de l'API */
class PosGiftCardUseCase
{
    public function __construct(private readonly PosGiftCardService $cards, private readonly PosWriteResponseBuilder $responses, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function lookup(User $user, mixed $code): array
    {
        return $this->cards->lookup($code, $user);
    }

    /** @return array{body: array, card: PosGiftCard} */
    public function issue(User $user, array $body, ?string $posKey, string $locale): array
    {
        return $this->transaction(function () use ($user, $body, $posKey, $locale) {
            $r = $this->cards->issue($user, $body, $posKey);

            return ['card' => $r['card'], 'body' => ['giftCard' => $this->cards->toArray($r['card'], $r['code']), 'orderId' => $r['order']->getId()] + $this->responses->receiptBlock($r['order'], $locale, $r['receipt'])];
        });
    }

    public function cancel(User $user, mixed $code, array $body, ?string $posKey, string $locale): array
    {
        return $this->transaction(function () use ($user, $code, $body, $posKey, $locale) {
            $r = $this->cards->cancel($user, $code, $body, $posKey);

            return ['card' => $r['card'], 'body' => ['giftCard' => $this->cards->toArray($r['card']), 'orderId' => $r['order']->getId(), 'refunds' => $r['refunds'], 'cash' => $r['cash']] + $this->responses->receiptBlock($r['order'], $locale, $r['creditNote'])];
        });
    }

    public function adjust(User $user, mixed $code, array $body): array
    {
        return $this->transaction(fn () => ['giftCard' => $this->cards->toArray($this->cards->adjust($user, $code, $body))]);
    }

    public function cashOut(User $user, mixed $code, array $body): array
    {
        return $this->transaction(function () use ($user, $code, $body) {
            $r = $this->cards->cashOut($user, $code, $body);

            return ['giftCard' => $this->cards->toArray($r['card']), 'amount' => $r['amount'], 'cash' => $r['cash'], 'cashRounding' => $r['cashRounding']];
        });
    }

    public function replace(User $user, array $body): array
    {
        return $this->transaction(function () use ($user, $body) {
            $r = $this->cards->replace($user, $body);

            return ['card' => $r['card'], 'body' => ['giftCard' => $this->cards->toArray($r['card'], $r['code']), 'replaced' => $this->cards->toArray($r['old'])]];
        });
    }

    /** Réponse reconstituée pour un rejeu idempotent (code en clair indisponible : empreinte seule en base) */
    public function rebuild(int $cardId): ?array
    {
        $card = $this->emProvider->getEntityManager()->find(PosGiftCard::class, $cardId);

        return $card ? ['giftCard' => $this->cards->toArray($card), 'replayed' => true] : null;
    }

    /** @template T @param callable(): T $work @return T */
    private function transaction(callable $work): mixed
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $own = !$connection->isTransactionActive();
        if ($own) {
            $connection->beginTransaction();
        }
        try {
            $result = $work();
            if ($own) {
                $connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($own && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }
}
