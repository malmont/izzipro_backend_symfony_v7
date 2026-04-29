<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Order;
use App\Entity\Payments;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;

class GemsuiteSaleManager
{

    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger,
        private string $gemsuiteApiUrl,
        private \App\Services\TenantEntityManagerProvider $emProvider
    ) {}

    public function createSale(Order $order): ?array
    {
        $user = $order->getUserId();

        $gemsuiteClientId = $user?->getGemsuiteClient()?->getGemsuiteId();

        if (!$gemsuiteClientId) {
            $this->logger->warning(sprintf('Client GEM-SUITE (relation introuvable) pour la commande #%d (User ID: %d).', $order->getId(), $user?->getId()));
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
            $this->createPaymentForSale($order, $saleId, $token);
            $this->finalizeSaleAsInvoice($saleId, $token);


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
        $em = $this->emProvider->getEntityManager();

        foreach ($order->getOrderItems() as $item) {
            $variant = $item->getProductVariant();
            $gemProductId = null;
            $payload = [
                'sale_id' => $saleId,
                'product_quantity' => $item->getQuantity(),
                'product_price' => $item->getUnitPrice() / 100,
            ];

            $booking = $item->getBooking();

            if ($booking) {
                // LOGIQUE RENTAL
                $this->logger->info("Synchro Gemsuite: Article détecté comme LOCATION pour l'item " . $item->getId());

                // 1. Swap Product ID par celui du RentalPack
                $packId = $booking->getRentalPackId();
                if ($packId) {
                    $pack = $em->getRepository(\App\Entity\RentalPack::class)->find($packId);
                    if ($pack) {
                        $gemProductId = $pack->getGemsuiteProductId();
                    }
                }

                // 2. Récupération du Véhicule
                $product = $booking->getProduct();
                $vehicle = $em->getRepository(\App\Entity\Vehicle::class)->findOneBy(['product' => $product]);
                if ($vehicle) {
                    $payload['car_id'] = $vehicle->getGemsuiteVehicleId();
                } else {
                    $this->logger->error(sprintf("Synchro Gemsuite SALE: Véhicule manquant pour le produit de location #%d. Le champ car_id sera manquant.", $product?->getId()));
                }

                // 3. Dates de location
                $payload['car_date_start'] = $booking->getStartAt()->format('Y-m-d H:i:s');
                $payload['car_date_end'] = $booking->getEndAt()->format('Y-m-d H:i:s');

                // 4. Permis de conduire
                if ($item->getLicenseNumber()) {
                    $payload['car_permit'] = $item->getLicenseNumber();
                }
                if ($item->getLicenseExpirationDate()) {
                    $payload['car_expiration'] = $item->getLicenseExpirationDate()->format('Y-m-d');
                }
            } else {
                // LOGIQUE RETAIL (Standard)
                if ($variant) {
                    $variantId = $variant->getGemsuiteVariantId();
                    if ($variantId) {
                        $gemProductId = (int) $variantId;
                    }
                    if (!$gemProductId && $variant->getProduct()) {
                        $gemProductId = $variant->getProduct()->getGemsuiteProductId();
                    }
                }
            }

            if ($gemProductId) {
                $payload['product_id'] = $gemProductId;

                $this->client->request('POST', $this->gemsuiteApiUrl . 'sales_products', [
                    'auth_bearer' => $token,
                    'json' => $payload,
                ]);
            } else {
                $this->logger->warning("Impossible de trouver un ID GemSuite pour l'item commande " . $item->getId());
            }
        }
    }

    private function finalizeSaleAsInvoice(int $saleId, string $token): void
    {
        $payload = [
            // 'id' => $saleId,
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

        $tenantEm = $this->emProvider->getEntityManager();
        $entreprise = $tenantEm->getRepository(\App\Entity\Entreprise::class)->findOneBy([]);
        $methodId = $entreprise?->getGemsuitePaymentMethodId() ?: 114; // Fallback à 114 par défaut

        $payload = [
            'invoice_id' => $saleId,
            'amount' => $formattedAmount,
            'date_payment' => $payment->getPaymentDate()->format('Y-m-d'),
            'method_id' => $methodId,
        ];

        // if ($stripeRef) {
        //     $payload['reference'] = $stripeRef;
        //     $payload['note'] = "Stripe ID: " . $stripeRef;
        // }

        $response = $this->client->request('POST', $this->gemsuiteApiUrl . 'sales_payments', [
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
