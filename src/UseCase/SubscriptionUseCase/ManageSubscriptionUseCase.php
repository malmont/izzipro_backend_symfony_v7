<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Dto\SubscriptionOutputDto;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\SubscriptionService\SubscriptionService;

/**
 * Routes du client connecté : POST /api/subscriptions (souscription), GET (liste, détail), POST …/{id}/cancel, resume,
 * pause, change-plan, POST /api/subscriptions/portal-session. Un abonnement d'un autre client : 404.
 */
class ManageSubscriptionUseCase
{
    public function __construct(private readonly SubscriptionService $service, private readonly SubscriptionMailer $mailer)
    {
    }

    /**
     * @param array<string, mixed> $body { planId, quantity?, addressId?, carrierId? }
     * @return array{subscriptionId: int, clientSecret: ?string, status: string, reused: bool, subscription: SubscriptionOutputDto}
     * @throws SubscriptionException
     */
    public function subscribe(User $user, array $body, string $locale): array
    {
        $planId = $body['planId'] ?? null;
        if (!is_numeric($planId)) {
            throw new SubscriptionException(422, 'planId : identifiant de formule attendu', [['path' => 'planId', 'message' => 'identifiant attendu']]);
        }
        $quantity = $body['quantity'] ?? 1;
        if (!is_numeric($quantity) || (int) $quantity != $quantity) {
            throw new SubscriptionException(422, 'quantity : entier attendu', [['path' => 'quantity', 'message' => 'entier attendu']]);
        }
        $result = $this->service->subscribe($user, (int) $planId, (int) $quantity, self::id($body['addressId'] ?? null), self::id($body['carrierId'] ?? null));

        return [
            'subscriptionId' => (int) $result['subscription']->getId(), 'clientSecret' => $result['clientSecret'], 'status' => $result['subscription']->getStatus(),
            'reused' => $result['reused'] ?? false,
            'subscription' => $this->service->toDto($result['subscription'], $locale),
        ];
    }

    /** @return list<SubscriptionOutputDto> */
    public function list(User $user, string $locale): array
    {
        return $this->service->listFor($user, $locale);
    }

    public function get(int $id, User $user, string $locale): SubscriptionOutputDto
    {
        return $this->service->toDto($this->service->ownedBy($id, $user), $locale);
    }

    public function cancel(int $id, User $user, bool $atPeriodEnd, string $locale, string $host): SubscriptionOutputDto
    {
        $subscription = $this->service->cancel($this->service->ownedBy($id, $user), $atPeriodEnd);
        $this->mailer->notify($subscription, $atPeriodEnd ? 'cancel_scheduled' : 'canceled', $locale, $host);

        return $this->service->toDto($subscription, $locale);
    }

    public function resume(int $id, User $user, string $locale): SubscriptionOutputDto
    {
        return $this->service->toDto($this->service->resume($this->service->ownedBy($id, $user)), $locale);
    }

    public function pause(int $id, User $user, string $locale, string $host): SubscriptionOutputDto
    {
        $subscription = $this->service->pause($this->service->ownedBy($id, $user));
        $this->mailer->notify($subscription, 'paused', $locale, $host);

        return $this->service->toDto($subscription, $locale);
    }

    public function changePlan(int $id, User $user, mixed $planId, string $locale): SubscriptionOutputDto
    {
        if (!is_numeric($planId)) {
            throw new SubscriptionException(422, 'planId : identifiant de formule attendu', [['path' => 'planId', 'message' => 'identifiant attendu']]);
        }

        return $this->service->toDto($this->service->changePlan($this->service->ownedBy($id, $user), (int) $planId), $locale);
    }

    public function portalUrl(User $user, ?string $returnUrl, string $fallbackUrl): string
    {
        $url = is_string($returnUrl) && preg_match('#^https://[^\s<>"\']+$#', $returnUrl) ? $returnUrl : $fallbackUrl;

        return $this->service->portalUrl($user, $url);
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
