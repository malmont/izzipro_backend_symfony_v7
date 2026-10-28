<?php

namespace App\Cache\Doctrine;

use App\Cache\CacheInvalidationQueue;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** Pilote enveloppé : chaque connexion ouverte connaît sa base (`dbname` des paramètres) ; voir InvalidateOnCommitMiddleware */
final class InvalidateOnCommitDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver, private CacheInvalidationQueue $queue)
    {
        parent::__construct($wrappedDriver);
    }

    /**
     * {@inheritDoc}
     *
     * @return DriverConnection
     */
    public function connect(
        #[\SensitiveParameter]
        array $params
    ) {
        return new InvalidateOnCommitConnection(parent::connect($params), $this->queue, (string) ($params['dbname'] ?? ''));
    }
}
