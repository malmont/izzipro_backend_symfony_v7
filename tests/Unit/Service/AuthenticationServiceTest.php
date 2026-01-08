<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Services\AuthenticationService;
use App\Services\OtpService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TokenService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthenticationServiceTest extends TestCase
{
    private $emProvider;
    private $passwordHasher;
    private $otpService;
    private $tokenService;
    private $em;
    private $authService;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->otpService = $this->createMock(OtpService::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->em);

        // Mock connection implicitly if needed, though simpler tests might skip deep DBAL mocks
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('test_db');
        $this->em->method('getConnection')->willReturn($connection);

        $this->authService = new AuthenticationService(
            $this->emProvider,
            $this->passwordHasher,
            $this->otpService,
            $this->tokenService
        );
    }

    public function testLoginReturnsUnauthorizedIfUserNotFoundOrInvalidPassword(): void
    {
        $request = new Request();
        $userRepo = $this->createMock(EntityRepository::class);

        // Scenario 1: User not found
        $userRepo->expects($this->once())->method('findOneBy')->with(['email' => 'unknown@example.com'])->willReturn(null);
        $this->em->method('getRepository')->with(User::class)->willReturn($userRepo);

        $response = $this->authService->login('unknown@example.com', 'wrong', 'web', $request);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertStringContainsString('LOGIN_INVALID', $response->getContent());
    }

    public function testLoginReturnsUnauthorizedIfAccountNotVerified(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(false);

        $request = new Request();
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($user);
        $this->em->method('getRepository')->willReturn($userRepo);

        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $response = $this->authService->login('user@example.com', 'password', 'web', $request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertStringContainsString('ACCOUNT_NOT_VERIFIED', $response->getContent());
    }

    public function testLoginReturnsForbiddenIfWebAccessDenied(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_USER']); // Missing ROLE_USER_INTERNET

        $request = new Request();
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($user);
        $this->em->method('getRepository')->willReturn($userRepo);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $response = $this->authService->login('user@example.com', 'password', 'web', $request);

        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testLoginTriggersOtpIfEnabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_USER_INTERNET']);
        $user->method('isOtpEnabled')->willReturn(true);

        $request = new Request();
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($user);
        $this->em->method('getRepository')->willReturn($userRepo);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $this->otpService->expects($this->once())->method('generateAndSendOtp');

        $response = $this->authService->login('user@example.com', 'password', 'web', $request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('otp_required', $response->getContent());
    }

    public function testLoginSuccessGeneratesToken(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isVerified')->willReturn(true);
        $user->method('getRoles')->willReturn(['ROLE_USER_INTERNET']);
        $user->method('isOtpEnabled')->willReturn(false);

        $request = new Request();
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($user);
        $this->em->method('getRepository')->willReturn($userRepo);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $this->tokenService->expects($this->once())->method('generateTokens')->willReturn(['token' => 'abc']);
        $this->tokenService->expects($this->once())->method('createResponseWithTokens')->willReturn(new Response('{"token":"abc"}'));

        $response = $this->authService->login('user@example.com', 'password', 'web', $request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('abc', $response->getContent());
    }
}
