<?php

namespace App\Tests\Fake;

use App\Services\PosStripeService\PosStripeAccountGatewayInterface;

/** Comptes Connect simulés de la caisse (M1) : aucun appel réseau ; états réglables par les tests */
class FakePosStripeAccountGateway implements PosStripeAccountGatewayInterface
{
    /** @var array<string, array> comptes par identifiant */
    public static array $accounts = [];
    public static array $links = [];

    public static function reset(): void
    {
        self::$accounts = [];
        self::$links = [];
    }

    /** Règle l'état d'un compte simulé */
    public static function set(string $id, bool $charges, bool $payouts, array $currentlyDue = [], bool $livemode = true): void
    {
        self::$accounts[$id] = ['id' => $id, 'livemode' => $livemode, 'chargesEnabled' => $charges, 'payoutsEnabled' => $payouts, 'detailsSubmitted' => $charges,
            'currentlyDue' => $currentlyDue, 'pastDue' => [], 'eventuallyDue' => $currentlyDue, 'disabledReason' => $charges ? null : 'requirements.past_due', 'currentDeadline' => null];
    }

    public function retrieveAccount(string $mode, ?string $accountId): array
    {
        return self::$accounts[$accountId ?? 'platform'] ?? ['id' => (string) $accountId, 'livemode' => $mode === 'live', 'chargesEnabled' => false, 'payoutsEnabled' => false, 'detailsSubmitted' => false,
            'currentlyDue' => ['external_account'], 'pastDue' => [], 'eventuallyDue' => ['external_account'], 'disabledReason' => 'requirements.past_due', 'currentDeadline' => null];
    }

    public function createExpressAccount(string $mode, string $country, ?string $email): string
    {
        $id = 'acct_fake' . strtoupper(bin2hex(random_bytes(5)));
        self::set($id, false, false, ['business_type', 'external_account'], $mode === 'live');

        return $id;
    }

    public function createOnboardingLink(string $mode, string $accountId, string $refreshUrl, string $returnUrl): string
    {
        self::$links[] = ['mode' => $mode, 'account' => $accountId, 'refresh' => $refreshUrl, 'return' => $returnUrl];

        return 'https://connect.stripe.test/setup/' . $accountId;
    }
}
