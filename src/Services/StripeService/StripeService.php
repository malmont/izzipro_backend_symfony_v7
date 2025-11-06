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

    public function __construct(
        string $stripeSecretKey, 
        TenantEntityManagerProvider $emProvider,
        TenantConnectionManager $connectionManager,
        LoggerInterface $logger
    ) {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->emProvider = $emProvider; 
        $this->em = $emProvider->getEntityManager(); 
        $this->connectionManager = $connectionManager;
        $this->logger = $logger; 
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
                    $this->logger->info("Paiement externe (Connect) pour le tenant: $tenantCode");
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
}