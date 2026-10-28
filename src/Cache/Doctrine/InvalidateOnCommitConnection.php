<?php

namespace App\Cache\Doctrine;

use App\Cache\CacheInvalidationQueue;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/** Connexion enveloppée : COMMIT réel → vidage des étiquettes de sa base, ROLLBACK → abandon ; voir InvalidateOnCommitMiddleware */
final class InvalidateOnCommitConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $wrappedConnection, private CacheInvalidationQueue $queue, private string $dbname)
    {
        parent::__construct($wrappedConnection);
    }

    /** {@inheritDoc} */
    public function commit()
    {
        $result = parent::commit();
        $this->queue->commit($this->dbname);

        return $result;
    }

    /** {@inheritDoc} */
    public function rollBack()
    {
        $result = parent::rollBack();
        $this->queue->rollBack($this->dbname);

        return $result;
    }
}
