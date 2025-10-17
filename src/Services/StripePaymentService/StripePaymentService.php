<?php
// src/Services/StripePaymentService/StripePaymentService.php

namespace App\Services\StripePaymentService;

use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;
use Psr\Log\LoggerInterface;

class StripePaymentService
{
    private string $stripeSecretKey;
    private TenantEntityManagerProvider $emProvider;
    private LoggerInterface $logger;
    private ?string $connectedAccountId = null;

    public function __construct(
        string $stripeSecretKey, 
        TenantEntityManagerProvider $emProvider, 
        LoggerInterface $logger
    ) {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->emProvider = $emProvider;
        $this->logger = $logger;

        $em = $this->emProvider->getEntityManager();
        $config = $em->getRepository(StripeConfig::class)->findOneBy(['isActive' => true]);
        if ($config) {
            $this->connectedAccountId = $config->getAccountId();
        }
    }


    public function createPayment(string $paymentIntentId): array
    {
        if (!$this->connectedAccountId) {
            return ['success' => false, 'errors' => ['Configuration Stripe non trouvée ou inactive.']];
        }

        try {
            Stripe::setApiKey($this->stripeSecretKey);

            $paymentIntent = PaymentIntent::retrieve($paymentIntentId, [
                'stripe_account' => $this->connectedAccountId,
            ]);

            if ($paymentIntent->status !== 'succeeded') {
                return ['success' => false, 'errors' => ['Le paiement n\'a pas abouti. Statut : ' . $paymentIntent->status]];
            }

            return [
                'success' => true, 
                'payment' => $paymentIntent 
            ];

        } catch (ApiErrorException $e) {
            $this->logger->error('Erreur API Stripe lors de la vérification du paiement : ' . $e->getMessage());
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }
}