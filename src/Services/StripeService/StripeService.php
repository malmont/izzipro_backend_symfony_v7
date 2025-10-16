<?php
// src/Services/StripeService/StripeService.php

namespace App\Services\StripeService;

use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider; 
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\PaymentIntent;


class StripeService
{
    private string $stripeSecretKey;

    public function __construct(string $stripeSecretKey, TenantEntityManagerProvider $emProvider)
    {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->em = $emProvider->getEntityManager(); 
    }

    public function createOnboardingLink(string $refreshUrl, string $returnUrl): string
    {
        Stripe::setApiKey($this->stripeSecretKey);
        $stripeConfigRepo = $this->em->getRepository(StripeConfig::class);
        $stripeConfig = $stripeConfigRepo->findOneBy([]);

        if (!$stripeConfig) {
            $account = Account::create([
                'type' => 'express',
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
            return false;
        }

        return false;
    }
    public function createPaymentIntent(int $amount, string $currency): ?string
    {
        $stripeConfigRepo = $this->em->getRepository(StripeConfig::class);
        $stripeConfig = $stripeConfigRepo->findOneBy(['isActive' => true]);

        if (!$stripeConfig || !$stripeConfig->getAccountId()) {
            return null;
        }

        try {
            Stripe::setApiKey($this->stripeSecretKey);
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true],
            ], [
                'stripe_account' => $stripeConfig->getAccountId(),
            ]);

            return $paymentIntent->client_secret;

        } catch (ApiErrorException $e) {
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