<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Order;
use App\Entity\Payments;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;

class GemsuiteSaleManager
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    private const METHOD_ID_STRIPE = 207;

    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger
    ) {
    }


    public function createSale(Order $order): ?array
    {
        $user = $order->getUserId();
        if (!$user || !$user->getGemsuiteClientId()) {
            $this->logger->warning(sprintf('Client GEM-SUITE manquant pour la commande #%d.', $order->getId()));
            return null;
        }

        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Token manquant pour le tenant "%s".', $tenantCode));
            return null;
        }

        try {
            $saleData = $this->createSaleShell($user->getGemsuiteClientId(), $order, $token);
            $saleId = $saleData['id'] ?? null;

            if (!$saleId) {
                throw new \Exception('ID de vente non retourné par GEM-SUITE lors de la création.');
            }
            $this->addProductsToSale($order, $saleId, $token);
            $this->finalizeSaleAsInvoice($saleId, $token);
            $this->createPaymentForSale($order, $saleId, $token);

            $this->logger->info(sprintf('Succès: Commande #%d synchronisée et facturée (GemSuite ID: %d).', $order->getId(), $saleId));
            
            return $saleData;

        } catch (\Throwable $e) {
            $this->logger->error('Erreur critique synchro GEM-SUITE : ' . $e->getMessage());
            return null;
        }
    }


    private function createSaleShell(int $clientId, Order $order, string $token): ?array
    {
        $shippingTotal = $order->getShippingCost() ?? 0;
        $shippingCostForGem = $shippingTotal > 0 ? $shippingTotal / 100 : 0;
        $payload = [
            'client_id' => (string) $clientId,
            'date' => $order->getOrderDate()->format('Y-m-d'),
            'external_number' => $order->getReference() ?? (string)$order->getId(),
            'shipping_cost' => $shippingCostForGem,
        ];

        $response = $this->client->request('POST', self::GEMSUITE_API_URL . 'sales', [
            'auth_bearer' => $token,
            'json' => $payload,
        ]);

        if (!in_array($response->getStatusCode(), [200, 201])) {
            throw new \Exception('Erreur API createSale: ' . $response->getContent(false));
        }

        return $response->toArray()['data'] ?? null;
    }

    private function addProductsToSale(Order $order, int $saleId, string $token): void
    {
        foreach ($order->getOrderItems() as $item) {
            $product = $item->getProductVariant() ? $item->getProductVariant()->getProduct() : null;
            $gemProductId = $product?->getGemsuiteProductId();

            if ($gemProductId) {
                $price = $item->getUnitPrice() / 100;

                $this->client->request('POST', self::GEMSUITE_API_URL . 'sales_products', [
                    'auth_bearer' => $token,
                    'json' => [
                        'sale_id' => $saleId,
                        'product_id' => $gemProductId,
                        'product_quantity' => $item->getQuantity(),
                        'product_price' => $price,
                    ],
                ]);
            }
        }
    }


    private function finalizeSaleAsInvoice(int $saleId, string $token): void
    {
        $response = $this->client->request('PUT', self::GEMSUITE_API_URL . 'sales/' . $saleId, [
            'auth_bearer' => $token,
            'json' => ['action' => 'invoice'],
        ]);

        if (!in_array($response->getStatusCode(), [200, 201])) {
            $this->logger->warning(sprintf('Impossible de convertir la vente #%d en facture (Code: %d).', $saleId, $response->getStatusCode()));
        }
    }

    private function createPaymentForSale(Order $order, int $saleId, string $token): void
    {
        /** @var Payments|null $payment */
        $payment = $order->getPayments()->first(); 

        if (!$payment) {
            $this->logger->info("Aucun paiement trouvé pour la commande #{$order->getId()}.");
            return;
        }
        $stripeRef = $payment->getStripePaymentId();
        $amount = $payment->getAmount() / 100;

        $payload = [
            'invoice_id' => $saleId,
            'amount' => $amount,
            'date_payment' => $payment->getPaymentDate()->format('Y-m-d'),
            'method_id' => self::METHOD_ID_STRIPE, 
        ];

        if ($stripeRef) {
            $payload['reference'] = $stripeRef; 
            $payload['note'] = "Stripe ID: " . $stripeRef; // On le met aussi en note par sécurité
        }

        $response = $this->client->request('POST', self::GEMSUITE_API_URL . 'sales_payment', [
            'auth_bearer' => $token,
            'json' => $payload,
        ]);

        if (!in_array($response->getStatusCode(), [200, 201])) {
            $this->logger->error("Erreur ajout paiement Stripe sur GemSuite pour vente #$saleId", [
                'response' => $response->getContent(false)
            ]);
        } else {
            $this->logger->info("Paiement Stripe ($stripeRef) ajouté à la vente #$saleId");
        }
    }
}