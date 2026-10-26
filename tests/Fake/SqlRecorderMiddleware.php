<?php

namespace App\Tests\Fake;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Tests seulement (S8) : enregistre les requêtes SQL exécutées, pour prouver qu'une route ne charge pas de relations sans limite.
 * SqlRecorderMiddleware::start() puis ::queries().
 */
final class SqlRecorderMiddleware implements Middleware
{
    /** @var list<string>|null */
    private static ?array $queries = null;

    public static function start(): void
    {
        self::$queries = [];
    }

    /** @return list<string> */
    public static function stop(): array
    {
        $q = self::$queries ?? [];
        self::$queries = null;

        return $q;
    }

    public static function record(string $sql): void
    {
        if (self::$queries !== null) {
            self::$queries[] = $sql;
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        SqlRecorderMiddleware::record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        SqlRecorderMiddleware::record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int
                    {
                        SqlRecorderMiddleware::record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
