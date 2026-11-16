<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Services\LossTagVault;

/**
 * La variable des clés de chiffrement du mot de passe des puces (lot L3-c), posée par un essai. UNE seule clé d'essai par rang pour
 * toutes les classes : le mot de passe du site d'essai, une fois tiré, ne se supprime pas (la base le refuse) ; une autre clé sous
 * le même rang, dans une autre classe de la même suite, ne le relirait plus.
 */
trait LossTagKeysTrait
{
    /** Une clé d'essai de 32 octets en base64 (jamais une vraie clé) */
    protected static function tagKey(int $n): string
    {
        return base64_encode(str_pad("cle-d-essai-l3c-$n", 32, '-'));
    }

    /** Pose la variable : une clé par rang, dans l'ordre (aucune : la variable est retirée) */
    protected function tagKeys(?string ...$keys): void
    {
        $keys = array_values(array_filter($keys, static fn (?string $k): bool => $k !== null));
        $this->tagKeysRaw($keys === [] ? null : implode(',', array_map(static fn (int $i, string $k): string => ($i + 1) . ':' . $k, array_keys($keys), $keys)));
    }

    protected function tagKeysRaw(?string $value): void
    {
        if ($value === null) {
            unset($_ENV[LossTagVault::VARIABLE], $_SERVER[LossTagVault::VARIABLE]);
        } else {
            $_ENV[LossTagVault::VARIABLE] = $_SERVER[LossTagVault::VARIABLE] = $value;
        }
    }
}
