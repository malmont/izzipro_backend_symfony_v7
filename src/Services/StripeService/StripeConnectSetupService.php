<?php

namespace App\Services\StripeService;

use App\Entity\Entreprise;
use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages du compte Stripe connecté d'un site que son tableau de bord ne permet pas (compte Express, 09/10/2026) :
 * configuration du portail client (carte, factures, résiliation) et Stripe Tax (siège, inscriptions fiscales). Fait par
 * la plateforme, par l'API, avec la clé de la plateforme et l'en-tête Stripe-Account.
 */
final class StripeConnectSetupService
{
    /** Inscriptions fiscales par défaut d'un site canadien : TPS/TVH fédérale et TVQ */
    public const DEFAULT_REGISTRATIONS_CA = ['CA', 'CA-QC'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $stripeSecretKey
    ) {
    }

    public function accountId(): ?string
    {
        return $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy(['isActive' => true])?->getAccountId();
    }

    /** Configuration du portail client (créée au besoin) ; null sans compte connecté */
    public function ensurePortalConfiguration(string $siteName): ?string
    {
        $account = $this->accountId();
        if ($account === null) {
            return null;
        }
        $client = $this->client();
        $existing = $client->billingPortal->configurations->all(['limit' => 1, 'active' => true], ['stripe_account' => $account]);
        if ($existing->data !== []) {
            return $existing->data[0]->id;
        }
        $configuration = $client->billingPortal->configurations->create([
            'business_profile' => ['headline' => sprintf('%s — gestion de votre abonnement', $siteName)],
            'features' => [
                'invoice_history' => ['enabled' => true],
                'payment_method_update' => ['enabled' => true],
                'customer_update' => ['enabled' => true, 'allowed_updates' => ['email', 'address', 'phone']],
                'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end'],
            ],
        ], ['stripe_account' => $account]);

        return $configuration->id;
    }

    /**
     * Stripe Tax du compte connecté : siège social (adresse de la fiche entreprise par défaut) et inscriptions
     * (« CA » = fédérale, « CA-QC » = provinciale Québec, « US-CA » = Californie, « FR » = TVA France…).
     *
     * @param array{line1: string, city: string, state: ?string, postal_code: string, country: string}|null $headOffice
     * @param list<string> $registrations
     * @return array{status: string, headOffice: ?array, registrations: list<string>}
     */
    public function setupTax(?array $headOffice, array $registrations): array
    {
        $account = $this->accountId();
        if ($account === null) {
            throw new \RuntimeException('Aucun compte Stripe connecté actif sur ce site.');
        }
        $client = $this->client();
        $options = ['stripe_account' => $account];
        $headOffice ??= $this->companyAddress();
        if ($headOffice !== null) {
            $client->tax->settings->update(['head_office' => ['address' => array_filter($headOffice)], 'defaults' => ['tax_behavior' => 'exclusive', 'tax_code' => 'txcd_99999999']], $options);
        }
        $active = [];
        foreach ($client->tax->registrations->all(['status' => 'active', 'limit' => 100], $options)->data as $registration) {
            $active[] = $registration->country . (isset($registration->country_options->ca->province_standard->province) ? '-' . $registration->country_options->ca->province_standard->province : '')
                . (isset($registration->country_options->us->state) ? '-' . $registration->country_options->us->state : '');
        }
        foreach ($registrations as $code) {
            if (in_array($code, $active, true)) {
                continue;
            }
            $client->tax->registrations->create(self::registrationParams($code), $options);
            $active[] = $code;
        }
        $settings = $client->tax->settings->retrieve([], $options);

        return ['status' => (string) $settings->status, 'headOffice' => $settings->head_office?->address?->toArray(), 'registrations' => $active];
    }

    /** @return array{line1: string, city: string, state: ?string, postal_code: string, country: string}|null */
    public function companyAddress(): ?array
    {
        $address = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getAddressEntreprise();
        if ($address === null || !$address->getStreet1() || !$address->getCity() || !$address->getCountry()) {
            return null;
        }
        $country = strtoupper(trim((string) $address->getCountry()));
        $country = match ($country) { 'CANADA' => 'CA', 'FRANCE' => 'FR', 'ÉTATS-UNIS', 'ETATS-UNIS', 'USA', 'UNITED STATES' => 'US', default => substr($country, 0, 2) };

        return ['line1' => (string) $address->getStreet1(), 'city' => (string) $address->getCity(), 'state' => $address->getState() ?: null, 'postal_code' => (string) $address->getZip(), 'country' => $country];
    }

    /** Paramètres d'une inscription fiscale Stripe d'après un code court */
    public static function registrationParams(string $code): array
    {
        [$country, $region] = array_pad(explode('-', strtoupper($code), 2), 2, null);
        $options = match (true) {
            $country === 'CA' && $region === null => ['ca' => ['type' => 'standard']],
            $country === 'CA' => ['ca' => ['type' => 'province_standard', 'province_standard' => ['province' => $region]]],
            $country === 'US' && $region !== null => ['us' => ['type' => 'state_sales_tax', 'state' => $region]],
            $country === 'FR' => ['fr' => ['type' => 'standard']],
            default => [strtolower($country) => ['type' => 'standard']],
        };

        return ['country' => $country, 'country_options' => $options, 'active_from' => 'now'];
    }

    private function client(): StripeClient
    {
        return new StripeClient((string) $this->stripeSecretKey);
    }
}
