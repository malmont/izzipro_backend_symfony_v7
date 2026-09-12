<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LuluPrintService
{
    private ?string $cachedToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $luluClientKey = null,
        private readonly ?string $luluClientSecret = null,
        private readonly ?string $luluApiUrl = 'https://api.sandbox.lulu.com',
        private readonly ?string $luluAuthUrl = 'https://api.sandbox.lulu.com/auth/realms/glasstree/protocol/openid-connect/token',
        private readonly ?string $luluContactEmail = 'contact@memoiresvivantes.com',
        private readonly ?string $luluDefaultPodPackageId = '0827X1169.FC.STD.CW.080CW444.MXX'
    ) {}

    /**
     * Vérifie si les identifiants API Lulu sont configurés.
     */
    public function isConfigured(): bool
    {
        return !empty($this->luluClientKey) && !empty($this->luluClientSecret);
    }

    /**
     * Récupère ou rafraîchit le Bearer Token OAuth2 auprès de Lulu.
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isConfigured()) {
            $this->logger->info('LuluPrintService: Identifiants Lulu non configurés. Mode simulation actif.');
            return null;
        }

        // Si le token en cache est encore valide (avec 60s de marge de sécurité)
        if ($this->cachedToken && time() < ($this->tokenExpiresAt - 60)) {
            return $this->cachedToken;
        }

        try {
            $basicAuth = base64_encode($this->luluClientKey . ':' . $this->luluClientSecret);

            $response = $this->httpClient->request('POST', $this->luluAuthUrl, [
                'headers' => [
                    'Authorization' => 'Basic ' . $basicAuth,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'grant_type=client_credentials',
                'timeout' => 15,
            ]);

            $data = $response->toArray();
            if (isset($data['access_token'])) {
                $this->cachedToken = $data['access_token'];
                $expiresIn = (int)($data['expires_in'] ?? 3600);
                $this->tokenExpiresAt = time() + $expiresIn;
                return $this->cachedToken;
            }

            $this->logger->error('LuluPrintService: Échec de récupération du token OAuth', ['response' => $data]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('LuluPrintService OAuth Error: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Calcule dynamiquement les dimensions de la couverture via l'API Lulu.
     *
     * @return array{width: float, height: float, unit: string}|null
     */
    public function getCoverDimensions(int $pageCount, ?string $podPackageId = null): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $packageId = $podPackageId ?: $this->luluDefaultPodPackageId;

        try {
            $response = $this->httpClient->request('POST', rtrim($this->luluApiUrl, '/') . '/cover-dimensions/', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'pod_package_id' => $packageId,
                    'interior_page_count' => $pageCount,
                    'unit' => 'pt',
                ],
                'timeout' => 15,
            ]);

            $data = $response->toArray();
            if (isset($data['width'], $data['height'])) {
                return [
                    'width' => (float)$data['width'],
                    'height' => (float)$data['height'],
                    'unit' => $data['unit'] ?? 'pt',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('LuluPrintService: Impossible de récupérer les dimensions de couverture depuis l\'API: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Calcule le coût d'impression, d'expédition et les taxes auprès de Lulu (/print-job-cost-calculations/).
     *
     * @param array{
     *   name?: string,
     *   street1: string,
     *   street2?: string,
     *   city: string,
     *   state_code?: string,
     *   postcode: string,
     *   country_code: string,
     *   phone_number?: string,
     *   email?: string
     * } $shippingAddress
     *
     * @return array{
     *   print_cost: string,
     *   shipping_cost: string,
     *   tax_cost: string,
     *   total_cost: string,
     *   currency: string,
     *   is_simulated: bool,
     *   details?: array
     * }
     */
    public function calculatePrintCost(
        Book $book,
        array $shippingAddress,
        string $shippingLevel = 'MAIL',
        int $quantity = 1,
        int $pageCount = 64
    ): array {
        $token = $this->getAccessToken();

        // Si l'API Lulu n'est pas configurée ou inaccessible, renvoyer une simulation tarifaire réaliste
        if (!$token) {
            return $this->getSimulatedCostEstimate($shippingAddress, $shippingLevel, $quantity, $pageCount);
        }

        try {
            $payload = [
                'line_items' => [
                    [
                        'page_count' => $pageCount,
                        'pod_package_id' => $this->luluDefaultPodPackageId,
                        'quantity' => $quantity,
                    ]
                ],
                'shipping_address' => [
                    'name' => $shippingAddress['name'] ?? 'Destinataire',
                    'street1' => $shippingAddress['street1'],
                    'street2' => $shippingAddress['street2'] ?? '',
                    'city' => $shippingAddress['city'],
                    'state_code' => $shippingAddress['state_code'] ?? '',
                    'postcode' => $shippingAddress['postcode'] ?? $shippingAddress['postal_code'] ?? '',
                    'country_code' => strtoupper($shippingAddress['country_code'] ?? 'CA'),
                    'phone_number' => $shippingAddress['phone_number'] ?? '+15140000000',
                ],
                'shipping_option' => strtoupper($shippingLevel),
            ];

            $response = $this->httpClient->request('POST', rtrim($this->luluApiUrl, '/') . '/print-job-cost-calculations/', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 20,
            ]);

            $data = $response->toArray();

            $printCost = $data['total_cost_excl_tax'] ?? '0.00';
            $shippingCost = $data['shipping_cost']['total_cost_excl_tax'] ?? '0.00';
            $taxCost = $data['total_tax'] ?? '0.00';
            $totalCost = $data['total_cost_incl_tax'] ?? '0.00';
            $currency = $data['currency'] ?? 'CAD';

            return [
                'print_cost' => number_format((float)$printCost, 2, '.', ''),
                'shipping_cost' => number_format((float)$shippingCost, 2, '.', ''),
                'tax_cost' => number_format((float)$taxCost, 2, '.', ''),
                'total_cost' => number_format((float)$totalCost, 2, '.', ''),
                'currency' => $currency,
                'is_simulated' => false,
                'details' => $data,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('LuluPrintService cost calculation error: ' . $e->getMessage(), ['exception' => $e]);
            return $this->getSimulatedCostEstimate($shippingAddress, $shippingLevel, $quantity, $pageCount);
        }
    }

    /**
     * Crée un travail d'impression chez Lulu (/print-jobs/).
     */
    public function createPrintJob(
        BookPrintOrder $order,
        string $interiorPublicUrl,
        string $coverPublicUrl
    ): array {
        $token = $this->getAccessToken();

        // En mode simulation
        if (!$token) {
            $simulatedJobId = 'SIM-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $order->setLuluPrintJobId($simulatedJobId);
            $order->setStatus('created');
            $order->setInteriorPdfUrl($interiorPublicUrl);
            $order->setCoverPdfUrl($coverPublicUrl);
            $order->setLuluRawResponse([
                'id' => $simulatedJobId,
                'status' => 'CREATED',
                'mode' => 'SIMULATED',
                'created_at' => (new \DateTime())->format(\DateTime::ATOM),
            ]);

            $em = $this->emProvider->getEntityManager();
            $em->flush();

            return [
                'success' => true,
                'job_id' => $simulatedJobId,
                'status' => 'CREATED',
                'is_simulated' => true,
            ];
        }

        try {
            $book = $order->getBook();
            $payload = [
                'contact_email' => $this->luluContactEmail,
                'external_id' => $order->getId()->toRfc4122(),
                'shipping_level' => $order->getShippingLevel(),
                'shipping_address' => [
                    'name' => $order->getRecipientName(),
                    'street1' => $order->getStreet1(),
                    'street2' => $order->getStreet2() ?: '',
                    'city' => $order->getCity(),
                    'state_code' => $order->getState() ?: '',
                    'postcode' => $order->getPostalCode(),
                    'country_code' => $order->getCountryCode(),
                    'phone_number' => $order->getPhoneNumber() ?: '+33100000000',
                ],
                'line_items' => [
                    [
                        'external_id' => $book ? $book->getId()->toRfc4122() : 'item-1',
                        'title' => $book ? $book->getTitle() : 'Mémoires Vivantes',
                        'quantity' => $order->getQuantity(),
                        'pod_package_id' => $this->luluDefaultPodPackageId,
                        'interior' => [
                            'source_url' => $interiorPublicUrl,
                        ],
                        'cover' => [
                            'source_url' => $coverPublicUrl,
                        ],
                    ]
                ],
            ];

            $response = $this->httpClient->request('POST', rtrim($this->luluApiUrl, '/') . '/print-jobs/', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 30,
            ]);

            $data = $response->toArray();
            $jobId = (string)($data['id'] ?? '');

            $order->setLuluPrintJobId($jobId);
            $order->setStatus('created');
            $order->setInteriorPdfUrl($interiorPublicUrl);
            $order->setCoverPdfUrl($coverPublicUrl);
            $order->setLuluRawResponse($data);

            $em = $this->emProvider->getEntityManager();
            $em->flush();

            return [
                'success' => true,
                'job_id' => $jobId,
                'status' => $data['status']['name'] ?? 'CREATED',
                'is_simulated' => false,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('LuluPrintService createPrintJob error: ' . $e->getMessage(), ['exception' => $e]);
            $order->setStatus('error');
            $order->setErrorMessage($e->getMessage());

            $em = $this->emProvider->getEntityManager();
            $em->flush();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Interroge l'état d'un print job chez Lulu (/print-jobs/{id}/).
     */
    public function getPrintJobStatus(string $luluJobId): ?array
    {
        $token = $this->getAccessToken();
        if (!$token || str_starts_with($luluJobId, 'SIM-')) {
            return [
                'id' => $luluJobId,
                'status' => ['name' => 'IN_PRODUCTION'],
                'tracking_urls' => [],
                'carrier' => 'Simulation Post',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($this->luluApiUrl, '/') . '/print-jobs/' . $luluJobId . '/', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('LuluPrintService getPrintJobStatus error: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Synchronise le statut et les coordonnées de tracking d'une commande.
     */
    public function syncOrderStatus(BookPrintOrder $order): BookPrintOrder
    {
        $jobId = $order->getLuluPrintJobId();
        if (!$jobId) {
            return $order;
        }

        $data = $this->getPrintJobStatus($jobId);
        if (!$data) {
            return $order;
        }

        $order->setLuluRawResponse($data);

        $statusName = strtoupper($data['status']['name'] ?? '');
        switch ($statusName) {
            case 'CREATED':
            case 'UNPAID':
                $order->setStatus('created');
                break;
            case 'ACCEPTED':
            case 'PRODUCTION_READY':
            case 'IN_PRODUCTION':
                $order->setStatus('in_production');
                break;
            case 'SHIPPED':
                $order->setStatus('shipped');
                if (!$order->getShippedAt()) {
                    $order->setShippedAt(new \DateTime());
                }
                break;
            case 'CANCELED':
                $order->setStatus('canceled');
                break;
            case 'ERROR':
            case 'REJECTED':
                $order->setStatus('error');
                $order->setErrorMessage($data['status']['message'] ?? 'Erreur signalée par Lulu');
                break;
        }

        // Extraction tracking si disponible
        if (!empty($data['line_items'][0]['tracking_urls'][0])) {
            $order->setTrackingUrl($data['line_items'][0]['tracking_urls'][0]);
        }
        if (!empty($data['line_items'][0]['carrier'])) {
            $order->setCarrierName($data['line_items'][0]['carrier']);
        }
        if (!empty($data['line_items'][0]['tracking_id'])) {
            $order->setTrackingNumber($data['line_items'][0]['tracking_id']);
        }

        $em = $this->emProvider->getEntityManager();
        $em->flush();

        return $order;
    }

    /**
     * Calcul réaliste d'estimation hors-ligne pour tests et développements.
     */
    private function getSimulatedCostEstimate(
        array $shippingAddress,
        string $shippingLevel,
        int $quantity,
        int $pageCount
    ): array {
        // Base hardcover casewrap A4 : ~27.50 CAD + 0.08 CAD par page au-delà de 32
        $unitPrint = 27.50 + max(0, $pageCount - 32) * 0.08;
        $printCost = $unitPrint * $quantity;

        // Tarifs moyens d'expédition au Canada / International (en CAD)
        $shippingRates = [
            'MAIL' => 8.50,
            'PRIORITY_MAIL' => 14.50,
            'GROUND' => 12.00,
            'EXPEDITED' => 18.00,
            'EXPRESS' => 28.00,
        ];
        $baseShipping = $shippingRates[strtoupper($shippingLevel)] ?? 8.50;
        $shippingCost = $baseShipping + max(0, $quantity - 1) * 3.00;

        $country = strtoupper($shippingAddress['country_code'] ?? 'CA');
        // Taxes canadiennes (TPS 5% + TVQ si QC) ou internationales
        $taxRate = ($country === 'CA') ? 0.05 : (in_array($country, ['FR', 'BE', 'MC']) ? 0.055 : 0.00);
        $taxCost = ($printCost + $shippingCost) * $taxRate;
        $totalCost = $printCost + $shippingCost + $taxCost;

        $currency = in_array($country, ['US']) ? 'USD' : (in_array($country, ['FR', 'BE', 'DE']) ? 'EUR' : 'CAD');

        return [
            'print_cost' => number_format($printCost, 2, '.', ''),
            'shipping_cost' => number_format($shippingCost, 2, '.', ''),
            'tax_cost' => number_format($taxCost, 2, '.', ''),
            'total_cost' => number_format($totalCost, 2, '.', ''),
            'currency' => $currency,
            'is_simulated' => true,
        ];
    }
}
