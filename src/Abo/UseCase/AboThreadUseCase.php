<?php

namespace App\Abo\UseCase;

use App\Abo\Services\AboException;
use App\Abo\Services\AboThreadService;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\TenantEntityManagerProvider;

/**
 * Le fil de l'abonné (lot ABO-3b, 20/11/2026) : l'ÉQUIPE (`ROLE_ADMIN`, module Boutique) lit et répond, voit les fils non lus ; l'ABONNÉ
 * (boutique web et commerce mobile) lit et écrit dans le fil de SON abonnement (404 sinon).
 */
class AboThreadUseCase
{
    public function __construct(private readonly AboThreadService $thread, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{messages: list<array<string, mixed>>} */
    public function teamThread(int $id, mixed $before): array
    {
        return $this->thread->forTeam($this->subscription($id), $before);
    }

    /** @param array<string, mixed> $body @return array{message: array<string, mixed>, emailed: bool} */
    public function teamPost(int $id, array $body, ?User $by, string $host, string $locale): array
    {
        return $this->thread->postByTeam($this->subscription($id), $body['body'] ?? null, $by, $host, $locale);
    }

    /** @return array{threads: list<array<string, mixed>>} */
    public function unread(string $locale): array
    {
        return ['threads' => $this->thread->unreadThreads(true, $locale)];
    }

    /** @return array{messages: list<array<string, mixed>>, unread: int} @throws SubscriptionException */
    public function clientThread(User $user, int $id, mixed $before): array
    {
        return $this->clientRefusal(fn () => $this->thread->forCustomer($this->owned($user, $id), $before));
    }

    /** @param array<string, mixed> $body @return array{message: array<string, mixed>} @throws SubscriptionException */
    public function clientPost(User $user, int $id, array $body, string $host, string $locale): array
    {
        return $this->clientRefusal(fn () => $this->thread->postByCustomer($this->owned($user, $id), $user, $body['body'] ?? null, $host, $locale));
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    private function subscription(int $id): Subscription
    {
        return $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($id) ?? throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
    }

    /** Un abonnement d'un autre client : 404, comme toutes les routes de l'abonné */
    private function owned(User $user, int $id): Subscription
    {
        $s = $this->subscription($id);
        if ($s->getUser()?->getId() !== $user->getId()) {
            throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
        }

        return $s;
    }

    /** Côté abonné : le refus a la forme des autres routes `/api/subscriptions` @throws SubscriptionException */
    private function clientRefusal(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AboException $e) {
            throw new SubscriptionException($e->status, $e->getMessage(), $e->field !== null ? [SubscriptionException::error($e->field, $e->errorCode, $e->getMessage())] : [], $e->details, $e->errorCode, $e);
        }
    }
}
