<?php
// src/Messenger/TenantRoutingMiddleware.php

namespace App\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

class TenantRoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $numPartitions
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        
        if (method_exists($message, 'getTenantId')) {
            $tenantId = (int) $message->getTenantId();
            
            // Calcul déterministe de la partition (1, 2 ou 3)
            $partition = ($tenantId % $this->numPartitions) + 1;
            
            $transportName = 'async_worker_' . $partition;
            $envelope = $envelope->with(new TransportNamesStamp($transportName));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
