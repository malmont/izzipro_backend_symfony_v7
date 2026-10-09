<?php

namespace App\Tests\Fake;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionStripeGatewayInterface;
use App\Services\TenantEntityManagerProvider;

/** Stripe Billing simulé : abonnements en mémoire, aucun appel réseau ; $calls garde les opérations pour les assertions */
final class FakeSubscriptionStripeGateway implements SubscriptionStripeGatewayInterface
{
    /** @var array<string, array> */
    public static array $subscriptions = [];
    /** @var list<array> */
    public static array $calls = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public static function reset(): void
    {
        self::$subscriptions = [];
        self::$calls = [];
    }

    public function ensureCustomer(User $user, ?Adress $address): string
    {
        if (!$user->getStripeCustomerId()) {
            $user->setStripeCustomerId('cus_test_' . $user->getId());
            $this->emProvider->getEntityManager()->flush();
        }

        return $user->getStripeCustomerId();
    }

    public function ensurePrice(SubscriptionPlan $plan): array
    {
        if (!$plan->getStripePriceId()) {
            $plan->setStripeProductId('prod_test_' . $plan->getId())->setStripePriceId('price_test_' . $plan->getId());
            $this->emProvider->getEntityManager()->flush();
        }

        return [$plan->getStripeProductId(), $plan->getStripePriceId()];
    }

    public function taxRateIds(array $taxes): array
    {
        self::$calls[] = ['taxRateIds', $taxes];

        return array_map(fn ($t) => 'txr_test_' . preg_replace('/\W/', '', $t['label']), $taxes);
    }

    public function ensureShippingPrice(Carrier $carrier, int $amount, string $currency, string $interval, int $intervalCount): string
    {
        self::$calls[] = ['ensureShippingPrice', $carrier->getId(), $amount, $currency, $interval, $intervalCount];

        return sprintf('price_test_shipping_%d_%d', $carrier->getId(), $amount);
    }

    public function create(string $customerId, string $priceId, int $quantity, int $trialDays, array $metadata, array $taxRateIds, bool $automaticTax, ?string $shippingPriceId = null): array
    {
        $id = 'sub_test_' . bin2hex(random_bytes(4)); // unique d'un test à l'autre (la base de test persiste)
        self::$subscriptions[$id] = [
            'id' => $id, 'status' => $trialDays > 0 ? 'trialing' : 'incomplete', 'currentPeriodEnd' => (new \DateTimeImmutable('+1 month'))->getTimestamp(),
            'cancelAtPeriodEnd' => false, 'paused' => false, 'clientSecret' => 'pi_test_secret_' . $id, 'itemId' => "si_test_{$id}", 'canceledAt' => null,
            'priceId' => $priceId, 'quantity' => $quantity, 'customerId' => $customerId, 'metadata' => $metadata, 'taxRateIds' => $taxRateIds, 'automaticTax' => $automaticTax, 'shippingPriceId' => $shippingPriceId,
        ];
        self::$calls[] = ['create', $id, $priceId, $quantity, $trialDays, $metadata, $taxRateIds, $automaticTax, $shippingPriceId];

        return self::$subscriptions[$id];
    }

    public function retrieve(string $subscriptionId): array
    {
        return self::$subscriptions[$subscriptionId];
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd): array
    {
        self::$calls[] = ['cancel', $subscriptionId, $atPeriodEnd];
        if ($atPeriodEnd) {
            self::$subscriptions[$subscriptionId]['cancelAtPeriodEnd'] = true;
        } else {
            self::$subscriptions[$subscriptionId]['status'] = 'canceled';
            self::$subscriptions[$subscriptionId]['canceledAt'] = time();
        }

        return self::$subscriptions[$subscriptionId];
    }

    public function resume(string $subscriptionId): array
    {
        self::$calls[] = ['resume', $subscriptionId];
        self::$subscriptions[$subscriptionId]['cancelAtPeriodEnd'] = false;
        self::$subscriptions[$subscriptionId]['paused'] = false;
        self::$subscriptions[$subscriptionId]['status'] = 'active';

        return self::$subscriptions[$subscriptionId];
    }

    public function pause(string $subscriptionId): array
    {
        self::$calls[] = ['pause', $subscriptionId];
        self::$subscriptions[$subscriptionId]['paused'] = true;

        return self::$subscriptions[$subscriptionId];
    }

    public function changePlan(string $subscriptionId, string $itemId, string $priceId, int $quantity): array
    {
        self::$calls[] = ['changePlan', $subscriptionId, $itemId, $priceId, $quantity];
        self::$subscriptions[$subscriptionId]['priceId'] = $priceId;
        self::$subscriptions[$subscriptionId]['quantity'] = $quantity;

        return self::$subscriptions[$subscriptionId];
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        self::$calls[] = ['portal', $customerId, $returnUrl];

        return 'https://billing.stripe.test/session/' . $customerId;
    }
}
