<?php

namespace App\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Security;
use App\Controller\OrderController\OrderController;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\GetOrdersBySourceUseCase;
use App\UseCase\OrderUseCase\GetOrdersByUserUseCase;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use App\Services\GemsuiteImporterService\GemsuiteSaleManager;
use Psr\Log\LoggerInterface;
use App\Services\OrderService\OrderMailerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entity\Adress;
use App\Entity\Carrier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use App\Dto\CreateOrderDTO;
use App\Entity\Order;
use App\Dto\CreateOrderMultiPaymentDTO;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class OrderControllerTest extends TestCase
{
    private $createOrderUseCase;
    private $cancelOrderUseCase;
    private $getOrdersBySourceUseCase;
    private $getOrdersByUserUseCase;
    private $emProvider;
    private $cache;
    private $gemsuiteSaleManager;
    private $logger;
    private $orderMailerService;
    private $stripeService;
    private $gemsuiteClientManager;
    private $tenantManager;
    private $gemsuiteClientUpdater;
    private $controller;
    private $container;
    private $tokenStorage;
    private $authChecker;

    protected function setUp(): void
    {
        $this->createOrderUseCase = $this->createMock(CreateOrderUseCase::class);
        $this->cancelOrderUseCase = $this->createMock(CancelOrderUseCase::class);
        $this->getOrdersBySourceUseCase = $this->createMock(GetOrdersBySourceUseCase::class);
        $this->getOrdersByUserUseCase = $this->createMock(GetOrdersByUserUseCase::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->cache = $this->createMock(TenantCacheService::class);
        $this->gemsuiteSaleManager = $this->createMock(GemsuiteSaleManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->orderMailerService = $this->createMock(OrderMailerService::class);
        $this->stripeService = $this->createMock(\App\Services\StripeService\StripeService::class);
        $this->gemsuiteClientManager = $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteClientManager::class);
        $this->tenantManager = $this->createMock(\App\Services\TenantConnectionManager::class);
        $this->gemsuiteClientUpdater = $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteClientUpdater::class);

        $this->controller = new OrderController(
            $this->createOrderUseCase,
            $this->cancelOrderUseCase,
            $this->getOrdersBySourceUseCase,
            $this->getOrdersByUserUseCase,
            $this->emProvider,
            $this->cache,
            $this->gemsuiteSaleManager,
            $this->logger,
            $this->orderMailerService,
            $this->stripeService,
            $this->gemsuiteClientManager,
            $this->tenantManager,
            $this->gemsuiteClientUpdater
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);

        $this->container->method('has')->willReturnMap([
            ['security.token_storage', true],
            ['security.authorization_checker', true],
            ['serializer', false], // We probably don't need serializer for these tests if json helper handles it manually or we mock it
        ]);

        // Use logic to return correct service based on ID
        $this->container->method('get')->willReturnCallback(function ($id) {
            if ($id === 'security.token_storage') {
                return $this->tokenStorage;
            }
            if ($id === 'security.authorization_checker') {
                return $this->authChecker;
            }
            return null;
        });

        $this->controller->setContainer($this->container);
    }

    /**
     * @return User|\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockUser()
    {
        $user = $this->createMock(User::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);
        return $user;
    }

    public function testCreateOrderUnauthorized(): void
    {
        // Mock no user
        $this->tokenStorage->method('getToken')->willReturn(null);

        $request = new Request();
        $response = $this->controller->createOrder($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(JsonResponse::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testCreateOrderMissingFields(): void
    {
        $this->mockUser();
        $request = new Request([], [], [], [], [], [], json_encode([]));

        $response = $this->controller->createOrder($request);

        $this->assertEquals(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCreateOrderSuccess(): void
    {
        $user = $this->mockUser();
        $user->method('getId')->willReturn(1);

        $payload = [
            'orderSource' => 1,
            'paymentMethod' => 2,
            'addressId' => 10,
            'items' => [['id' => 1, 'qty' => 2]],
            'carrierId' => 5,
            'typeOrder' => 1
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        // Mock EntityManager for Address and Carrier lookup
        $em = $this->createMock(EntityManagerInterface::class);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $addressRepo = $this->createMock(EntityRepository::class);
        $carrierRepo = $this->createMock(EntityRepository::class);

        $em->method('getRepository')->willReturnMap([
            [Adress::class, $addressRepo],
            [Carrier::class, $carrierRepo]
        ]);

        $address = $this->createMock(Adress::class);
        $address->method('getUserAdress')->willReturn($user);
        $addressRepo->method('find')->with(10)->willReturn($address);

        $carrier = $this->createMock(Carrier::class);
        $carrierRepo->method('find')->with(5)->willReturn($carrier);

        // Mock Stripe Verification
        $paymentIntent = $this->createMock(\Stripe\PaymentIntent::class);
        $paymentIntent->id = 'pi_123';
        $paymentIntent->status = 'succeeded';
        $paymentIntent->charges = (object)['data' => []];
        $this->stripeService->method('verifyPaymentIntent')->willReturn($paymentIntent);

        // Mock Order creation
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);

        $this->createOrderUseCase->expects($this->once())
            ->method('execute')
            ->with($this->isInstanceOf(CreateOrderDTO::class))
            ->willReturn($order);

        $em->expects($this->once())->method('refresh')->with($order);
        $this->gemsuiteSaleManager->expects($this->once())->method('createSale')->with($order);

        $response = $this->controller->createOrder($request);

        $this->assertEquals(JsonResponse::HTTP_CREATED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(123, $content['orderId']);
    }

    public function testCreateOrderWithMultiplePaymentsSuccess(): void
    {
        $user = $this->mockUser();
        $user->method('getId')->willReturn(1);

        $payload = [
            'orderSource' => 1,
            'paymentMethods' => [
                ['type' => 1, 'amount' => 50],
                ['type' => 2, 'amount' => 50]
            ],
            'addressId' => 10,
            'typeOrder' => 1,
            'items' => [['id' => 1, 'qty' => 2]]
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        // Mock Order creation
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(456);

        $this->createOrderUseCase->expects($this->once())
            ->method('execute')
            ->with($this->isInstanceOf(CreateOrderMultiPaymentDTO::class))
            ->willReturn($order);

        $this->gemsuiteSaleManager->expects($this->once())->method('createSale')->with($order);

        $response = $this->controller->createOrderWithMultiplePayments($request);

        $this->assertEquals(JsonResponse::HTTP_CREATED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(456, $content['orderId']);
    }

    public function testCancelOrderUnauthorizedUser(): void
    {
        $user = $this->mockUser();
        $otherUser = $this->createMock(User::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $orderRepo = $this->createMock(EntityRepository::class);
        $em->method('getRepository')->with(Order::class)->willReturn($orderRepo);

        $order = $this->createMock(Order::class);
        $order->method('getUserId')->willReturn($otherUser); // Different user
        $orderRepo->method('find')->with(999)->willReturn($order);

        $request = new Request([], [], [], [], [], [], json_encode(['paymentMethod' => 1]));

        $response = $this->controller->cancelOrder(999, $request);

        $this->assertEquals(JsonResponse::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testCancelOrderSuccess(): void
    {
        $user = $this->mockUser();

        $em = $this->createMock(EntityManagerInterface::class);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $orderRepo = $this->createMock(EntityRepository::class);
        $em->method('getRepository')->with(Order::class)->willReturn($orderRepo);

        $order = $this->createMock(Order::class);
        $order->method('getUserId')->willReturn($user); // Same user
        $orderRepo->method('find')->with(999)->willReturn($order);

        $request = new Request([], [], [], [], [], [], json_encode(['paymentMethod' => 1]));

        $this->cancelOrderUseCase->expects($this->once())
            ->method('execute')
            ->with(999, 1)
            ->willReturn(new JsonResponse(['success' => true]));

        $response = $this->controller->cancelOrder(999, $request);

        $this->assertEquals(200, $response->getStatusCode());
    }
    public function testGetOrdersSuccess(): void
    {
        $this->authChecker->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(true);

        $request = new Request(['orderSource' => 1]);

        $dto = $this->createMock(CreateOrderDTO::class);
        $dto->method('toArray')->willReturn(['id' => 123]);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([$dto]);

        $response = $this->controller->getOrders($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(1, $content);
        $this->assertEquals(123, $content[0]['id']);
    }

    public function testGetUserOrdersSuccess(): void
    {
        $user = $this->mockUser();
        $user->method('getId')->willReturn(1);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $request = new Request([], [], [], [], [], [], [], [], [], []);

        $dto = $this->createMock(CreateOrderDTO::class);
        $dto->method('toArray')->willReturn(['id' => 123]);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([$dto]);

        $response = $this->controller->getUserOrders($request, $security);

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertCount(1, $content);
        $this->assertEquals(123, $content[0]['id']);
    }
}
