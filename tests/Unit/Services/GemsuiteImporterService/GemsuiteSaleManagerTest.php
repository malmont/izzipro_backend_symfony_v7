<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\GemsuiteClient;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\Payments;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\GemsuiteImporterService\GemsuiteSaleManager;
use App\Services\TenantConnectionManager;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GemsuiteSaleManagerTest extends TestCase
{
    private $client;
    private $tenantManager;
    private $logger;
    private $gemsuiteApiUrl = 'https://api.example.com/';
    private $emProvider;
    private $em;
    private $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->emProvider = $this->createMock(\App\Services\TenantEntityManagerProvider::class);
        $this->em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->em);

        $this->manager = new GemsuiteSaleManager(
            $this->client,
            $this->tenantManager,
            $this->logger,
            $this->gemsuiteApiUrl,
            $this->emProvider
        );
    }

    public function testCreateSaleReturnsNullIfNoGemsuiteClientId(): void
    {
        $order = $this->createMock(Order::class);
        $user = $this->createMock(User::class);
        $order->method('getUserId')->willReturn($user);

        // Mock User returning null for everything
        $user->method('getGemsuiteClient')->willReturn(null);
        $user->method('getGemsuiteClientId')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Client GEM-SUITE manquant'));

        $this->assertNull($this->manager->createSale($order));
    }

    public function testCreateSaleReturnsNullIfNoToken(): void
    {
        $order = $this->createMock(Order::class);
        $user = $this->createMock(User::class);
        $order->method('getUserId')->willReturn($user);

        $user->method('getGemsuiteClientId')->willReturn(12345);

        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT');
        $this->tenantManager->method('getTenantToken')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Token manquant'));

        $this->assertNull($this->manager->createSale($order));
    }

    public function testCreateSaleFullSuccess(): void
    {
        $order = $this->createMock(Order::class);
        $user = $this->createMock(User::class);
        $order->method('getUserId')->willReturn($user);
        $order->method('getId')->willReturn(100);
        $order->method('getOrderDate')->willReturn(new \DateTime('2024-01-01'));
        $order->method('getReference')->willReturn('REF-100');
        $order->method('getShippingCost')->willReturn(1500.0); // 15.00

        $user->method('getGemsuiteClientId')->willReturn(12345);

        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT');
        $this->tenantManager->method('getTenantToken')->willReturn('valid_token');

        // Payment Mock
        $payment = $this->createMock(Payments::class);
        $payment->method('getStripePaymentId')->willReturn('pi_123');
        $payment->method('getAmount')->willReturn(5000.0); // 50.00
        $payment->method('getPaymentDate')->willReturn(new \DateTime('2024-01-01'));

        $order->method('getPayments')->willReturn(new ArrayCollection([$payment]));

        // Order Items Mock
        $variant = $this->createMock(ProductVariant::class);
        $variant->method('getGemsuiteVariantId')->willReturn('999');

        $item = $this->createMock(OrderItems::class);
        $item->method('getProductVariant')->willReturn($variant);
        $item->method('getQuantity')->willReturn(2);
        $item->method('getUnitPrice')->willReturn(2500.0); // 25.00

        $order->method('getOrderItems')->willReturn(new ArrayCollection([$item]));

        // --- Mock Entreprise for Payment Method ID ---
        $entreprise = $this->createMock(\App\Entity\Entreprise::class);
        $entreprise->method('getGemsuitePaymentMethodId')->willReturn(114);
        $entRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $entRepo->method('findOneBy')->willReturn($entreprise);
        $this->em->method('getRepository')->with(\App\Entity\Entreprise::class)->willReturn($entRepo);

        // --- HTTP Mocks for sequential calls ---

        // 1. Create Sale Shell
        $responseCreate = $this->createMock(ResponseInterface::class);
        $responseCreate->method('getStatusCode')->willReturn(201);
        $responseCreate->method('toArray')->willReturn(['data' => ['id' => 500]]);

        // 2. Add Product (called once)
        $responseAddProd = $this->createMock(ResponseInterface::class); // status not checked in logic but request made

        // 3. Finalize Invoice
        $responseInvoice = $this->createMock(ResponseInterface::class);
        $responseInvoice->method('getStatusCode')->willReturn(200);

        // 4. Create Payment
        $responsePayment = $this->createMock(ResponseInterface::class);
        $responsePayment->method('getStatusCode')->willReturn(201);

        $matcher = $this->exactly(4);
        $this->client->expects($matcher)
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($matcher, $responseCreate, $responseAddProd, $responseInvoice, $responsePayment) {
                $invocation = $matcher->getInvocationCount();

                if ($invocation === 1) { // Create Sale
                    return $responseCreate;
                }
                if ($invocation === 2) { // Add Product
                    return $responseAddProd;
                }
                if ($invocation === 3) { // Invoice
                    return $responseInvoice;
                }
                if ($invocation === 4) { // Payment
                    return $responsePayment;
                }
                return null;
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->manager->createSale($order);

        $this->assertEquals(['id' => 500], $result);
    }

    public function testCreateSaleHandlesException(): void
    {
        $order = $this->createMock(Order::class);
        $user = $this->createMock(User::class);
        $order->method('getUserId')->willReturn($user);
        $user->method('getGemsuiteClientId')->willReturn(12345);
        $order->method('getOrderDate')->willReturn(new \DateTime());

        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT');
        $this->tenantManager->method('getTenantToken')->willReturn('valid_token');

        // Fail on first API call
        $this->client->method('request')->willThrowException(new \Exception('API Fail'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Erreur critique synchro GEM-SUITE'));

        $this->assertNull($this->manager->createSale($order));
    }
}
