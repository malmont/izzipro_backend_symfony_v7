<?php
// src/Service/StripeService/StripeService.php

namespace App\Services\StripeService;

use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\PaymentIntent;
use Psr\Log\LoggerInterface;

class StripeService
{
    private string $stripeSecretKey;
    private TenantEntityManagerProvider $emProvider;
    private \Doctrine\ORM\EntityManagerInterface $em;
    private TenantConnectionManager $connectionManager;
    private LoggerInterface $logger;
    private \App\Services\EntityRetrieverService $entityRetrieverService;
    private \App\UseCase\OrderUseCase\CalculateTaxesUseCase $calculateTaxesUseCase;
    private \App\Services\OrderService\RentalPriceCalculator $rentalPriceCalculator;


    public function __construct(
        string $stripeSecretKey,
        TenantEntityManagerProvider $emProvider,
        TenantConnectionManager $connectionManager,
        LoggerInterface $logger,
        \App\Services\EntityRetrieverService $entityRetrieverService,
        \App\UseCase\OrderUseCase\CalculateTaxesUseCase $calculateTaxesUseCase,
        \App\Services\OrderService\RentalPriceCalculator $rentalPriceCalculator
    ) {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->emProvider = $emProvider;
        $this->em = $emProvider->getEntityManager();
        $this->connectionManager = $connectionManager;
        $this->logger = $logger;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->calculateTaxesUseCase = $calculateTaxesUseCase;
        $this->rentalPriceCalculator = $rentalPriceCalculator;
    }

    public function createOnboardingLink(string $refreshUrl, string $returnUrl): string
    {
        Stripe::setApiKey($this->stripeSecretKey);
        $stripeConfigRepo = $this->em->getRepository(StripeConfig::class);
        $stripeConfig = $stripeConfigRepo->findOneBy([]);

        if (!$stripeConfig) {
            $account = Account::create([
                'type' => 'express',
                'requested_capabilities' => ['card_payments', 'transfers'],
            ]);

            $stripeConfig = new StripeConfig();
            $stripeConfig->setAccountId($account->id);
            $stripeConfig->setIsActive(false);
            $this->em->persist($stripeConfig);
            $this->em->flush();
        }

        $accountLink = AccountLink::create([
            'account' => $stripeConfig->getAccountId(),
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $accountLink->url;
    }
    public function finalizeConnection(): bool
    {
        $stripeConfigRepo = $this->em->getRepository(StripeConfig::class);
        $stripeConfig = $stripeConfigRepo->findOneBy([]);

        if (!$stripeConfig || !$stripeConfig->getAccountId()) {
            return false;
        }

        try {
            $stripe = new StripeClient($this->stripeSecretKey);
            $account = $stripe->accounts->retrieve($stripeConfig->getAccountId(), []);
            if ($account->charges_enabled) {
                $stripeConfig->setIsActive(true);
                $this->em->flush();
                return true;
            }
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur finalizeConnection: " . $e->getMessage());
            return false;
        }

        return false;
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
                'metadata' => ['store_code' => $tenantCode]
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

    public function createPaymentIntentFromItems(array $payload, float $priceShipping, string $currency = 'cad'): array
    {
        $items = $payload['items'] ?? [];
        if (empty($items)) {
            return ['error' => 'Items requis', 'status' => 400];
        }

        $globalBooking = $payload['booking'] ?? $payload['rental'] ?? null;
        $itemsTotal = 0.0;

        foreach ($items as $itemData) {
            $productVariantId = $itemData['productVariantId'] ?? null;
            $quantity = $itemData['quantity'] ?? 0;

            if (!$productVariantId || $quantity <= 0) {
                return ['error' => 'Invalid item data (ID ou quantité manquante)', 'status' => 400];
            }

            try {
                $productVariant = $this->entityRetrieverService->findOrFail(
                    \App\Entity\ProductVariant::class,
                    $productVariantId,
                    'Product variant not found'
                );
            } catch (\Exception $e) {
                return ['error' => $e->getMessage(), 'status' => 400];
            }

            $product = $productVariant->getProduct();
            
            // Calcul du prix : Location si données de booking présentes, sinon Retail
            $bookingData = $itemData['booking'] ?? $itemData['rental'] ?? $globalBooking;

            if ($bookingData) {
                $unitPrice = $this->rentalPriceCalculator->calculate($product, $bookingData);
                $this->logger->info("Calcul prix LOCATION pour produit {$product->getId()}: $unitPrice CAD", ['bookingData' => $bookingData]);
            } else {
                $unitPrice = $product->getPrice();
                $this->logger->info("Calcul prix RETAIL pour produit {$product->getId()}: $unitPrice CAD", [
                    'available_keys' => array_keys($itemData),
                    'global_booking_present' => !empty($globalBooking)
                ]);
            }

            if ($unitPrice === null) {
                return ['error' => "Impossible de calculer le prix pour le produit {$product->getName()}", 'status' => 400];
            }

            $itemsTotal += $unitPrice * $quantity;
        }

        $subtotal = $itemsTotal + $priceShipping;

        // Create a dummy order for tax calculation (NOT PERSISTED)
        $dummyOrder = new \App\Entity\Order();

        $totalTax = $this->calculateTaxesUseCase->execute($dummyOrder, $subtotal, false);
        $totalAmount = $subtotal + $totalTax;

        $amountInCents = (int) round($totalAmount);

        if ($amountInCents <= 0) {
            return ['error' => 'Montant invalide calculé', 'status' => 400];
        }

        $clientSecret = $this->createPaymentIntent($amountInCents, $currency);

        if (!$clientSecret) {
            return ['error' => 'Impossible de créer l\'intention de paiement.', 'status' => 500];
        }

        return [
            'success' => true,
            'clientSecret' => $clientSecret,
            'calculatedAmount' => $totalAmount
        ];
    }

    public function getStripeConfigForCurrentTenant(): ?StripeConfig
    {
        $stripeConfigRepo = $this->em->getRepository(StripeConfig::class);
        return $stripeConfigRepo->findOneBy([]);
    }

    public function disconnectCurrentTenant(): bool
    {
        $stripeConfig = $this->getStripeConfigForCurrentTenant();

        if ($stripeConfig) {
            $this->em->remove($stripeConfig);
            $this->em->flush();
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
            return $paymentIntent->status === 'succeeded' ? $paymentIntent : null;
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur verification Stripe PaymentIntent $paymentIntentId: " . $e->getMessage());
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
