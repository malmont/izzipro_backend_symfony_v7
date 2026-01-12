<?php

namespace App\Tests\Unit\Controller\Account;

use App\Controller\Account\SecurityController;
use App\Entity\User;
use App\Services\AuthenticationService;
use App\Services\OtpService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

class SecurityControllerTest extends TestCase
{
    private $tenantEmProvider;
    private $tokenService;
    private $otpService;
    private $controller;
    private $container;
    private $entityManager;

    protected function setUp(): void
    {
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->otpService = $this->createMock(OtpService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->controller = new SecurityController(
            $this->tenantEmProvider,
            $this->tokenService,
            $this->otpService
        );

        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->controller->setContainer($this->container);

        // Default container mock
        $this->container->method('has')->willReturn(false);
    }

    public function testLoginApiDelegatesToAuthService(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'username' => 'user',
            'password' => 'pass',
            'platform' => 'web'
        ]));

        $authService = $this->createMock(AuthenticationService::class);
        $expectedResponse = new JsonResponse(['token' => 'abc']);

        $authService->expects($this->once())
            ->method('login')
            ->with('user', 'pass', 'web', $request)
            ->willReturn($expectedResponse);

        $response = $this->controller->loginApi($request, $authService);

        $this->assertSame($expectedResponse, $response);
    }

    public function testRefreshTokenSuccess(): void
    {
        $request = new Request();
        $request->cookies->set('refresh_token', 'valid_refresh_token');

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $refreshTokenManager = $this->createMock(RefreshTokenManagerInterface::class);

        // Mock Refresh Token
        $refreshTokenEntity = $this->createMock(RefreshToken::class);
        $refreshTokenEntity->method('isValid')->willReturn(true);
        $refreshTokenEntity->method('getUsername')->willReturn('user@test.com');

        $refreshTokenManager->method('get')->with('valid_refresh_token')->willReturn($refreshTokenEntity);

        // Mock User Lookup
        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($userRepo);
        $userRepo->expects($this->once())->method('findOneBy')->with(['email' => 'user@test.com'])->willReturn($user);

        $jwtManager->expects($this->once())->method('create')->with($user)->willReturn('new_jwt_token');

        $response = $this->controller->refreshToken($request, $jwtManager, $refreshTokenManager);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Token refreshed successfully', $content['message']);

        // Verify Cookies
        $cookies = $response->headers->getCookies();
        $this->assertNotEmpty($cookies);
        $this->assertEquals('jwt', $cookies[0]->getName());
        $this->assertEquals('new_jwt_token', $cookies[0]->getValue());
    }

    public function testRefreshTokenMissingCookie(): void
    {
        $request = new Request(); // No cookies
        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $refreshTokenManager = $this->createMock(RefreshTokenManagerInterface::class);

        $response = $this->controller->refreshToken($request, $jwtManager, $refreshTokenManager);

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Refresh token not found', $content['error']);
    }

    public function testRefreshTokenInvalid(): void
    {
        $request = new Request();
        $request->cookies->set('refresh_token', 'invalid_token');

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $refreshTokenManager = $this->createMock(RefreshTokenManagerInterface::class);

        $refreshTokenManager->method('get')->with('invalid_token')->willReturn(null);

        $response = $this->controller->refreshToken($request, $jwtManager, $refreshTokenManager);

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid refresh token', $content['error']);
    }

    public function testValidateTokenSuccess(): void
    {
        $user = $this->createMock(UserInterface::class);

        $controller = new class($this->tenantEmProvider, $this->tokenService, $this->otpService) extends SecurityController {
            public $userMock;
            protected function getUser(): ?UserInterface
            {
                return $this->userMock;
            }
        };
        $controller->userMock = $user;
        $controller->setContainer($this->container);

        $response = $controller->validateToken();

        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Token is valid', $content['message']);
    }

    public function testValidateTokenFailure(): void
    {
        $controller = new class($this->tenantEmProvider, $this->tokenService, $this->otpService) extends SecurityController {
            protected function getUser(): ?UserInterface
            {
                return null;
            }
        };
        $controller->setContainer($this->container);

        $response = $controller->validateToken();

        $this->assertEquals(401, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Token is invalid or expired', $content['message']);
    }

    public function testLogoutWeb(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'example.com']);
        $response = $this->controller->logoutWeb($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $cookies = $response->headers->getCookies();
        $this->assertCount(2, $cookies);

        $jwtCookie = $cookies[0];
        $this->assertEquals('jwt', $jwtCookie->getName());
        $this->assertLessThan(time(), $jwtCookie->getExpiresTime());

        $refreshCookie = $cookies[1];
        $this->assertEquals('refresh_token', $refreshCookie->getName());
        $this->assertLessThan(time(), $refreshCookie->getExpiresTime());
    }
}
