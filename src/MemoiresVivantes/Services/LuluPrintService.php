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
    /**
     * Normalise l'adresse de livraison aux standards postaux requis par Lulu.
     *
     * @param array $raw
     * @return array{
     *     name: string,
     *     street1: string,
     *     street2: string,
     *     city: string,
     *     state_code: string,
     *     country_code: string,
     *     postcode: string,
     *     postal_code: string,
     *     phone_number: string,
     *     contact_email: string
     * }
     */
    public static function normalizeAddress(array $raw): array
    {
        $street1 = trim((string)($raw['street1'] ?? $raw['street'] ?? $raw['address'] ?? $raw['address1'] ?? $raw['line1'] ?? ''));
        $street2 = trim((string)($raw['street2'] ?? $raw['apt'] ?? $raw['suite'] ?? $raw['apartment'] ?? ''));
        $city = trim((string)($raw['city'] ?? $raw['locality'] ?? $raw['town'] ?? ''));

        $rawCountry = strtoupper(trim((string)($raw['country_code'] ?? $raw['country'] ?? 'CA')));
        $countryMap = [
            'CANADA' => 'CA',
            'CAN' => 'CA',
            'ÉTATS-UNIS' => 'US',
            'ETATS-UNIS' => 'US',
            'UNITED STATES' => 'US',
            'USA' => 'US',
            'FRANCE' => 'FR',
            'FRA' => 'FR',
            'ROYAUME-UNI' => 'GB',
            'UNITED KINGDOM' => 'GB',
            'UK' => 'GB',
            'BELGIQUE' => 'BE',
            'BELGIUM' => 'BE',
            'SUISSE' => 'CH',
            'SWITZERLAND' => 'CH',
        ];
        $countryCode = $countryMap[$rawCountry] ?? (strlen($rawCountry) === 2 ? $rawCountry : 'CA');

        $state = trim((string)($raw['state_code'] ?? $raw['state'] ?? $raw['province'] ?? ''));
        if (preg_match('/\(([A-Z]{2})\)/i', $state, $matches)) {
            $state = strtoupper($matches[1]);
        } elseif (strlen($state) > 2 && preg_match('/\b([A-Z]{2})\b/i', $state, $matches)) {
            $state = strtoupper($matches[1]);
        }

        $postcode = trim((string)($raw['postal_code'] ?? $raw['postcode'] ?? $raw['zip'] ?? ''));
        $name = trim((string)($raw['recipient_name'] ?? $raw['name'] ?? 'Destinataire'));
        $phone = trim((string)($raw['phone_number'] ?? $raw['phone'] ?? '+15140000000'));
        $email = trim((string)($raw['contact_email'] ?? $raw['email'] ?? ''));

        // 1. Normalisation code postal canadien : format postal A1A 1A1 (espace obligatoire chez Postes Canada et Lulu)
        if ($countryCode === 'CA') {
            $cleanPost = strtoupper(str_replace([' ', '-'], '', $postcode));
            if (preg_match('/^([A-Z]\d[A-Z])(\d[A-Z]\d)$/', $cleanPost, $m)) {
                $postcode = $m[1] . ' ' . $m[2];
            }
        } elseif (in_array($countryCode, ['FR', 'BE', 'MC'])) {
            $postcode = preg_replace('/[^\d]/', '', $postcode);
        }

        // 2. Extraction automatique de l'appartement / suite si street2 est vide
        if (empty($street2)) {
            if (preg_match('/^(.*?),\s*(.*)$/', $street1, $m)) {
                $street1 = trim($m[1]);
                $street2 = trim($m[2]);
            } elseif (preg_match('/^(.*?[a-zà-ÿ0-9])\s+(?:(?:apt|app|appartement|suite|bureau|unit|chambre|porte|#)\s*([a-z0-9-]+))$/i', $street1, $m)) {
                $street1 = trim($m[1]);
                $street2 = 'Apt ' . trim($m[2]);
            } elseif (preg_match('/^(.*?[a-zà-ÿ])\s+([A-Z]\d{2,4}|\d{1,4}[A-Z])$/i', $street1, $m)) {
                // Correspond à "4000 avenue de la pépinière C307" -> street1: 4000 avenue de la pépinière, street2: C307
                $street1 = trim($m[1]);
                $street2 = trim($m[2]);
            }
        }

        return [
            'name' => $name,
            'street1' => $street1,
            'street2' => $street2,
            'city' => $city,
            'state_code' => $state,
            'country_code' => $countryCode,
            'postcode' => $postcode,
            'postal_code' => $postcode,
            'phone_number' => $phone,
            'contact_email' => $email,
        ];
    }

    /**
     * Valide et adapte le mode de livraison aux niveaux réels acceptés par Lulu selon le pays.
     */
    public static function sanitizeShippingLevel(string $level, string $countryCode): string
    {
        $level = strtoupper(trim($level));
        $countryCode = strtoupper(trim($countryCode));

        if ($countryCode === 'CA') {
            return match ($level) {
                'EXPEDITED' => 'PRIORITY_MAIL', // Lulu rejette EXPEDITED au Canada, PRIORITY_MAIL ou EXPRESS requis
                'GROUND' => 'MAIL',
                'EXPRESS' => 'EXPRESS',
                'PRIORITY_MAIL' => 'PRIORITY_MAIL',
                'MAIL' => 'MAIL',
                default => 'MAIL',
            };
        }

        if (in_array($countryCode, ['FR', 'BE', 'CH', 'LU', 'MC', 'DE', 'GB', 'ES', 'IT'])) {
            return match ($level) {
                'EXPEDITED', 'GROUND' => 'PRIORITY_MAIL',
                'EXPRESS' => 'EXPRESS',
                'PRIORITY_MAIL' => 'PRIORITY_MAIL',
                'MAIL' => 'MAIL',
                default => 'MAIL',
            };
        }

        // US & international
        return match ($level) {
            'MAIL' => 'MAIL',
            'PRIORITY_MAIL' => 'PRIORITY_MAIL',
            'GROUND' => 'GROUND',
            'EXPEDITED' => 'EXPEDITED',
            'EXPRESS' => 'EXPRESS',
            default => 'MAIL',
        };
    }

    /**
     * Calcule le coût d'impression et d'expédition via l'API Lulu (/print-job-cost-calculations/).
     *
     * @param Book $book
     * @param array $shippingAddress
     * @param string $shippingLevel
     * @param int $quantity
     * @param int $pageCount
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
        $normalizedAddress = self::normalizeAddress($shippingAddress);
        $countryCode = $normalizedAddress['country_code'];
        $effectiveShippingLevel = self::sanitizeShippingLevel($shippingLevel, $countryCode);

        $token = $this->getAccessToken();

        // Si l'API Lulu n'est pas configurée ou inaccessible, renvoyer une simulation tarifaire réaliste
        if (!$token) {
            return $this->getSimulatedCostEstimate($normalizedAddress, $effectiveShippingLevel, $quantity, $pageCount);
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
                    'name' => $normalizedAddress['name'],
                    'street1' => $normalizedAddress['street1'],
                    'street2' => $normalizedAddress['street2'],
                    'city' => $normalizedAddress['city'],
                    'state_code' => $normalizedAddress['state_code'],
                    'postcode' => $normalizedAddress['postcode'],
                    'country_code' => $normalizedAddress['country_code'],
                    'phone_number' => $normalizedAddress['phone_number'],
                ],
                'shipping_option' => $effectiveShippingLevel,
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
            $responseBody = null;
            if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                try {
                    $responseBody = $e->getResponse()->getContent(false);
                } catch (\Throwable) {}
            }
            $this->logger->error('LuluPrintService cost calculation error: ' . $e->getMessage(), [
                'exception' => $e,
                'response_body' => $responseBody,
            ]);
            return $this->getSimulatedCostEstimate($normalizedAddress, $effectiveShippingLevel, $quantity, $pageCount);
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
            $countryCode = strtoupper($order->getCountryCode() ?: 'CA');
            $shippingLevel = self::sanitizeShippingLevel($order->getShippingLevel() ?: 'MAIL', $countryCode);

            $payload = [
                'contact_email' => $this->luluContactEmail,
                'external_id' => $order->getId()->toRfc4122(),
                'shipping_level' => $shippingLevel,
                'shipping_address' => [
                    'name' => $order->getRecipientName(),
                    'street1' => $order->getStreet1(),
                    'street2' => $order->getStreet2() ?: '',
                    'city' => $order->getCity(),
                    'state_code' => $order->getState() ?: '',
                    'postcode' => $order->getPostalCode(),
                    'country_code' => $countryCode,
                    'phone_number' => $order->getPhoneNumber() ?: '+15140000000',
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
            $responseBody = null;
            if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                try {
                    $responseBody = $e->getResponse()->getContent(false);
                } catch (\Throwable) {}
            }
            $this->logger->error('LuluPrintService createPrintJob error: ' . $e->getMessage(), [
                'exception' => $e,
                'response_body' => $responseBody,
            ]);
            $order->setStatus('error');
            $order->setErrorMessage($e->getMessage() . ($responseBody ? ' - ' . $responseBody : ''));

            $em = $this->emProvider->getEntityManager();
            $em->flush();

            return [
                'success' => false,
                'error' => $e->getMessage() . ($responseBody ? ' - ' . $responseBody : ''),
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
