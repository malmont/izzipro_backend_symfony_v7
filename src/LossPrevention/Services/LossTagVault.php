<?php

namespace App\LossPrevention\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le coffre du mot de passe d'accès des puces (lot L3-c, `docs/prevention-pertes.md` § 16.20) : il chiffre et déchiffre avec une
 * clé qui vit HORS de la base, dans la variable d'environnement `LOSS_TAG_KEYS`, posée par le propriétaire (jamais par le code,
 * jamais dérivée du secret du serveur : celui-ci change, et les puces déjà écrites ne pourraient plus être réécrites).
 *
 * Chiffrement authentifié (XChaCha20-Poly1305), lié au site et au rang du mot de passe.
 *
 * Forme de la variable : `rang:clé` séparés par des virgules, la clé étant 32 octets au hasard en base64 (`1:…`, puis `1:…,2:…`
 * le jour où la clé change). Le plus grand rang chiffre ; un chiffre rangé se déchiffre avec la clé de SON rang : un changement de
 * clé déchiffre avec l'ancienne et rechiffre avec la nouvelle.
 *
 * Rien de ce que cette classe manipule ne s'écrit dans un journal ni dans un message d'erreur.
 */
final class LossTagVault
{
    public const VARIABLE = 'LOSS_TAG_KEYS';
    public const READY = 'ready';
    /** La variable n'est pas posée : aucun vrai travail d'impression */
    public const MISSING = 'missing';
    /** La variable est posée mais illisible (forme, longueur d'une clé, rang répété) */
    public const INVALID = 'invalid';

    /** @var array<int, string>|null clés par rang (binaire), ou null si la variable manque ou est illisible */
    private ?array $keys = null;
    private ?string $state = null;

    /**
     * Rien n'est lu ni vérifié ici : ce service est construit à chaque requête du boîtier, et la variable n'est regardée qu'au
     * premier usage (une variable illisible n'empêche aucune autre route de répondre).
     */
    public function __construct(#[\SensitiveParameter] #[Autowire('%env(default::LOSS_TAG_KEYS)%')] private ?string $raw = null)
    {
    }

    /** Rien de ce coffre ne s'affiche : ni la variable, ni les clés */
    public function __debugInfo(): array
    {
        return ['state' => $this->state];
    }

    /** @return string ready | missing | invalid */
    public function state(): string
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $raw = trim((string) $this->raw);
        $this->raw = null; // lue une fois : la variable ne reste pas dans l'objet
        if ($raw === '') {
            return $this->state = self::MISSING;
        }
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            return $this->state = self::INVALID; // sans la bibliothèque de chiffrement, rien ne se range ni ne se relit
        }
        $parsed = [];
        foreach (explode(',', $raw) as $part) {
            $pair = explode(':', trim($part), 2);
            $key = count($pair) === 2 ? base64_decode($pair[1], true) : false;
            if (count($pair) !== 2 || preg_match('/\A[1-9][0-9]{0,5}\z/', $pair[0]) !== 1 || $key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES || isset($parsed[(int) $pair[0]])) {
                return $this->state = self::INVALID;
            }
            $parsed[(int) $pair[0]] = $key;
        }
        ksort($parsed);
        $this->keys = $parsed;

        return $this->state = self::READY;
    }

    /** Le rang de la clé qui chiffre aujourd'hui (le plus grand), ou null sans clé */
    public function currentRank(): ?int
    {
        return $this->state() === self::READY ? (int) array_key_last($this->keys) : null;
    }

    /** A-t-on la clé de ce rang ? */
    public function has(int $rank): bool
    {
        return $this->state() === self::READY && isset($this->keys[$rank]);
    }

    /**
     * Un mot de passe d'accès neuf : 32 bits au hasard (ce que la puce accepte), jamais nul (nul veut dire « pas de mot de passe »).
     *
     * @return string huit caractères hexadécimaux
     */
    public static function draw(): string
    {
        do {
            $password = strtoupper(bin2hex(random_bytes(4)));
        } while ($password === '00000000');

        return $password;
    }

    /**
     * Chiffre un mot de passe pour UN usage : `$context` (le site et le rang du mot de passe) est lié au chiffre sans y être écrit.
     * Un chiffre copié ailleurs (une autre base, un autre rang) ne se relit pas.
     *
     * @return array{0: int, 1: string} le rang de la clé employée et le chiffre (hexadécimal : nonce puis texte chiffré authentifié)
     */
    public function encrypt(#[\SensitiveParameter] string $password, string $context): array
    {
        $rank = $this->currentRank();
        if ($rank === null) {
            throw new \LogicException('Aucune clé de chiffrement du mot de passe des puces.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return [$rank, sodium_bin2hex($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($password, $context, $nonce, $this->keys[$rank]))];
    }

    /** Le mot de passe d'un chiffre rangé, ou null si la clé de ce rang manque, si le chiffre est abîmé ou s'il n'est pas de ce contexte */
    public function decrypt(int $keyRank, string $cipher, string $context): ?string
    {
        if (!$this->has($keyRank) || preg_match('/\A(?:[0-9a-f]{2})+\z/', $cipher) !== 1) {
            return null;
        }
        $raw = sodium_hex2bin($cipher);
        $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($raw) <= $size) {
            return null;
        }
        $password = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $size), $context, substr($raw, 0, $size), $this->keys[$keyRank]);

        return is_string($password) && preg_match('/\A[0-9A-F]{8}\z/', $password) === 1 ? $password : null;
    }
}
