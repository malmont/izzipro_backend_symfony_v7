<?php

namespace App\Tests\Unit\Services\Booking;

use App\Services\Booking\BookingAuthService;
use App\Services\TenantConnectionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class BookingAuthServiceTest extends TestCase
{
    private $tenantManager;
    private $requestStack;
    private $session;
    private $service;

    protected function setUp(): void
    {
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(SessionInterface::class);

        // When getSession is called, return our mock session
        $this->requestStack->method('getSession')->willReturn($this->session);

        $this->service = new BookingAuthService($this->tenantManager, $this->requestStack);
    }

    public function testIsAuthenticatedReturnsTrueWhenSessionSet(): void
    {
        $this->session->expects($this->once())
            ->method('get')
            ->with('booking_access_granted')
            ->willReturn(true);

        $this->assertTrue($this->service->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsFalseWhenSessionNotSet(): void
    {
        $this->session->expects($this->once())
            ->method('get')
            ->with('booking_access_granted')
            ->willReturn(null);

        $this->assertFalse($this->service->isAuthenticated());
    }

    public function testAttemptLoginReturnsFalseIfNoTenantCode(): void
    {
        $this->tenantManager->expects($this->once())
            ->method('getCurrentTenantCode')
            ->willReturn(null);

        $result = $this->service->attemptLogin('some-token');
        $this->assertFalse($result);
    }

    public function testAttemptLoginReturnsFalseIfTokenDoesNotMatch(): void
    {
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('tenant');
        $this->tenantManager->expects($this->once())
            ->method('getTenantToken')
            ->with('tenant')
            ->willReturn('valid-token');

        $result = $this->service->attemptLogin('wrong-token');
        $this->assertFalse($result);
    }

    public function testAttemptLoginReturnsTrueAndSetsSessionIfTokenMatches(): void
    {
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('tenant');
        $this->tenantManager->method('getTenantToken')->willReturn('valid-token');

        $this->session->expects($this->once())
            ->method('set')
            ->with('booking_access_granted', true);

        $result = $this->service->attemptLogin('valid-token');
        $this->assertTrue($result);
    }

    public function testLogoutRemovesSessionKey(): void
    {
        $this->session->expects($this->once())
            ->method('remove')
            ->with('booking_access_granted');

        $this->service->logout();
    }
}
