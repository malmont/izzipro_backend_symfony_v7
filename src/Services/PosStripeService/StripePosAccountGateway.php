<?php

namespace App\Services\PosStripeService;

use App\Services\CaisseService\CashRegisterException;
use App\Services\StripeService\StripeAccountResolver;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/** Comptes Connect de la caisse chez Stripe, avec la clé du mode demandé (StripeAccountResolver) ; aucune clé au journal */
class StripePosAccountGateway implements PosStripeAccountGatewayInterface
{
    public function __construct(private readonly StripeAccountResolver $accounts)
    {
    }

    public function retrieveAccount(string $mode, ?string $accountId): array
    {
        $account = $this->call($mode, fn (StripeClient $c) => $accountId !== null ? $c->accounts->retrieve($accountId, []) : $c->accounts->retrieve(null, []));
        $req = $account->requirements ?? null;

        return [
            'id' => (string) $account->id, 'livemode' => (bool) ($account->livemode ?? false),
            'chargesEnabled' => (bool) ($account->charges_enabled ?? false), 'payoutsEnabled' => (bool) ($account->payouts_enabled ?? false),
            'detailsSubmitted' => (bool) ($account->details_submitted ?? false),
            'currentlyDue' => array_values((array) ($req?->currently_due ?? [])), 'pastDue' => array_values((array) ($req?->past_due ?? [])),
            'eventuallyDue' => array_values((array) ($req?->eventually_due ?? [])), 'disabledReason' => $req?->disabled_reason ?? null,
            'currentDeadline' => $req?->current_deadline ?? null,
        ];
    }

    public function createExpressAccount(string $mode, string $country, ?string $email): string
    {
        $params = ['type' => 'express', 'country' => $country, 'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]]];
        if ($email !== null) {
            $params['email'] = $email;
        }

        return (string) $this->call($mode, fn (StripeClient $c) => $c->accounts->create($params))->id;
    }

    public function createOnboardingLink(string $mode, string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return (string) $this->call($mode, fn (StripeClient $c) => $c->accountLinks->create(['account' => $accountId, 'refresh_url' => $refreshUrl, 'return_url' => $returnUrl, 'type' => 'account_onboarding']))->url;
    }

    private function call(string $mode, callable $action): mixed
    {
        $key = $this->accounts->secretKey($mode);
        if ($key === null) {
            throw new CashRegisterException('STRIPE_KEYS_MISSING', $mode === StripeAccountResolver::LIVE ? 'Clés Stripe réelles de la caisse non configurées sur le serveur (STRIPE_LIVE_SECRET_KEY)' : 'Clé Stripe de test non configurée sur le serveur', 409);
        }
        try {
            return $action(new StripeClient($key));
        } catch (ApiErrorException $e) {
            throw new CashRegisterException('STRIPE_ERROR', 'Stripe : ' . $e->getMessage(), 502, [], ['stripeCode' => $e->getStripeCode()]);
        }
    }
}
