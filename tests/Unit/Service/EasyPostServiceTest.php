<?php

namespace App\Tests\Unit\Service;

use App\Entity\EasyPostConfiguration;
use App\Services\ShippingService\EasyPostService;
use App\Services\TenantEntityManagerProvider;
use EasyPost\EasyPostClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface; // Si tu utilises un logger

class EasyPostServiceTest extends TestCase
{
    // --- PARTIE 1 : TEST DE LA CONFIGURATION (BDD -> Clé API) ---

    public function testGetClientThrowsExceptionIfNoConfigFound(): void
    {
        // 1. Mock Doctrine pour simuler l'absence de config
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn(null); // Pas de config

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // 2. Service (Mode Sandbox par défaut)
        $service = new EasyPostService($emProvider, 'dev');

        // 3. Assertion
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Aucune configuration EasyPost trouvée');

        // 4. Action (getClient appelle getTenantClient)
        $service->getClient();
    }

    public function testGetClientReturnsClientWithSandboxKeyInDev(): void
    {
        // 1. Mock Config
        $config = $this->createMock(EasyPostConfiguration::class);
        $config->method('getEasypostApiKeySandbox')->willReturn('TEST_KEY_123');

        // 2. Mock Doctrine
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn($config);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // 3. Service en mode 'dev'
        $service = new EasyPostService($emProvider, 'dev');

        // 4. Action
        $client = $service->getClient();

        // 5. Vérification
        $this->assertInstanceOf(EasyPostClient::class, $client);
        // On ne peut pas facilement lire la clé privée du client, 
        // mais si pas d'exception, c'est que la clé était présente.
    }

    // --- PARTIE 2 : TEST DES MÉTHODES MÉTIER (getRates, buy...) ---
    // Ici, on utilise un "Partial Mock" pour éviter d'utiliser la BDD et new EasyPostClient()

    public function testGetRatesReturnsRatesFromEasyPost(): void
    {
        // 1. Préparation des données API simulées
        $shipmentData = ['to_address' => [], 'from_address' => [], 'parcel' => []];

        $mockRates = [
            (object)['id' => 'rate_1', 'service' => 'Express', 'rate' => '10.00'],
            (object)['id' => 'rate_2', 'service' => 'Standard', 'rate' => '5.00']
        ];

        // Objet Shipment simulé (réponse de create())
        $mockShipment = new \stdClass();
        $mockShipment->rates = $mockRates;
        $mockShipment->messages = [];

        // 2. Mock de la ressource "Shipment" interne au client
        $mockShipmentService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();
        $mockShipmentService->expects($this->once())
            ->method('create')
            ->with($shipmentData)
            ->willReturn($mockShipment);

        // 3. Mock du Client EasyPost complet
        $mockClient = $this->createMock(EasyPostClient::class);
        $mockClient->shipment = $mockShipmentService;

        // 4. PARTIAL MOCK du Service
        // On remplace SEULEMENT getTenantClient pour qu'il renvoie notre faux client
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);

        $service = $this->getMockBuilder(EasyPostService::class)
            ->setConstructorArgs([$emProvider, 'dev'])
            ->onlyMethods(['getTenantClient']) // On mocke uniquement cette méthode protected
            ->getMock();

        $service->method('getTenantClient')->willReturn($mockClient);

        // 5. Exécution
        $rates = $service->getRates($shipmentData);

        // 6. Vérification
        $this->assertCount(2, $rates);
        $this->assertEquals('Express', $rates[0]->service);
    }

    public function testCreateShipmentAndBuyBuysCorrectRate(): void
    {
        // 1. Données
        $shipmentData = ['foo' => 'bar'];
        $carrierId = 'ca_123';
        $serviceName = 'Regular';

        // 2. Simulation des Rates
        $rate1 = (object)['id' => 'r1', 'carrier_account_id' => 'ca_999', 'service' => 'Express', 'rate' => '20.00', 'currency' => 'CAD'];
        $rate2 = (object)['id' => 'r2', 'carrier_account_id' => 'ca_123', 'service' => 'Regular', 'rate' => '15.50', 'currency' => 'CAD']; // Celui qu'on veut

        $mockShipment = new \stdClass();
        $mockShipment->id = 'shp_123';
        $mockShipment->rates = [$rate1, $rate2];

        // Simulation de la réponse "Buy"
        $mockBoughtShipment = new \stdClass();
        $mockBoughtShipment->postage_label = (object)['label_url' => 'http://label.pdf'];
        $mockBoughtShipment->tracking_code = 'TRACK123';

        // 3. Mock Shipment Service
        $mockShipmentService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create', 'buy'])
            ->getMock();

        // Expect Create
        $mockShipmentService->expects($this->once())
            ->method('create')
            ->willReturn($mockShipment);

        // Expect Buy (avec le bon ID de rate !)
        $mockShipmentService->expects($this->once())
            ->method('buy')
            ->with('shp_123', ['rate' => ['id' => 'r2']]) // Vérifie qu'on achète le bon
            ->willReturn($mockBoughtShipment);

        // 4. Mock Client
        $mockClient = $this->createMock(EasyPostClient::class);
        $mockClient->shipment = $mockShipmentService;

        // 5. Partial Mock Service
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $service = $this->getMockBuilder(EasyPostService::class)
            ->setConstructorArgs([$emProvider, 'dev'])
            ->onlyMethods(['getTenantClient'])
            ->getMock();

        $service->method('getTenantClient')->willReturn($mockClient);

        // 6. Exécution
        $result = $service->createShipmentAndBuy($shipmentData, $carrierId, $serviceName);

        // 7. Assertion
        $this->assertEquals('http://label.pdf', $result['label_url']);
        $this->assertEquals('TRACK123', $result['tracking_code']);
    }

    public function testTrackReturnsStatusHistory(): void
    {
        // 1. Simulation réponse
        $mockTracker = new \stdClass();
        $mockTracker->status_history = [
            ['status' => 'pre_transit', 'location' => 'Warehouse'],
            ['status' => 'in_transit', 'location' => 'Truck']
        ];

        // 2. Mock Tracker Service
        $mockTrackerService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();

        $mockTrackerService->expects($this->once())
            ->method('create')
            ->with(['tracking_code' => '12345'])
            ->willReturn($mockTracker);

        // 3. Mock Client
        $mockClient = $this->createMock(EasyPostClient::class);
        $mockClient->tracker = $mockTrackerService;

        // 4. Partial Mock
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $service = $this->getMockBuilder(EasyPostService::class)
            ->setConstructorArgs([$emProvider, 'dev'])
            ->onlyMethods(['getTenantClient'])
            ->getMock();

        $service->method('getTenantClient')->willReturn($mockClient);

        // 5. Action
        $history = $service->track('12345');

        // 6. Assertion
        $this->assertCount(2, $history);
        $this->assertEquals('pre_transit', $history[0]['status']);
    }
}
