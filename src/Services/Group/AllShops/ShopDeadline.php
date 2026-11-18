<?php

namespace App\Services\Group\AllShops;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Lot G-2 (relecture neuve) : l'échéance de la lecture d'UNE boutique dans « Toutes les boutiques ». `statement_timeout` ne borne qu'une
 * requête ; une boutique en envoie une quinzaine. Tant qu'une échéance est posée (`AllShopsReader::readOnly`, et là seulement), chaque
 * requête SQL est d'abord vérifiée :
 * - échéance passée : `ShopDeadlineExceeded`, aucune requête de plus ;
 * - temps restant tombé sous la moitié du `statement_timeout` en place : il est resserré (`SET LOCAL`, dans la transaction de la boutique),
 *   si bien qu'une requête commencée juste avant l'échéance ne la dépasse que de peu.
 * `tripped()` dit si l'échéance a été atteinte, même si un service a attrapé l'exception et continué : la boutique est alors en `timeout`,
 * jamais servie avec des chiffres partiels.
 *
 * Sans échéance (toute autre requête du serveur), l'intergiciel ne fait qu'un test sur une variable statique.
 */
#[AsMiddleware]
final class ShopDeadline implements Middleware
{
    private static ?int $deadline = null;
    private static int $timeoutMs = 0;
    private static bool $tripped = false;

    /** Pose l'échéance (en ms à partir de maintenant) ; `$timeoutMs` : le `statement_timeout` déjà posé dans la transaction */
    public static function start(int $remainingMs): void
    {
        self::$deadline = hrtime(true) + $remainingMs * 1_000_000;
        self::$timeoutMs = $remainingMs;
        self::$tripped = false;
    }

    public static function stop(): void
    {
        self::$deadline = null;
    }

    public static function tripped(): bool
    {
        return self::$tripped;
    }

    /** Avant chaque requête : rien sans échéance ; sinon refus après l'échéance, ou délai resserré à renvoyer à la base */
    public static function check(): ?int
    {
        if (self::$deadline === null) {
            return null;
        }
        $remaining = (int) ((self::$deadline - hrtime(true)) / 1_000_000);
        if ($remaining <= 0) {
            self::$tripped = true;
            throw new ShopDeadlineExceeded('Toutes les boutiques : délai de la boutique dépassé');
        }
        if ($remaining < self::$timeoutMs / 2) {
            self::$timeoutMs = $remaining;

            return $remaining;
        }

        return null;
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params
            ): Connection {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        $this->tighten();

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->tighten();

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int
                    {
                        $this->tighten();

                        return parent::exec($sql);
                    }

                    private function tighten(): void
                    {
                        $ms = ShopDeadline::check();
                        if ($ms !== null) {
                            parent::exec('SET LOCAL statement_timeout = ' . max(1, $ms));
                        }
                    }
                };
            }
        };
    }
}
