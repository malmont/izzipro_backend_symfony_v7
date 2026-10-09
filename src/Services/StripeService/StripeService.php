<?php
// src/Service/StripeService/StripeService.php

namespace App\Services\StripeService;

use App\Entity\AddressEntreprise;
use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\PaymentIntent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StripeService
{
    private string $stripeSecretKey;
    private TenantEntityManagerProvider $emProvider;
    private TenantConnectionManager $connectionManager;
    private LoggerInterface $logger;
    private \App\Services\EntityRetrieverService $entityRetrieverService;
    private \App\UseCase\OrderUseCase\CalculateTaxesUseCase $calculateTaxesUseCase;


    public function __construct(
        string $stripeSecretKey,
        TenantEntityManagerProvider $emProvider,
        TenantConnectionManager $connectionManager,
        LoggerInterface $logger,
        \App\Services\EntityRetrieverService $entityRetrieverService,
        \App\UseCase\OrderUseCase\CalculateTaxesUseCase $calculateTaxesUseCase,
        private readonly \App\Services\OrderService\CartQuoteCalculator $quotes
    ) {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->emProvider = $emProvider;
        $this->connectionManager = $connectionManager;
        $this->logger = $logger;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->calculateTaxesUseCase = $calculateTaxesUseCase;
    }

    private function getEm(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    /**
     * Résout le code pays ISO (2 lettres majuscules) depuis l'adresse d'entreprise du tenant.
     * Par défaut : 'US'.
     */
    public function resolveTenantCountryCode(): string
    {
        try {
            $addressRepo = $this->getEm()->getRepository(AddressEntreprise::class);
            $address = $addressRepo->findOneBy([]);
            if ($address && $address->getCountry()) {
                $rawCountry = trim($address->getCountry());
                $normalized = mb_strtoupper($rawCountry, 'UTF-8');

                $countryMap = [
                    'CANADA' => 'CA',
                    'FRANCE' => 'FR',
                    'ÉTATS-UNIS' => 'US',
                    'ETATS-UNIS' => 'US',
                    'UNITED STATES' => 'US',
                    'USA' => 'US',
                    'BELGIQUE' => 'BE',
                    'BELGIUM' => 'BE',
                    'SUISSE' => 'CH',
                    'SWITZERLAND' => 'CH',
                    'ROYAUME-UNI' => 'GB',
                    'UNITED KINGDOM' => 'GB',
                    'UK' => 'GB',
                    'ESPAGNE' => 'ES',
                    'SPAIN' => 'ES',
                    'ALLEMAGNE' => 'DE',
                    'GERMANY' => 'DE',
                    'ITALIE' => 'IT',
                    'ITALY' => 'IT',
                ];

                if (isset($countryMap[$normalized])) {
                    return $countryMap[$normalized];
                }

                if (strlen($normalized) === 2 && ctype_alpha($normalized)) {
                    return $normalized;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Erreur lors de la résolution du pays du tenant pour Stripe: " . $e->getMessage());
        }

        return 'US';
    }

    public function createOnboardingLink(string $refreshUrl, string $returnUrl): string
    {
        Stripe::setApiKey($this->stripeSecretKey);
        $stripeConfigRepo = $this->getEm()->getRepository(StripeConfig::class);
        $stripeConfig = $stripeConfigRepo->findOneBy([]);

        $countryCode = $this->resolveTenantCountryCode();
        $isLiveKey = str_starts_with($this->stripeSecretKey, 'rk_live_') || str_starts_with($this->stripeSecretKey, 'sk_live_');

        $needNewAccount = false;
        if (!$stripeConfig || !$stripeConfig->getAccountId()) {
            $needNewAccount = true;
        } else {
            // Vérifier si le compte existant est valide et correspond au bon mode (test vs live) et au bon pays
            try {
                $account = Account::retrieve($stripeConfig->getAccountId());
                if ($account->livemode !== $isLiveKey) {
                    $this->logger->info(sprintf(
                        "Compte Stripe %s : discordance de mode (compte livemode=%s, clé livemode=%s). Nouveau compte requis.",
                        $stripeConfig->getAccountId(),
                        $account->livemode ? 'true' : 'false',
                        $isLiveKey ? 'true' : 'false'
                    ));
                    $needNewAccount = true;
                } elseif (strtoupper($account->country ?? '') !== $countryCode) {
                    $this->logger->info(sprintf(
                        "Compte Stripe %s : discordance de pays (compte country=%s, pays requis=%s). Nouveau compte requis.",
                        $stripeConfig->getAccountId(),
                        $account->country ?? 'inconnu',
                        $countryCode
                    ));
                    $needNewAccount = true;
                }
            } catch (ApiErrorException $e) {
                $this->logger->warning("Erreur lors de la récupération du compte Stripe {$stripeConfig->getAccountId()}: " . $e->getMessage());
                $needNewAccount = true;
            }
        }

        if ($needNewAccount) {
            $accountParams = [
                'type' => 'express',
                'country' => $countryCode,
                'requested_capabilities' => ['card_payments', 'transfers'],
            ];

            // Pré-remplir l'email si disponible dans l'adresse entreprise
            try {
                $address = $this->getEm()->getRepository(AddressEntreprise::class)->findOneBy([]);
                if ($address && $address->getEmail()) {
                    $accountParams['email'] = $address->getEmail();
                }
            } catch (\Throwable) {
                // Ignore
            }

            $account = Account::create($accountParams);

            if (!$stripeConfig) {
                $stripeConfig = new StripeConfig();
                $this->getEm()->persist($stripeConfig);
            }
            $stripeConfig->setAccountId($account->id);
            $stripeConfig->setIsActive(false);
            $this->getEm()->flush();
        }

        try {
            $accountLink = AccountLink::create([
                'account' => $stripeConfig->getAccountId(),
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);
        } catch (ApiErrorException $e) {
            // Si une erreur liée au mode (test/live) ou à un compte invalide survient, recréer un compte propre
            if (str_contains(strtolower($e->getMessage()), 'test mode') ||
                str_contains(strtolower($e->getMessage()), 'live mode') ||
                str_contains(strtolower($e->getMessage()), 'no such account')) {

                $this->logger->warning("AccountLink a échoué ({$e->getMessage()}), re-création d'un compte propre pour {$countryCode}.");
                $accountParams = [
                    'type' => 'express',
                    'country' => $countryCode,
                    'requested_capabilities' => ['card_payments', 'transfers'],
                ];
                $account = Account::create($accountParams);
                $stripeConfig->setAccountId($account->id);
                $stripeConfig->setIsActive(false);
                $this->getEm()->flush();

                $accountLink = AccountLink::create([
                    'account' => $stripeConfig->getAccountId(),
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                    'type' => 'account_onboarding',
                ]);
            } else {
                throw $e;
            }
        }

        return $accountLink->url;
    }
    public function finalizeConnection(): bool
    {
        $stripeConfigRepo = $this->getEm()->getRepository(StripeConfig::class);
        $stripeConfig = $stripeConfigRepo->findOneBy([]);

        if (!$stripeConfig || !$stripeConfig->getAccountId()) {
            return false;
        }

        try {
            $stripe = new StripeClient($this->stripeSecretKey);
            $account = $stripe->accounts->retrieve($stripeConfig->getAccountId(), []);
            if ($account->charges_enabled || $account->details_submitted) {
                $stripeConfig->setIsActive(true);
                $this->getEm()->flush();
                return true;
            }
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur finalizeConnection: " . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Options Stripe du site courant : [] pour un site interne (compte de la plateforme), ['stripe_account' => …] pour un
     * site connecté, null si le site n'a pas de compte Stripe actif (paiement impossible).
     */
    public function accountOptions(): ?array
    {
        $tenantCode = $this->connectionManager->getCurrentTenantCode();
        if (!$tenantCode) {
            return null;
        }
        if ($this->connectionManager->isTenantInternal($tenantCode)) {
            return [];
        }
        $stripeConfig = $this->getStripeConfigForCurrentTenant();

        return $stripeConfig && $stripeConfig->isActive() ? ['stripe_account' => $stripeConfig->getAccountId()] : null;
    }

    /** Commission de la plateforme sur un paiement d'un site connecté (1 %) */
    public static function applicationFee(int $amount): int
    {
        return (int) (($amount * 1) / 100);
    }

    public function secretKey(): string
    {
        return $this->stripeSecretKey;
    }

    /**
     * ✅ C'EST LA MÉTHODE ENTIÈREMENT MISE À JOUR AVEC L'AIGUILLAGE
     */
    public function createPaymentIntent(int $amount, string $currency): ?string
    {
        $tenantCode = $this->connectionManager->getCurrentTenantCode();
        if (!$tenantCode) {
            $this->logger->error("Impossible de créer un PaymentIntent: tenantCode inconnu.");
            return null;
        }

        try {
            Stripe::setApiKey($this->stripeSecretKey);

            $options = [
                'amount' => $amount,
                'currency' => $currency,
                'payment_method_types' => ['card'],
                'metadata' => ['store_code' => $tenantCode],
                'capture_method' => 'manual'
            ];

            $stripeOptions = [];
            $isInternal = $this->connectionManager->isTenantInternal($tenantCode);

            if ($isInternal) {
                $this->logger->info("Paiement interne (V2V) pour le tenant: $tenantCode");
            } else {
                $stripeConfig = $this->getStripeConfigForCurrentTenant();
                if ($stripeConfig && $stripeConfig->isActive()) {
                    $stripeOptions['stripe_account'] = $stripeConfig->getAccountId();

                    // Calcul de la commission de 1% pour la plateforme (V2V)
                    $platformFeePercent = 1;
                    $applicationFeeAmount = (int) (($amount * $platformFeePercent) / 100);
                    $options['application_fee_amount'] = $applicationFeeAmount;

                    $this->logger->info("Paiement externe (Connect) pour le tenant: $tenantCode avec commission V2V de $applicationFeeAmount cents.");
                } else {
                    $this->logger->error("BLOCAGE PAIEMENT: Tentative de paiement sur un client externe non-connecté à Stripe. Tenant: $tenantCode");
                    return null;
                }
            }
            $paymentIntent = PaymentIntent::create($options, $stripeOptions);
            return $paymentIntent->client_secret;
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur API Stripe (createPaymentIntent) pour $tenantCode: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Intention de paiement d'un panier : montant = total du devis du serveur (CartQuoteCalculator : lignes, livraison,
     * taxes ; caution exclue), devise du site. Le navigateur n'envoie aucun montant d'article ; priceShipping (cents)
     * n'est lu que pour un transporteur EasyPost.
     *
     * @param array<string, mixed> $payload items[], carrierId?, priceShipping|shippingPrice?, booking? (ancien envoi global)
     * @return array{success?: bool, clientSecret?: string, calculatedAmount?: int, quote?: array, error?: string, status?: int, errors?: array}
     */
    public function createPaymentIntentFromItems(array $payload, float $priceShipping = 0.0, ?string $currency = null): array
    {
        if (!isset($payload['priceShipping']) && !isset($payload['shippingPrice']) && $priceShipping > 0) {
            $payload['priceShipping'] = $priceShipping;
        }
        try {
            $quote = $this->quotes->quote(\App\Dto\CartQuoteInputDto::fromArray($payload));
        } catch (\App\Services\OrderService\CartQuoteException $e) {
            return ['error' => $e->getMessage(), 'errors' => $e->errors, 'status' => $e->getStatusCode()];
        }

        $amountInCents = (int) $quote['total'];
        if ($amountInCents <= 0) {
            return ['error' => 'Montant invalide calculé', 'status' => 400];
        }

        $clientSecret = $this->createPaymentIntent($amountInCents, strtolower($currency ?? $quote['currency']));

        if (!$clientSecret) {
            return ['error' => 'Impossible de créer l\'intention de paiement.', 'status' => 500];
        }

        return [
            'success' => true,
            'clientSecret' => $clientSecret,
            'calculatedAmount' => $amountInCents,
            'quote' => $quote,
        ];
    }

    public function getStripeConfigForCurrentTenant(): ?StripeConfig
    {
        $stripeConfigRepo = $this->getEm()->getRepository(StripeConfig::class);
        return $stripeConfigRepo->findOneBy([]);
    }

    public function disconnectCurrentTenant(): bool
    {
        $stripeConfig = $this->getStripeConfigForCurrentTenant();

        if ($stripeConfig) {
            $this->getEm()->remove($stripeConfig);
            $this->getEm()->flush();
            return true;
        }

        return false;
    }

    public function verifyPaymentIntent(string $paymentIntentId): ?PaymentIntent
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            
            $stripeOptions = [];
            $tenantCode = $this->connectionManager->getCurrentTenantCode();
            $isInternal = $this->connectionManager->isTenantInternal($tenantCode);

            if (!$isInternal) {
                $stripeConfig = $this->getStripeConfigForCurrentTenant();
                if ($stripeConfig && $stripeConfig->isActive()) {
                    $stripeOptions['stripe_account'] = $stripeConfig->getAccountId();
                }
            }

            $paymentIntent = $this->retrieveStripePaymentIntent($paymentIntentId, $stripeOptions);
            return in_array($paymentIntent->status, ['requires_capture', 'succeeded']) ? $paymentIntent : null;
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur verification Stripe PaymentIntent $paymentIntentId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Annule un paiement autorisé mais non capturé (capture_method manual) : l'autorisation est libérée,
     * le client n'est pas débité. Utilisé quand la commande est refusée après le paiement.
     */
    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);

            $stripeOptions = [];
            $tenantCode = $this->connectionManager->getCurrentTenantCode();
            if (!$this->connectionManager->isTenantInternal($tenantCode)) {
                $stripeConfig = $this->getStripeConfigForCurrentTenant();
                if ($stripeConfig && $stripeConfig->isActive()) {
                    $stripeOptions['stripe_account'] = $stripeConfig->getAccountId();
                }
            }

            $paymentIntent = $this->retrieveStripePaymentIntent($paymentIntentId, $stripeOptions);
            if ($paymentIntent->status === 'requires_capture') {
                $paymentIntent->cancel([], $stripeOptions);
            }
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur annulation Stripe PaymentIntent $paymentIntentId: " . $e->getMessage());
        }
    }

    public function capturePaymentIntent(string $paymentIntentId): ?PaymentIntent
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            
            $stripeOptions = [];
            $tenantCode = $this->connectionManager->getCurrentTenantCode();
            $isInternal = $this->connectionManager->isTenantInternal($tenantCode);

            if (!$isInternal) {
                $stripeConfig = $this->getStripeConfigForCurrentTenant();
                if ($stripeConfig && $stripeConfig->isActive()) {
                    $stripeOptions['stripe_account'] = $stripeConfig->getAccountId();
                }
            }

            $paymentIntent = $this->retrieveStripePaymentIntent($paymentIntentId, $stripeOptions);
            if ($paymentIntent->status === 'requires_capture') {
                return $paymentIntent->capture([], $stripeOptions);
            }
            return $paymentIntent;
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur capture Stripe PaymentIntent $paymentIntentId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Wrapper pour PaymentIntent::retrieve afin de faciliter le mocking dans les tests.
     */
    protected function retrieveStripePaymentIntent(string $paymentIntentId, array $options = []): PaymentIntent
    {
        return PaymentIntent::retrieve($paymentIntentId, $options);
    }
}
