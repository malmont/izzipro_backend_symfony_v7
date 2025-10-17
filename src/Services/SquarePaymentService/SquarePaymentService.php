<?php

namespace App\Services\SquarePaymentService;

use App\Entity\SquareConfig;
use Doctrine\ORM\EntityManagerInterface;
use Square\SquareClient;
use Square\Models\Money;
use Square\Models\CreatePaymentRequest;

class SquarePaymentService
{
    private $client;
    private $applicationId;

    public function __construct(EntityManagerInterface $em)
    {
        $config = $em->getRepository(SquareConfig::class)->findOneBy(['isActive' => true]);

        if (!$config) {
            throw new \Exception("Aucune configuration Square active.");
        }

        $this->applicationId = $config->getApplicationId();

        $this->client = new SquareClient([
            'accessToken' => $config->getAccessToken(),
            'environment' => 'sandbox', // ⚠️ Remplace par 'production' après les tests
        ]);
    }

    public function createPayment(string $nonce, int $amount): array
    {
        $paymentsApi = $this->client->getPaymentsApi();

        // ✅ Créer correctement le montant (amount_money)
        $money = new Money();
        $money->setAmount($amount);
        $money->setCurrency('CAD'); 

        // ✅ Générer une clé idempotente pour éviter les paiements en double
        $idempotencyKey = uniqid('payment_', true);

        // ✅ Créer la requête complète de paiement
        $paymentRequest = new CreatePaymentRequest(
            $nonce,              // 🔑 source_id → Jeton de carte
            $idempotencyKey      // 🔑 idempotency_key → Clé unique
        );
        
        // ✅ Ajouter amount_money à la requête
        $paymentRequest->setAmountMoney($money);

        // ✅ Exécuter la requête de paiement
        $response = $paymentsApi->createPayment($paymentRequest);

        if ($response->isSuccess()) {
            return [
                'success' => true,
                'payment' => $response->getResult()->getPayment()
            ];
        }

        return [
            'success' => false,
            'errors' => $response->getErrors()
        ];
    }
}
