<?php

namespace App\Tests\Unit\Controller\paymentController;

use PHPUnit\Framework\TestCase;
use App\Controller\paymentController\PaymentsController;
use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Security\Core\Security;
use App\Services\TenantCacheService;
use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use App\Entity\Order;
use App\Entity\StripeConfig;
use Symfony\Contracts\Cache\ItemInterface;

class PaymentsControllerTest extends TestCase
{
    private $getPaymentsByOrderSourceUseCase;
    private $createPaymentUseCase;
    private $emProvider;
    private $security;
    private $cache;
    private $stripeService;
    private $connectionManager;
    private $controller;
    private $container;
    private $tokenStorage;

    protected function setUp(): void
    {
        $this->getPaymentsByOrderSourceUseCase = $this->createMock(GetPaymentsByOrderSourceUseCase::class);
        $this->createPaymentUseCase = $this->createMock(CreatePaymentUseCase::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->security = $this->createMock(Security::class);
        $this->cache = $this->createMock(TenantCacheService::class);
        $this->stripeService = $this->createMock(StripeService::class);
        $this->connectionManager = $this->createMock(TenantConnectionManager::class);

        $this->controller = new PaymentsController(
            $this->getPaymentsByOrderSourceUseCase,
            $this->createPaymentUseCase,
            $this->emProvider,
            $this->security,
            $this->cache,
            $this->stripeService,
            $this->connectionManager
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);

        $this->container->method('has')->willReturnCallback(function ($id) {
            if ($id === 'security.token_storage') {
                return true;
            }
            if ($id === 'serializer') {
                return false;
            }
            return false;
        });

        $this->container->method('get')->willReturnCallback(function ($id) {
            if ($id === 'security.token_storage') {
                return $this->tokenStorage;
            }
            return null;
        });

        $this->controller->setContainer($this->container);
    }

    protected function tearDown(): void
    {
        unset($_ENV['STRIPE_PUBLIC_KEY']);
        parent::tearDown();
    }

    private function mockUser()
    {
        $user = $this->createMock(User::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);
        // Also mock the security service injected in constructor
        $this->security->method('getUser')->willReturn($user);
        return $user;
    }


    public function testGetPaymentsUnauthorized(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);
        $request = new Request();
        $response = $this->controller->getPayments($request);

        $this->assertEquals(JsonResponse::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testGetPaymentsMissingOrderSource(): void
    {
        $this->mockUser();
        $request = new Request();
        $response = $this->controller->getPayments($request);

        $this->assertEquals(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGetPaymentsSuccess(): void
    {
        $user = $this->mockUser();
        $request = new Request(['orderSource' => 1, 'days' => 30]);

        $em = $this->createMock(EntityManagerInterface::class);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $orderRepo = $this->createMock(EntityRepository::class);
        $em->method('getRepository')->with(Order::class)->willReturn($orderRepo);

        // Mock finding user orders
        $orderRepo->method('findBy')->with(['user' => $user])->willReturn([new Order()]);

        // Mock Cache
        // We simulate the cache callback execution to test the use case call inside it
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        // Mock UseCase
        $dtoMock = new class {
            public function toArray()
            {
                return ['id' => 123, 'amount' => 100];
            }
        };

        $this->getPaymentsByOrderSourceUseCase->expects($this->once())
            ->method('execute')
            ->with(1, $this->anything(), $this->anything(), 30)
            ->willReturn([$dtoMock]);

        $response = $this->controller->getPayments($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(1, $content);
        $this->assertEquals(123, $content[0]['id']);
    }

    public function testProcessPaymentUnauthorized(): void
    {
        $this->tokenStorage->method('getToken')->willReturn(null);
        $request = new Request();
        $response = $this->controller->processPayment($request);

        $this->assertEquals(JsonResponse::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProcessPaymentMissingId(): void
    {
        $this->mockUser();
        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $this->controller->processPayment($request);

        $this->assertEquals(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testProcessPaymentSuccess(): void
    {
        $this->mockUser();
        $payload = ['paymentIntentId' => 'pi_12345'];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->createPaymentUseCase->expects($this->once())
            ->method('execute')
            ->with('pi_12345')
            ->willReturn(['success' => true, 'payment' => ['id' => 1]]);

        $response = $this->controller->processPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
    }

    public function testProcessPaymentFailure(): void
    {
        $this->mockUser();
        $payload = ['paymentIntentId' => 'pi_fail'];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->createPaymentUseCase->expects($this->once())
            ->method('execute')
            ->with('pi_fail')
            ->willReturn(['success' => false, 'errors' => 'Error message']);

        $response = $this->controller->processPayment($request);

        $this->assertEquals(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testCreateStripePaymentIntentSuccess(): void
    {
        $this->mockUser();
        $payload = [
            'items' => [['productVariantId' => 1, 'quantity' => 1]],
            'priceShipping' => 10.0
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $expectedResult = [
            'success' => true,
            'clientSecret' => 'client_secret_123',
            'calculatedAmount' => 115.0
        ];

        $this->stripeService->expects($this->once())
            ->method('createPaymentIntentFromItems')
            ->with($payload['items'], $payload['priceShipping'])
            ->willReturn($expectedResult);

        $response = $this->controller->createStripePaymentIntent($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('client_secret_123', $content['clientSecret']);
    }

    public function testGetStripeConfigInternal(): void
    {
        $_ENV['STRIPE_PUBLIC_KEY'] = 'pk_test_123';

        $this->connectionManager->method('getCurrentTenantCode')->willReturn('internal_tenant');
        $this->connectionManager->method('isTenantInternal')->with('internal_tenant')->willReturn(true);

        $response = $this->controller->getStripeConfig($this->emProvider);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('pk_test_123', $content['publicKey']);
        $this->assertNull($content['stripeAccountId']);
    }

    public function testGetStripeConfigExternalSuccess(): void
    {
        $_ENV['STRIPE_PUBLIC_KEY'] = 'pk_test_123';

        $this->connectionManager->method('getCurrentTenantCode')->willReturn('external_tenant');
        $this->connectionManager->method('isTenantInternal')->with('external_tenant')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $repo = $this->createMock(EntityRepository::class);
        $em->method('getRepository')->with(StripeConfig::class)->willReturn($repo);

        $config = $this->createMock(StripeConfig::class);
        $config->method('getAccountId')->willReturn('acct_123');

        $repo->method('findOneBy')->with(['isActive' => true])->willReturn($config);

        $response = $this->controller->getStripeConfig($this->emProvider);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('acct_123', $content['stripeAccountId']);
    }
}
