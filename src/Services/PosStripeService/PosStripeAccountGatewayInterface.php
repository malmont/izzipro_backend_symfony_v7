<?php

namespace App\Services\PosStripeService;

/** Comptes Connect de la caisse chez Stripe (M1), par mode (test | live) : simulés dans les tests */
interface PosStripeAccountGatewayInterface
{
    /**
     * État d'un compte (null : compte de la plateforme, site interne).
     *
     * @return array{id: string, livemode: bool, chargesEnabled: bool, payoutsEnabled: bool, detailsSubmitted: bool, currentlyDue: list<string>, pastDue: list<string>, eventuallyDue: list<string>, disabledReason: ?string, currentDeadline: ?int}
     */
    public function retrieveAccount(string $mode, ?string $accountId): array;

    /** Compte Express (encaissement par carte, virements) ; rend son identifiant */
    public function createExpressAccount(string $mode, string $country, ?string $email): string;

    /** Lien d'inscription Stripe (à usage unique, expire en quelques minutes) */
    public function createOnboardingLink(string $mode, string $accountId, string $refreshUrl, string $returnUrl): string;
}
