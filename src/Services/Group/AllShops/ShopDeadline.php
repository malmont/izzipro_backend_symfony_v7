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
 * - `statement_timeout` en place plus long que le temps restant de plus de `SLACK_MS` : il est resserré au temps restant (`SET LOCAL`,
 *   dans la transaction de la boutique) ; une requête ne dépasse donc jamais l'échéance de plus de `SLACK_MS` (une quinzaine de
 *   resserrages au plus sur 3 s).
 * `tripped()` dit si l'échéance a été atteinte, même si un service a attrapé l'exception et continué : la boutique est alors en `timeout`,
 * jamais servie avec des chiffres partiels.
 *
 * Sans échéance (toute autre requête du serveur), l'intergiciel ne fait qu'un test sur une variable statique.
 */
#[AsMiddleware]
final class ShopDeadline implements Middleware
{
    public const SLACK_MS = 200;

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
        if (self::$timeoutMs > $remaining + self::SLACK_MS) {
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
