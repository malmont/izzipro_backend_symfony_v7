<?php

namespace App\Tests\Unit\Controller\Account;

use App\Controller\Account\OtpApiController;
use App\Entity\OtpCode;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Services\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

class OtpApiControllerTest extends TestCase
{
    private $emProvider;
    private $tokenService;
    private $requestStack;
    private $entityManager;
    private $controller;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Mock getting EM from provider
        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        // Instantiate Controller
        $this->controller = new OtpApiController(
            $this->emProvider,
            $this->tokenService,
            $this->requestStack
        );

        // AbstractController needs a container for some features, but here we are testing specific methods that rely on injected services.
        // However, $this->json() helper uses the serializer/container.
        // To make $this->json() work in a unit test of a controller extending AbstractController, we might need to mock the container or use a slightly different approach.
        // Alternatively, since we are doing a "Unit" test of a controller, we can mock the container:
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        // Exception: AbstractController uses generic container -> get('serializer') usually.
        // If the controller uses $this->json, it requires 'serializer' service or falls back to json_encode.
        // Let's set the container.
        $this->controller->setContainer($container);

        // Mock 'serializer' if needed or ensure json() works. 
        // AbstractController::json() uses $this->container->get('serializer') if available, or basic json_encode.
        // We can rely on basic behavior if serializer is missing (it throws if serializer missing usually? No, it constructs JsonResponse).
        // Let's see if it works without serializer first.

        // NOTE: AbstractController::json() implementation:
        // if ($this->container->has('serializer')) { return new JsonResponse($this->container->get('serializer')->serialize($data, 'json', $context), $status, $headers, true); }
        // return new JsonResponse($data, $status, $headers);

        $container->method('has')->with('serializer')->willReturn(false);
    }

    public function testOtpVerifyApiSuccess(): void
    {
        // 1. Setup Request
        $requestData = [
            'username' => 'test@example.com',
            'otp' => '123456',
            'platform' => 'mobile'
        ];
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'localhost'], json_encode($requestData));

        // 2. Mock Entities
        $user = $this->createMock(User::class);
        $otpCode = $this->createMock(OtpCode::class);
        $otpCode->method('getExpiration')->willReturn(new \DateTime('+1 hour'));

        // 3. Mock Repositories
        $userRepo = $this->createMock(EntityRepository::class);
        $otpRepo = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->will($this->returnValueMap([
                [User::class, $userRepo],
                [OtpCode::class, $otpRepo],
            ]));

        $userRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'test@example.com'])
            ->willReturn($user);

        $otpRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['userOtp' => $user, 'code' => '123456'])
            ->willReturn($otpCode);

        // 4. Mock Logic
        $this->entityManager->expects($this->once())->method('remove')->with($otpCode);
        $this->entityManager->expects($this->once())->method('flush');

        $tokens = ['token' => 'jwt_token', 'refresh_token' => 'refresh'];
        $this->tokenService->expects($this->once())
            ->method('generateTokens')
            ->with($user)
            ->willReturn($tokens);

        $responseMock = new JsonResponse($tokens);
        $this->tokenService->expects($this->once())
            ->method('createResponseWithTokens')
            ->with($tokens, 'mobile', 'localhost') // Symfony Request defaults to localhost or we mock it
            ->willReturn($responseMock);

        // 5. Execute
        $response = $this->controller->otpVerifyApi($request);

        // 6. Verify
        $this->assertSame($responseMock, $response);
    }

    public function testOtpVerifyApiUserNotFound(): void
    {
        $requestData = ['username' => 'unknown@example.com'];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $userRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($userRepo);

        $userRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $response = $this->controller->otpVerifyApi($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Unauthorized', $content['error']);
    }

    public function testOtpVerifyApiInvalidOtp(): void
    {
        $requestData = ['username' => 'test@example.com', 'otp' => 'wrong'];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $otpRepo = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->will($this->returnValueMap([
                [User::class, $userRepo],
                [OtpCode::class, $otpRepo],
            ]));

        $userRepo->method('findOneBy')->willReturn($user);

        // Case 1: OTP not found
        $otpRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $response = $this->controller->otpVerifyApi($request);

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Code OTP invalide ou expiré', $content['error']);
    }

    public function testOtpVerifyApiExpiredOtp(): void
    {
        $requestData = ['username' => 'test@example.com', 'otp' => 'expired'];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));

        $user = $this->createMock(User::class);
        $otpCode = $this->createMock(OtpCode::class);
        // Expired yesterday
        $otpCode->method('getExpiration')->willReturn(new \DateTime('-1 day'));

        $userRepo = $this->createMock(EntityRepository::class);
        $otpRepo = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->will($this->returnValueMap([
                [User::class, $userRepo],
                [OtpCode::class, $otpRepo],
            ]));

        $userRepo->method('findOneBy')->willReturn($user);
        $otpRepo->method('findOneBy')->willReturn($otpCode);

        $response = $this->controller->otpVerifyApi($request);

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Code OTP invalide ou expiré', $content['error']);
    }
}
