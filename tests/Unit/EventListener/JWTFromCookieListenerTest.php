<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\JWTFromCookieListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class JWTFromCookieListenerTest extends TestCase
{
    public function testOnKernelRequestDoesNothingIfAuthorizationHeaderExists()
    {
        $listener = new JWTFromCookieListener();
        $event = $this->createMock(RequestEvent::class);
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer existing_token');

        $event->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $listener->onKernelRequest($event);

        $this->assertEquals('Bearer existing_token', $request->headers->get('Authorization'));
    }

    public function testOnKernelRequestDoesNothingIfNoAuthorizationHeaderAndNoJwtCookie()
    {
        $listener = new JWTFromCookieListener();
        $event = $this->createMock(RequestEvent::class);
        $request = new Request();

        $event->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $listener->onKernelRequest($event);

        $this->assertFalse($request->headers->has('Authorization'));
    }

    public function testOnKernelRequestSetsAuthorizationHeaderIfNoHeaderButJwtCookieExists()
    {
        $listener = new JWTFromCookieListener();
        $event = $this->createMock(RequestEvent::class);
        $request = new Request();
        $jwtToken = 'some_jwt_token';
        $request->cookies->set('jwt', $jwtToken);

        $event->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $listener->onKernelRequest($event);

        $this->assertTrue($request->headers->has('Authorization'));
        $this->assertEquals('Bearer ' . $jwtToken, $request->headers->get('Authorization'));
    }
}
