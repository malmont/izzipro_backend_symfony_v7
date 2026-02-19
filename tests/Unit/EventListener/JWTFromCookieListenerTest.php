<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\JWTFromCookieListener;
use App\Services\TenantConnectionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class JWTFromCookieListenerTest extends TestCase
{
    private $tenantProvider;
    private $listener;

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantConnectionProvider::class);
        $this->tenantProvider->method('getTenantCode')->willReturn('default');

        $this->listener = new JWTFromCookieListener($this->tenantProvider);
    }

    public function testOnKernelRequestDoesNothingIfAuthorizationHeaderExists()
    {
        $event = $this->createMock(RequestEvent::class);
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer existing_token');

        $event->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $this->listener->onKernelRequest($event);

        $this->assertEquals('Bearer existing_token', $request->headers->get('Authorization'));
    }

    public function testOnKernelRequestDoesNothingIfNoAuthorizationHeaderAndNoJwtCookie()
    {
        $event = $this->createMock(RequestEvent::class);
        $request = new Request();

        $event->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $this->listener->onKernelRequest($event);

        $this->assertFalse($request->headers->has('Authorization'));
    }

    public function testOnKernelRequestSetsAuthorizationHeaderIfNoHeaderButJwtCookieExists()
    {
        $event = $this->createMock(RequestEvent::class);
        $request = new Request();
        $jwtToken = 'some_jwt_token';

        // The listener looks for 'auth_token_default' (when tenant code is 'default')
        $request->cookies->set('auth_token_default', $jwtToken);

        $event->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $this->listener->onKernelRequest($event);

        $this->assertTrue($request->headers->has('Authorization'));
        $this->assertEquals('Bearer ' . $jwtToken, $request->headers->get('Authorization'));
    }
}
