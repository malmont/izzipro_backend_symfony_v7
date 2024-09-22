<?php
namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;

class JWTFromCookieListener
{
    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();
        if (!$request->headers->has('Authorization') && $request->cookies->has('jwt')) {
            $jwt = $request->cookies->get('jwt');
        
            $request->headers->set('Authorization', sprintf('Bearer %s', $jwt));
        }
    }
}
