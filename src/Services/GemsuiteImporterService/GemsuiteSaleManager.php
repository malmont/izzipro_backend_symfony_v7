<?php


namespace App\Services\GemsuiteImporterService;

use App\Entity\Order;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;

class GemsuiteSaleManager
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Orchestre la création d'une vente complète dans GEM-SUITE.
     *
     * @param Order $order La commande créée dans Iizipro.
     * @return array|null Les données de la vente créée, ou null en cas d'erreur.
     */
    public function createSale(Order $order): ?array
    {
        $user = $order->getUserId();
        if (!$user) {
            $this->logger->error(sprintf('La commande #%d n\'a pas d\'utilisateur associé.', $order->getId()));
            return null;
        }
        
        $gemsuiteClientId = $user->getGemsuiteClientId();
        if (!$gemsuiteClientId) {
            $this->logger->warning(sprintf('L\'utilisateur %s n\'a pas de client GEM-SUITE associé.', $user->getEmail()));
            return null;
        }

        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Aucun token pour le tenant "%s", impossible de créer la vente.', $tenantCode));
            return null;
        }

        try {
            $saleData = $this->createSaleShell($gemsuiteClientId, $order->getOrderDate(), $token);
            $saleId = $saleData['id'] ?? null;

            if (!$saleId) {
                $this->logger->error('La création de la vente sur GEM-SUITE a réussi mais aucun ID n\'a été retourné.');
                return null;
            }
            $this->logger->info(sprintf('Vente #%d créée avec succès sur GEM-SUITE.', $saleId));
            $this->addProductsToSale($order, $saleId, $token);
            $this->createPaymentForSale($order, $saleId, $token);

            return $saleData;

        } catch (\Throwable $e) {
            $this->logger->error('Une erreur est survenue lors du processus de création de la vente sur GEM-SUITE : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Étape 1 : Crée la vente de base.
     */
    private function createSaleShell(int $clientId, \DateTimeInterface $date, string $token): ?array
    {
        $salePayload = [
            'client_id' => (string) $clientId,
            'date' => $date->format('Y-m-d'),
        ];

        $response = $this->client->request('POST', self::GEMSUITE_API_URL . 'sales', [
            'auth_bearer' => $token,
            'json' => $salePayload,
        ]);

        if (!in_array($response->getStatusCode(), [200, 201])) {
            $this->logger->error('API Error while creating sale shell', [
                'status_code' => $response->getStatusCode(),
                'response' => $response->getContent(false),
            ]);
            throw new \Exception('Impossible de créer la vente de base sur GEM-SUITE.');
        }

        return $response->toArray()['data'] ?? null;
    }

    /**
     * Étape 2 : Ajoute les lignes de produits à une vente existante.
     */
    private function addProductsToSale(Order $order, int $saleId, string $token): void
    {
        foreach ($order->getOrderItems() as $item) {
            $product = $item->getProductVariant() ? $item->getProductVariant()->getProduct() : null;
            if ($product && $product->getGemsuiteProductId()) {
                $productPayload = [
                    'sale_id' => $saleId,
                    'product_id' => $product->getGemsuiteProductId(),
                    'product_quantity' => $item->getQuantity(),
                    'product_price' => $item->getUnitPrice() / 100,
                ];

                $this->client->request('POST', self::GEMSUITE_API_URL . 'sales_products', [
                    'auth_bearer' => $token,
                    'json' => $productPayload,
                ]);
                $this->logger->info(sprintf('Produit #%d ajouté à la vente #%d.', $product->getGemsuiteProductId(), $saleId));
            }
        }
    }

    /**
     * Étape 3 : Crée un paiement et l'associe à une vente.
     */
    private function createPaymentForSale(Order $order, int $saleId, string $token): void
    {
        $payment = $order->getPayments()->first();
        if (!$payment) {
            $this->logger->info(sprintf('Aucun paiement à synchroniser pour la vente #%d.', $saleId));
            return;
        }

        $paymentPayload = [
            'invoice_id' => $saleId,
            'amount' => $payment->getAmount() / 100,
            'date_payment' => $payment->getPaymentDate()->format('Y-m-d'),
            // NOTE: L'ID de la méthode de paiement doit être mappé.
            // Pour l'instant, on utilise une valeur par défaut (ex: 1 pour "Carte de crédit").
            'method_id' => 1, 
        ];

        $response = $this->client->request('POST', self::GEMSUITE_API_URL . 'sales_payment', [
            'auth_bearer' => $token,
            'json' => $paymentPayload,
        ]);

        if (in_array($response->getStatusCode(), [200, 201])) {
            $this->logger->info(sprintf('Paiement pour la vente #%d synchronisé avec succès.', $saleId));
        } else {
            $this->logger->warning(sprintf('La vente #%d a été créée, mais la synchronisation du paiement a échoué.', $saleId), [
                'status_code' => $response->getStatusCode(),
                'response' => $response->getContent(false),
            ]);
        }
    }
}
