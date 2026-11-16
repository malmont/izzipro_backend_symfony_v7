<?php

namespace App\Security;

/**
 * Les SECRETS ne s'affichent jamais (lot SECRETS-1, 16/11/2026, `docs/architecture.md`, « Secrets ») : mot de passe d'un serveur
 * d'envoi, clé d'un service tiers, jeton d'accès. Un secret se remplace, il ne se relit pas : un écran dit seulement s'il est
 * « défini » ou « non défini ».
 *
 * Cette classe dit quels NOMS de champ désignent un secret, et masque leur valeur dans un état relu (journal des modifications).
 */
final class SecretKeys
{
    public const MASK = '•••';
    public const DEFINED = 'défini';
    public const UNDEFINED = 'non défini';

    /**
     * Un nom de champ dit-il un secret ? Oui s'il contient « password », « secret » ou « apikey », ou s'il finit par « token »,
     * « accesskey » ou « privatekey » (casse, tirets et soulignés ignorés). `inputTokens`, `memoryKey` ou `periodKey` n'en sont pas.
     */
    public static function names(string $key): bool
    {
        $k = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));

        return preg_match('/password|passwd|secret|apikey|credential/', $k) === 1 || preg_match('/(token|accesskey|privatekey)$/', $k) === 1;
    }

    /**
     * Une colonne qu'une liste ne doit ni CHERCHER ni TRIER : un secret, ou une empreinte (mot de passe, code, jeton). Une recherche
     * par fragment dit si le fragment est dans la colonne : répétée, elle reconstitue la valeur ; un tri la range. Lot SECRETS-1 :
     * la recherche d'EasyAdmin portait sur toutes les colonnes d'une table, affichées ou non.
     */
    public static function unsearchable(string $property): bool
    {
        $last = ($pos = strrpos($property, '.')) === false ? $property : substr($property, $pos + 1);

        return self::names($last) || preg_match('/hash$/i', $last) === 1;
    }

    /**
     * Un état (tableau ou objet décodé d'un JSON) dont chaque valeur de secret est masquée, à toute profondeur. Rien n'est effacé de
     * ce qui est gardé : c'est la LECTURE qui masque. Une valeur vide reste vide (on voit qu'il n'y avait rien).
     */
    public static function mask(mixed $state): mixed
    {
        if (is_array($state)) {
            foreach ($state as $key => $value) {
                $state[$key] = is_string($key) && self::names($key) && self::filled($value) ? self::MASK : self::mask($value);
            }

            return $state;
        }
        if ($state instanceof \stdClass) {
            foreach (get_object_vars($state) as $key => $value) {
                $state->{$key} = self::names((string) $key) && self::filled($value) ? self::MASK : self::mask($value);
            }
        }

        return $state;
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [] && $value !== false;
    }
}
