<?php
// src/Services/StripePaymentService/StripePaymentService.php

namespace App\Services\StripePaymentService;

use App\Entity\Payments;
use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;
use Psr\Log\LoggerInterface;

class StripePaymentService
{
    private string $stripeSecretKey;
    private TenantEntityManagerProvider $emProvider;
    private LoggerInterface $logger;
    private TenantConnectionManager $connectionManager;

    public function __construct(
        string $stripeSecretKey,
        TenantEntityManagerProvider $emProvider,
        LoggerInterface $logger,
        TenantConnectionManager $connectionManager
    ) {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->emProvider = $emProvider;
        $this->logger = $logger;
        $this->connectionManager = $connectionManager;
    }

    /**
     * ✅ MÉTHODE MISE À JOUR AVEC L'AIGUILLAGE
     * Vérifie un PaymentIntent Stripe et retourne l'objet de Stripe.
     */
    public function createPayment(string $paymentIntentId): array
    {
        $tenantCode = $this->connectionManager->getCurrentTenantCode();
        if (!$tenantCode) {
            $this->logger->error("Impossible de vérifier le PaymentIntent: tenantCode inconnu.");
            return ['success' => false, 'errors' => ['Tenant inconnu.']];
        }

        try {
            Stripe::setApiKey($this->stripeSecretKey);

            $stripeOptions = [];
            $isInternal = $this->connectionManager->isTenantInternal($tenantCode);

            if ($isInternal) {
                $this->logger->info("Vérification de paiement interne (V2V) pour le tenant: $tenantCode");
            } else {
                $em = $this->emProvider->getEntityManager();
                $config = $em->getRepository(StripeConfig::class)->findOneBy(['isActive' => true]);

                if ($config) {
                    $stripeOptions['stripe_account'] = $config->getAccountId();
                    $this->logger->info("Vérification de paiement externe (Connect) pour le tenant: $tenantCode");
                } else {
                    $this->logger->error("BLOCAGE VÉRIFICATION: Tentative de vérification d'un paiement pour un client externe non-connecté à Stripe. Tenant: $tenantCode");
                    return ['success' => false, 'errors' => ['Le compte Stripe de cette boutique n\'est pas actif.']];
                }
            }
            $paymentIntent = $this->retrieveStripePaymentIntent($paymentIntentId, $stripeOptions);
            if ($paymentIntent->status !== 'succeeded') {
                return ['success' => false, 'errors' => ['Le paiement n\'a pas abouti. Statut : ' . $paymentIntent->status]];
            }
            return [
                'success' => true,
                'payment' => $paymentIntent
            ];
        } catch (ApiErrorException $e) {
            $this->logger->error("Erreur API Stripe (createPayment) pour $tenantCode: " . $e->getMessage());
            return ['success' => false, 'errors' => [$e->getMessage()]];
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
