<?php

namespace App\UseCase\PosCustomerUseCase;

use App\Entity\PosCustomer;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosCustomerService\PosCustomerService;
use App\Services\PosCustomerService\PosLoyaltyService;
use App\Services\TenantEntityManagerProvider;

/** Fiche client et fidélité (F8) : une transaction SQL par écriture (validation C3 consommée dedans), réponses de l'API */
class PosCustomerUseCase
{
    public function __construct(private readonly PosCustomerService $customers, private readonly PosLoyaltyService $loyalty, private readonly CashRegisterResolver $registers, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function search(User $actor, mixed $q): array
    {
        return $this->customers->search($q, $actor);
    }

    public function create(User $actor, array $body): array
    {
        return $this->transaction(fn () => $this->customers->toArray($this->customers->create($actor, $body), $actor));
    }

    public function get(User $actor, mixed $id): array
    {
        $customer = $this->customers->find($id, true);
        $this->customers->viewed($actor, $customer);

        return $this->customers->toArray($customer, $actor);
    }

    public function history(User $actor, mixed $id): array
    {
        return $this->customers->history($actor, $this->customers->find($id, true));
    }

    public function update(User $actor, mixed $id, array $body): array
    {
        return $this->transaction(fn () => $this->customers->toArray($this->customers->update($actor, $this->customers->find($id), $body), $actor));
    }

    public function link(User $actor, mixed $id, array $body): array
    {
        return $this->transaction(fn () => $this->customers->toArray($this->customers->link($actor, $this->customers->find($id), $body['userId'] ?? null), $actor));
    }

    public function merge(User $actor, mixed $id, array $body): array
    {
        return $this->transaction(fn () => $this->customers->toArray($this->customers->merge($actor, $this->customers->find($id), $body['intoId'] ?? null), $actor));
    }

    public function erase(User $actor, mixed $id): array
    {
        return $this->transaction(fn () => $this->customers->toArray($this->customers->erase($actor, $this->customers->find($id)), $actor));
    }

    public function export(User $actor, mixed $id): array
    {
        return $this->customers->export($actor, $this->customers->find($id, true));
    }

    public function adjustLoyalty(User $actor, mixed $id, array $body): array
    {
        return $this->transaction(function () use ($actor, $id, $body) {
            $customer = $this->customers->find($id);
            $points = is_numeric($body['points'] ?? null) && (int) $body['points'] == $body['points'] ? (int) $body['points'] : 0;
            $reason = isset($body['reason']) && is_string($body['reason']) && trim($body['reason']) !== '' ? trim($body['reason']) : null;
            if ($reason === null) {
                throw new \App\Services\CaisseService\CashRegisterException('VALIDATION_FAILED', 'reason : motif requis', 422, [['path' => 'reason', 'message' => 'requis']]);
            }
            $caisse = null;
            try {
                $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, 409);
            } catch (\App\Services\CaisseService\CashRegisterException) {
                // ajustement possible hors session ouverte : non rattaché à une session
            }
            $tx = $this->loyalty->adjust($customer, $points, $reason, $actor, $caisse);

            return ['customer' => $this->customers->toArray($customer, $actor), 'transaction' => ['id' => $tx->getId(), 'points' => $tx->getPoints(), 'balanceAfter' => $tx->getBalanceAfter(), 'reason' => $tx->getReason()]];
        });
    }

    public function rebuild(int $customerId, User $actor): ?array
    {
        $customer = $this->emProvider->getEntityManager()->find(PosCustomer::class, $customerId);

        return $customer ? $this->customers->toArray($customer, $actor) + ['replayed' => true] : null;
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
