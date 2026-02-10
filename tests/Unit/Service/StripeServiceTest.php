<?php

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use App\Services\StripeService\StripeService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use App\Services\EntityRetrieverService;
use App\UseCase\OrderUseCase\CalculateTaxesUseCase;
use App\Entity\ProductVariant;
use App\Entity\Product;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;

class StripeServiceTest extends TestCase
{
    public function testCreatePaymentIntentFromItemsCalculatesCorrectly()
    {
        $stripeSecretKey = 'sk_test_123';
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $connectionManager = $this->createMock(TenantConnectionManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $entityRetrieverService = $this->createMock(EntityRetrieverService::class);
        $calculateTaxesUseCase = $this->createMock(CalculateTaxesUseCase::class);

        // Mock EntityManager (needed for constructor but accessed via provider in class)
        $em = $this->createMock(EntityManagerInterface::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // Create partial mock to mock the protected/private createPaymentIntent method if possible,
        // OR mock the Stripe API call.
        // Since createPaymentIntent is public (from original class), we can mock it if we partial mock the service.
        // But better: testing the public method CreatePaymentIntentFromItems which calls createPaymentIntent.
        // createPaymentIntent does real Stripe calls or needs extensive mocking of Stripe library calls (Stripe::setApiKey, PaymentIntent::create).
        // To avoid making real calls, we should probably partial mock StripeService to specifically mock `createPaymentIntent`.

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([
                $stripeSecretKey,
                $emProvider,
                $connectionManager,
                $logger,
                $entityRetrieverService,
                $calculateTaxesUseCase
            ])
            ->onlyMethods(['createPaymentIntent'])
            ->getMock();

        // Data
        $items = [['productVariantId' => 1, 'quantity' => 2]];
        $priceShipping = 10.0;

        // Mock Entity Retrieval
        $product = $this->createMock(Product::class);
        $product->method('getPrice')->willReturn(50.0);

        $productVariant = $this->createMock(ProductVariant::class);
        $productVariant->method('getProduct')->willReturn($product);

        $entityRetrieverService->method('findOrFail')
            ->with(ProductVariant::class, 1, 'Product variant not found')
            ->willReturn($productVariant);

        // Mock Tax Calculation
        // Subtotal = 100 + 10 = 110
        $calculateTaxesUseCase->method('execute')
            ->willReturn(5.0); // Total = 115.0

        // Expect createPaymentIntent to be called with 115 (since we treat input as cents now)
        $service->expects($this->once())
            ->method('createPaymentIntent')
            ->with(115, 'cad')
            ->willReturn('client_secret_abc');

        $result = $service->createPaymentIntentFromItems($items, $priceShipping);

        $this->assertTrue($result['success']);
        $this->assertEquals('client_secret_abc', $result['clientSecret']);
        $this->assertEquals(115.0, $result['calculatedAmount']);
    }
}
