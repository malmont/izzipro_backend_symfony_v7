<?php

namespace App\Tests\Unit\Controller\ShippingController;

use PHPUnit\Framework\TestCase;
use App\Controller\ShippingController\ShippingController;
use App\UseCase\ShippingUseCase\GetShippingRatesForCart;
use App\UseCase\ShippingUseCase\GetParcelSummariesForCart;
use App\UseCase\ShippingUseCase\PurchaseShippingForCart;
use App\UseCase\ShippingUseCase\GetShippingSummaryForCart;
use App\Exception\ItemTooLargeForPackagingException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Dto\CartItemDto;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ShippingControllerTest extends TestCase
{
    private $getRatesForCart;
    private $getParcelSummariesForCart;
    private $purchaseShippingForCart;
    private $getShippingSummaryForCart;
    private $logger;
    private $controller;
    private $container;

    protected function setUp(): void
    {
        $this->getRatesForCart = $this->createMock(GetShippingRatesForCart::class);
        $this->getParcelSummariesForCart = $this->createMock(GetParcelSummariesForCart::class);
        $this->purchaseShippingForCart = $this->createMock(PurchaseShippingForCart::class);
        $this->getShippingSummaryForCart = $this->createMock(GetShippingSummaryForCart::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ShippingController(
            $this->getRatesForCart,
            $this->getParcelSummariesForCart,
            $this->purchaseShippingForCart,
            $this->getShippingSummaryForCart,
            $this->logger
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testRatesSuccess(): void
    {
        $payload = [
            'cartItems' => [['productId' => 1, 'quantity' => 2]],
            'to' => ['country' => 'FR'],
            'from' => ['country' => 'US'],
            'carriers' => ['UPS']
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->getRatesForCart->expects($this->once())
            ->method('execute')
            ->with(
                $this->isType('array'),
                ['country' => 'FR'],
                ['country' => 'US'],
                ['UPS']
            )
            ->willReturn(['rate' => 100]);

        $response = $this->controller->rates($request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(['rate' => 100], $content);
    }

    public function testRatesItemTooLargeException(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([]));

        $this->getRatesForCart->expects($this->once())
            ->method('execute')
            ->willThrowException(new ItemTooLargeForPackagingException('Item too large'));

        $response = $this->controller->rates($request);

        $this->assertEquals(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Item too large', $content['error']);
    }

    public function testRatesGeneralException(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([]));

        $this->getRatesForCart->expects($this->once())
            ->method('execute')
            ->willThrowException(new \Exception('Server error'));

        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->rates($request);

        $this->assertEquals(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Une erreur serveur est survenue lors du calcul des tarifs.', $content['error']);
    }

    public function testParcelsSuccess(): void
    {
        $payload = ['cartItems' => [['productId' => 1, 'quantity' => 1]]];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->getParcelSummariesForCart->expects($this->once())
            ->method('execute')
            ->with($this->isType('array'))
            ->willReturn(['parcel' => 'box']);

        $response = $this->controller->parcels($request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(['parcel' => 'box'], $content);
    }

    public function testParcelsException(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([]));

        $this->getParcelSummariesForCart->expects($this->once())
            ->method('execute')
            ->willThrowException(new \Exception('Server error'));

        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->parcels($request);

        $this->assertEquals(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testBuySuccess(): void
    {
        $payload = [
            'cartItems' => [['productId' => 1, 'quantity' => 1]],
            'orderId' => 123,
            'to' => [],
            'from' => [],
            'carrierAccountId' => 'acc_123',
            'service' => 'Ground'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->purchaseShippingForCart->expects($this->once())
            ->method('execute')
            ->with(
                $this->isType('array'),
                [],
                [],
                'acc_123',
                'Ground',
                123
            )
            ->willReturn(['label' => 'url']);

        $response = $this->controller->buy($request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }

    public function testSummarySuccess(): void
    {
        $payload = [
            'cartItems' => [['productId' => 1, 'quantity' => 1]],
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->getShippingSummaryForCart->expects($this->once())
            ->method('execute')
            ->willReturn(['summary' => 'data']);

        $response = $this->controller->summary($request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }
}
