<?php

namespace App\Cache\Doctrine;

use App\Cache\CacheInvalidationQueue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Middleware DBAL (lot 1, 28/10/2026) : vide les étiquettes en attente (CacheInvalidationQueue) au COMMIT réel de chaque
 * connexion et les abandonne au ROLLBACK. Le COMMIT du pilote n'est appelé que lorsque le niveau d'imbrication des
 * transactions DBAL revient à zéro : c'est la validation réelle, qu'elle vienne du flush lui-même ou d'une transaction
 * explicite qui l'englobe.
 */
#[AsMiddleware]
final class InvalidateOnCommitMiddleware implements Middleware
{
    public function __construct(private CacheInvalidationQueue $queue)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new InvalidateOnCommitDriver($driver, $this->queue);
    }
}
