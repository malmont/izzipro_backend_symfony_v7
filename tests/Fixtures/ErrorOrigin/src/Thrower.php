<?php

namespace App\Tests\Fixtures\ErrorOrigin\src;

use App\Services\CaisseService\CashRegisterException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Exceptions levées « depuis notre code » pour le test de ClientSafeError : ce dossier joue le rôle de `src/` (le service est construit
 * avec `tests/Fixtures/ErrorOrigin` comme racine du projet).
 */
final class Thrower
{
    public static function business(string $class, string $message, ?\Throwable $previous = null): \Throwable
    {
        return new $class($message, 0, $previous);
    }

    public static function application(string $message, ?\Throwable $previous = null): CashRegisterException
    {
        $e = new CashRegisterException('DEMO', $message, 409);

        return $previous === null ? $e : new CashRegisterException('DEMO', $message . ($previous->getMessage()), 409);
    }

    public static function notFound(string $message): NotFoundHttpException
    {
        return new NotFoundHttpException($message);
    }

    /** Exception née dans une classe de PHP appelée d'ici : son texte est celui de PHP */
    public static function internal(): \Throwable
    {
        $before = libxml_use_internal_errors(true); // pas d'avertissement : seule l'exception nous intéresse
        try {
            new \SimpleXMLElement('<pas-du-xml');
        } catch (\Throwable $e) {
            return $e;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        throw new \LogicException('SimpleXMLElement aurait dû refuser');
    }

    public static function typeError(): \Throwable
    {
        try {
            return self::typed('pas un entier');
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private static function typed(mixed $value): \Throwable
    {
        return new \Exception(str_repeat('x', $value));
    }
}
