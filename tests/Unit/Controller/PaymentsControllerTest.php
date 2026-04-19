<?php

namespace App\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use App\Controller\paymentController\PaymentsController;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Security\Core\Security;
use App\Services\TenantCacheService;
use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;

class PaymentsControllerTest extends TestCase
{
    public function testCreateStripePaymentIntentDelegatesToService()
    {
        $getPaymentsByOrderSourceUseCase = $this->createMock(GetPaymentsByOrderSourceUseCase::class);
        $createPaymentUseCase = $this->createMock(CreatePaymentUseCase::class);
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $security = $this->createMock(Security::class);
        $cache = $this->createMock(TenantCacheService::class);
        $stripeService = $this->createMock(StripeService::class);
        $connectionManager = $this->createMock(TenantConnectionManager::class);

        // Controller no longer takes EntityRetrieverService or CalculateTaxesUseCase
        $controller = new PaymentsController(
            $getPaymentsByOrderSourceUseCase,
            $createPaymentUseCase,
            $emProvider,
            $security,
            $cache,
            $stripeService,
            $connectionManager
        );
        $controller->setContainer($this->createMock(\Psr\Container\ContainerInterface::class));

        // Mock User
        $user = $this->createMock(User::class);
        $security->method('getUser')->willReturn($user);

        // Mock Request
        $requestData = [
            'items' => [
                ['productVariantId' => 1, 'quantity' => 2]
            ],
            'priceShipping' => 10.0
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        // Expect StripeService to handle the logic
        $expectedResult = [
            'success' => true,
            'clientSecret' => 'client_secret_123',
            'calculatedAmount' => 115.0
        ];

        $stripeService->expects($this->once())
            ->method('createPaymentIntentFromItems')
            ->with($requestData, $requestData['priceShipping'])
            ->willReturn($expectedResult);

        $response = $controller->createStripePaymentIntent($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $responseData = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('clientSecret', $responseData);
        $this->assertEquals('client_secret_123', $responseData['clientSecret']);
    }
}
