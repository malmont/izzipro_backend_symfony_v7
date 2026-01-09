<?php

namespace App\Tests\Unit\Services\ShippingService;

use App\Services\ShippingService\EasyPostService;
use App\Services\TenantEntityManagerProvider;
use EasyPost\EasyPostClient;
use EasyPost\Service\ShipmentService;
use EasyPost\Service\TrackerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EasyPostServiceTest extends TestCase
{
    private $emProvider;
    private $service;
    private $easyPostClient;
    private $shipmentService;
    private $trackerService;
    private $logger;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);

        // Mock des services internes d'EasyPost
        $this->shipmentService = $this->createMock(ShipmentService::class);
        $this->trackerService = $this->createMock(TrackerService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Mock du client EasyPost
        $this->easyPostClient = $this->createMock(EasyPostClient::class);

        // Injection des services dans le client (propriétés publiques ou magiques simulées)
        $this->easyPostClient->shipment = $this->shipmentService;
        $this->easyPostClient->tracker = $this->trackerService;

        // Mock partiel du service pour surcharger la création du client
        $this->service = $this->getMockBuilder(EasyPostService::class)
            ->setConstructorArgs([$this->emProvider, 'test', $this->logger])
            ->onlyMethods(['getTenantClient'])
            ->getMock();

        $this->service->method('getTenantClient')->willReturn($this->easyPostClient);
    }

    public function testGetRates(): void
    {
        $data = ['to_address' => [], 'from_address' => [], 'parcel' => []];

        $shipmentMock = new \stdClass();
        $shipmentMock->messages = [];
        $shipmentMock->rates = [
            (object)['id' => 'rate_1', 'carrier' => 'FedEx', 'rate' => '10.00'],
            (object)['id' => 'rate_2', 'carrier' => 'UPS', 'rate' => '12.00'],
        ];

        $this->shipmentService->expects($this->once())
            ->method('create')
            ->with($data)
            ->willReturn($shipmentMock);

        $rates = $this->service->getRates($data);

        $this->assertCount(2, $rates);
        $this->assertEquals('rate_1', $rates[0]->id);
    }

    public function testCreateShipmentAndBuy(): void
    {
        $data = ['some' => 'data'];
        $carrierAccountId = 'ca_123';
        $serviceName = 'Standard';

        $rateToBuy = (object)[
            'id' => 'rate_found',
            'carrier_account_id' => $carrierAccountId,
            'service' => $serviceName,
            'rate' => '15.50',
            'currency' => 'USD'
        ];

        $shipmentMock = new \stdClass();
        $shipmentMock->id = 'shp_123';
        $shipmentMock->rates = [$rateToBuy];
        $shipmentMock->messages = [];

        // Mocking the buy response
        $boughtShipment = new \stdClass();
        $boughtShipment->tracking_code = '1234567890';
        $boughtShipment->postage_label = (object)['label_url' => 'http://example.com/label.pdf'];

        $this->shipmentService->expects($this->once())
            ->method('create')
            ->willReturn($shipmentMock);

        $this->shipmentService->expects($this->once())
            ->method('buy')
            ->with('shp_123', ['rate' => ['id' => 'rate_found']])
            ->willReturn($boughtShipment);

        $result = $this->service->createShipmentAndBuy($data, $carrierAccountId, $serviceName);

        $this->assertEquals('1234567890', $result['tracking_code']);
        $this->assertEquals('http://example.com/label.pdf', $result['label_url']);
        $this->assertEquals('15.50', $result['price']);
    }

    public function testTrack(): void
    {
        $trackingCode = 'EZ1000000001';

        $trackerMock = new \stdClass();
        $trackerMock->status_history = [
            (object)['status' => 'pre_transit', 'message' => 'Label created'],
            (object)['status' => 'in_transit', 'message' => 'On the way']
        ];

        $this->trackerService->expects($this->once())
            ->method('create')
            ->with(['tracking_code' => $trackingCode])
            ->willReturn($trackerMock);

        $history = $this->service->track($trackingCode);

        $this->assertCount(2, $history);
        $this->assertEquals('pre_transit', $history[0]->status);
    }
}
