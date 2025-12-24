<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Order;
use App\Entity\Payments;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;

class GemsuiteSaleManager
{
    // ID 207 validé ensemble précédemment
    private const METHOD_ID_STRIPE = 207;

    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger,
        private string $gemsuiteApiUrl
    ) {
    }

    public function createSale(Order $order): ?array
    {
        $user = $order->getUserId();
        
        // --- CORRECTION CLIENT ID ---
        // 1. On cherche d'abord dans la relation (Entité GemsuiteClient)
        $gemsuiteClientId = $user?->getGemsuiteClient()?->getGemsuiteId();
        
        // 2. Si pas de relation, on cherche dans le champ integer direct
        if (!$gemsuiteClientId) {
            $gemsuiteClientId = $user?->getGemsuiteClientId();
        }

        if (!$gemsuiteClientId) {
            $this->logger->warning(sprintf('Client GEM-SUITE manquant pour la commande #%d (User ID: %d).', $order->getId(), $user?->getId()));
            return null;
        }

        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        $token = $this->tenantManager->getTenantToken($tenantCode);
        
        if (!$token) {
            $this->logger->error(sprintf('Token manquant pour le tenant "%s".', $tenantCode));
            return null;
        }

        try {
            // On envoie le bon ID trouvé plus haut
            $saleData = $this->createSaleShell($gemsuiteClientId, $order, $token);
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
            'client_id' => $clientId, // Ici on a un INT propre (ex: 12484)
            'date' => $order->getOrderDate()->format('Y-m-d'),
            'external_number' => $order->getReference() ?? (string)$order->getId(),
            'shipping_cost' => $shippingCostForGem,
        ];

        $response = $this->client->request('POST', $this->gemsuiteApiUrl . 'sales', [
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
            $variant = $item->getProductVariant();
            $gemProductId = null;

            if ($variant) {
                // 1. On récupère l'ID de la variante
                $variantId = $variant->getGemsuiteVariantId();
                
                // ⚠️ SÉCURITÉ RÉTROCOMPATIBILITÉ :
                // Si l'ID est "32-default", le (int) va le transformer en 32 (ID du parent).
                // Si l'ID est "1055" (Variant réel), il reste 1055.
                if ($variantId) {
                    $gemProductId = (int) $variantId; 
                }

                // 2. FALLBACK : Si toujours pas d'ID, on prend le parent direct
                if (!$gemProductId && $variant->getProduct()) {
                    $gemProductId = $variant->getProduct()->getGemsuiteProductId();
                }
            }

            if ($gemProductId) {
                $price = $item->getUnitPrice() / 100;

                $this->client->request('POST', $this->gemsuiteApiUrl . 'sales_products', [
                    'auth_bearer' => $token,
                    'json' => [
                        'sale_id' => $saleId,
                        'product_id' => $gemProductId, // Sera "32" ou "1055" (propre)
                        'product_quantity' => $item->getQuantity(),
                        'product_price' => $price,
                    ],
                ]);
            } else {
                $this->logger->warning("Impossible de trouver un ID GemSuite pour l'item commande " . $item->getId());
            }
        }
    }

    private function finalizeSaleAsInvoice(int $saleId, string $token): void
    {
        $payload = [
            'id' => $saleId, 
            'action' => 'invoice'
        ];

        $response = $this->client->request('PUT', $this->gemsuiteApiUrl . 'sales/' . $saleId, [
            'auth_bearer' => $token,
            'json' => $payload,
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

        $formattedAmount = number_format($amount, 2, '.', '');

        $payload = [
            'invoice_id' => $saleId,
            'amount' => $formattedAmount,
            'date_payment' => $payment->getPaymentDate()->format('Y-m-d'),
            'method_id' => self::METHOD_ID_STRIPE, 
        ];

        if ($stripeRef) {
            $payload['reference'] = $stripeRef; 
            $payload['note'] = "Stripe ID: " . $stripeRef;
        }

        $response = $this->client->request('POST', $this->gemsuiteApiUrl . 'sales_payment', [
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