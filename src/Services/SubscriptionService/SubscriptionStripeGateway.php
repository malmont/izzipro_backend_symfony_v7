<?php

namespace App\Services\SubscriptionService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StripeConfig;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\TenantConnectionProvider;
use App\Services\StripeService\StripeConnectSetupService;
use App\Services\TenantEntityManagerProvider;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Stripe Billing sur le compte connecté du site (StripeConfig actif ; sinon le compte de la plateforme). Les
 * identifiants Stripe créés (client, produit, prix, taux de taxe) sont gardés en base ou en cache pour ne pas être
 * recréés. Toute erreur de l'API remonte en SubscriptionException 502.
 */
final class SubscriptionStripeGateway implements SubscriptionStripeGatewayInterface
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly CacheInterface $cache,
        private readonly StripeConnectSetupService $setup,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $stripeSecretKey
    ) {
    }

    public function ensureCustomer(User $user, ?Adress $address): string
    {
        if ($user->getStripeCustomerId()) {
            return $user->getStripeCustomerId();
        }
        $customer = $this->call(fn (StripeClient $c, array $o) => $c->customers->create(array_filter([
            'email' => $user->getEmail(),
            'name' => trim($user->getFirstname() . ' ' . $user->getLastname()) ?: null,
            'address' => $address ? array_filter(['line1' => $address->getAddress(), 'city' => $address->getCity(), 'state' => $address->getProvince(), 'postal_code' => $address->getCodepostal(), 'country' => $address->getCountry()]) : null,
            'metadata' => ['tenant_code' => (string) $this->tenantProvider->getTenantCode(), 'user_id' => (string) $user->getId()],
        ]), $o));
        $user->setStripeCustomerId($customer->id);
        $this->emProvider->getEntityManager()->flush();

        return $customer->id;
    }

    public function ensurePrice(SubscriptionPlan $plan): array
    {
        if ($plan->getStripePriceId()) {
            return [$plan->getStripeProductId(), $plan->getStripePriceId()];
        }
        $productId = $plan->getStripeProductId() ?: $this->call(fn (StripeClient $c, array $o) => $c->products->create([
            'name' => sprintf('%s — %s', $plan->getProduct()?->getName() ?? 'Abonnement', $plan->getName()),
            'metadata' => ['tenant_code' => (string) $this->tenantProvider->getTenantCode(), 'plan_id' => (string) $plan->getId()],
        ], $o))->id;
        $price = $this->call(fn (StripeClient $c, array $o) => $c->prices->create([
            'product' => $productId, 'unit_amount' => $plan->getPrice(), 'currency' => strtolower($plan->getCurrency()),
            'recurring' => ['interval' => $plan->getInterval(), 'interval_count' => $plan->getIntervalCount()],
            'metadata' => ['plan_id' => (string) $plan->getId()],
        ], $o));
        $plan->setStripeProductId($productId)->setStripePriceId($price->id);
        $this->emProvider->getEntityManager()->flush();

        return [$productId, $price->id];
    }

    public function taxRateIds(array $taxes): array
    {
        $ids = [];
        foreach ($taxes as $tax) {
            $percentage = round((float) $tax['rate'] * 100, 4);
            $key = 'stripe_tax_rate_' . hash('sha256', ($this->account() ?? 'platform') . '|' . $tax['label'] . '|' . $percentage);
            $ids[] = $this->cache->get($key, function (ItemInterface $item) use ($tax, $percentage) {
                $item->expiresAfter(86400 * 30);

                return $this->call(fn (StripeClient $c, array $o) => $c->taxRates->create([
                    'display_name' => (string) $tax['label'], 'percentage' => $percentage, 'inclusive' => false,
                    'country' => 'CA', 'description' => 'Taxe du site (table)',
                ], $o))->id;
            });
        }

        return $ids;
    }

    public function ensureShippingPrice(Carrier $carrier, int $amount, string $currency, string $interval, int $intervalCount): string
    {
        $key = 'stripe_shipping_price_' . hash('sha256', implode('|', [$this->account() ?? 'platform', $carrier->getId(), $amount, strtolower($currency), $interval, $intervalCount]));

        return $this->cache->get($key, function (ItemInterface $item) use ($carrier, $amount, $currency, $interval, $intervalCount) {
            $item->expiresAfter(86400 * 30);
            $product = $this->call(fn (StripeClient $c, array $o) => $c->products->create([
                'name' => sprintf('Livraison — %s', $carrier->getName()), 'tax_code' => 'txcd_92010001', // code fiscal « livraison » de Stripe Tax
                'metadata' => ['tenant_code' => (string) $this->tenantProvider->getTenantCode(), 'carrier_id' => (string) $carrier->getId()],
            ], $o));

            return $this->call(fn (StripeClient $c, array $o) => $c->prices->create([
                'product' => $product->id, 'unit_amount' => $amount, 'currency' => strtolower($currency),
                'recurring' => ['interval' => $interval, 'interval_count' => $intervalCount],
            ], $o))->id;
        });
    }

    public function create(string $customerId, string $priceId, int $quantity, int $trialDays, array $metadata, array $taxRateIds, bool $automaticTax, ?string $shippingPriceId = null): array
    {
        $items = [['price' => $priceId, 'quantity' => $quantity, 'metadata' => ['role' => 'plan']]];
        if ($shippingPriceId !== null) {
            $items[] = ['price' => $shippingPriceId, 'quantity' => 1, 'metadata' => ['role' => 'shipping']];
        }
        $params = [
            'customer' => $customerId,
            'items' => $items,
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'metadata' => $metadata,
            // API 2025-03+ : le secret de la première facture est dans latest_invoice.confirmation_secret (payment_intent n'est plus exposé)
            'expand' => ['latest_invoice.confirmation_secret', 'latest_invoice.payment_intent', 'pending_setup_intent'],
        ];
        if ($trialDays > 0) {
            $params['trial_period_days'] = $trialDays;
        }
        if ($automaticTax) {
            $params['automatic_tax'] = ['enabled' => true];
        } elseif ($taxRateIds) {
            $params['default_tax_rates'] = $taxRateIds;
        }

        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->create($params, $o)));
    }

    public function retrieve(string $subscriptionId): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice.confirmation_secret', 'latest_invoice.payment_intent']], $o)));
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd): array
    {
        if (!$atPeriodEnd) {
            return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->cancel($subscriptionId, [], $o)));
        }

        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true], $o)));
    }

    public function resume(string $subscriptionId): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['cancel_at_period_end' => false, 'pause_collection' => ''], $o)));
    }

    public function pause(string $subscriptionId): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['pause_collection' => ['behavior' => 'void']], $o)));
    }

    public function changePlan(string $subscriptionId, string $itemId, string $priceId, int $quantity): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, [
            'items' => [['id' => $itemId, 'price' => $priceId, 'quantity' => $quantity]], 'proration_behavior' => 'create_prorations',
        ], $o)));
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        // Compte Express : aucun portail par défaut, la plateforme crée sa configuration (StripeConnectSetupService)
        $siteName = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([])?->getName() ?? (string) $this->tenantProvider->getTenantCode();
        $configuration = $this->setup->ensurePortalConfiguration($siteName);

        return (string) $this->call(fn (StripeClient $c, array $o) => $c->billingPortal->sessions->create(array_filter(['customer' => $customerId, 'return_url' => $returnUrl, 'configuration' => $configuration]), $o))->url;
    }

    /** Forme commune d'un abonnement Stripe (objet du SDK ou tableau d'un webhook) */
    public static function normalize(StripeSubscription|array $s): array
    {
        $a = $s instanceof StripeSubscription ? $s->toArray() : $s;
        // Ligne de la formule : celle marquée role=plan (la livraison est une seconde ligne), sinon la première
        $items = $a['items']['data'] ?? [];
        $item = null;
        foreach ($items as $candidate) {
            if (($candidate['metadata']['role'] ?? 'plan') === 'plan') {
                $item = $candidate;
                break;
            }
        }
        $item ??= $items[0] ?? null;

        return [
            'id' => (string) ($a['id'] ?? ''),
            'status' => (string) ($a['status'] ?? ''),
            'currentPeriodEnd' => isset($a['current_period_end']) ? (int) $a['current_period_end'] : (isset($item['current_period_end']) ? (int) $item['current_period_end'] : null),
            'cancelAtPeriodEnd' => (bool) ($a['cancel_at_period_end'] ?? false),
            'paused' => !empty($a['pause_collection']),
            'clientSecret' => $a['latest_invoice']['confirmation_secret']['client_secret'] ?? $a['latest_invoice']['payment_intent']['client_secret'] ?? $a['pending_setup_intent']['client_secret'] ?? null,
            'itemId' => $item['id'] ?? null,
            'canceledAt' => isset($a['canceled_at']) ? (int) $a['canceled_at'] : null,
        ];
    }

    private function account(): ?string
    {
        return $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy(['isActive' => true])?->getAccountId();
    }

    /** @throws SubscriptionException 502 */
    private function call(callable $action): mixed
    {
        try {
            $account = $this->account();

            return $action(new StripeClient((string) $this->stripeSecretKey), $account ? ['stripe_account' => $account] : []);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new SubscriptionException(502, 'Stripe : ' . $e->getMessage());
        }
    }
}
