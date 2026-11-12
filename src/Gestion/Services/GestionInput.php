<?php

namespace App\Gestion\Services;

/**
 * Lecture et validation des entrées des routes de gestion (lot G1, 09/11/2026). Les montants sont des cents entiers ≥ 0 :
 * un nombre JSON intégral est accepté sous toute forme (`1250` et `1250.0`), une chaîne, un décimal non entier ou un négatif
 * est refusé (`MUST_BE_INTEGER_CENTS`) ; au-delà de `MAX_INT`, `TOO_LARGE`. Les erreurs sont accumulées puis levées en une seule `GestionValidationException`.
 */
final class GestionInput
{
    /**
     * Plus grand entier qu'une colonne `INT` range (lot BORNES, 12/11/2026) : au-delà, un montant, une quantité ou un identifiant est
     * refusé ici (`TOO_LARGE`) ; avant, il passait la validation et l'enregistrement échouait en base (500).
     */
    public const MAX_INT = 2147483647;
    /** Borne des montants signés de la trésorerie (colonnes `BIGINT`, nombres JSON exacts jusque-là) */
    public const MAX_SIGNED = 9.0e15;

    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data corps décodé (JSON ou multipart) */
    public function __construct(private readonly array $data)
    {
    }

    /** Montant en cents ; null si absent et facultatif */
    public function cents(string $field, bool $required = true): ?int
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            if ($required) {
                $this->errors[$field] = 'REQUIRED';
            }

            return null;
        }
        $value = $this->data[$field];
        // multipart : les nombres arrivent en chaîne ("1250", "1250.0") ; JSON : int ou float
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }
        if ((is_int($value) || is_float($value)) && $value > self::MAX_INT) {
            $this->errors[$field] = 'TOO_LARGE';

            return null;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && floor($value) === $value) {
            return (int) $value;
        }
        $this->errors[$field] = 'MUST_BE_INTEGER_CENTS';

        return null;
    }

    /** Montant en cents entiers signés (solde de trésorerie, seuil : un découvert est négatif, lot B1b-1) ; null si absent et facultatif */
    public function signedCents(string $field, bool $required = true): ?int
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            if ($required) {
                $this->errors[$field] = 'REQUIRED';
            }

            return null;
        }
        $value = $this->data[$field];
        if ((is_int($value) || is_float($value)) && !is_nan((float) $value) && abs($value) >= self::MAX_SIGNED) {
            $this->errors[$field] = 'TOO_LARGE';

            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        $this->errors[$field] = 'MUST_BE_INTEGER_CENTS';

        return null;
    }

    /** Nombre décimal (coefficient) ≥ 0 ; null si absent et facultatif */
    public function decimal(string $field, bool $required = true): ?float
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            if ($required) {
                $this->errors[$field] = 'REQUIRED';
            }

            return null;
        }
        $value = $this->data[$field];
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0) {
            return (float) $value;
        }
        $this->errors[$field] = 'MUST_BE_NUMBER';

        return null;
    }

    /** Entier ≥ 0 (quantité) ; null si absent et facultatif */
    public function integer(string $field, bool $required = true): ?int
    {
        $value = $this->cents($field, $required);
        if (($this->errors[$field] ?? null) === 'MUST_BE_INTEGER_CENTS') {
            $this->errors[$field] = 'MUST_BE_INTEGER';
        }

        return $value;
    }

    /** Chaîne non vide (bornée) ; null si absente et facultative */
    public function text(string $field, bool $required = true, int $max = 255): ?string
    {
        $value = $this->data[$field] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'REQUIRED';
            }

            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /** Date (`Y-m-d` ou `Y-m-d H:i:s`, ISO 8601 accepté) ; null si absente et facultative */
    public function date(string $field, bool $required = true): ?\DateTime
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            if ($required) {
                $this->errors[$field] = 'REQUIRED';
            }

            return null;
        }
        try {
            return new \DateTime(trim($value));
        } catch (\Exception) {
            $this->errors[$field] = 'INVALID_DATE';

            return null;
        }
    }

    /** Identifiant d'une entité liée : `field` entier, ou `field.id` (forme `{"fournisseur": {"id": 3}}`) */
    public function id(string $field, bool $required = true): ?int
    {
        $value = $this->data[$field] ?? null;
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'REQUIRED';
            }

            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if ((int) $value > self::MAX_INT) { // une chaîne de chiffres trop longue vaut PHP_INT_MAX une fois convertie
                $this->errors[$field] = 'TOO_LARGE';

                return null;
            }
            if ((int) $value > 0) {
                return (int) $value;
            }
        }
        $this->errors[$field] = 'INVALID_ID';

        return null;
    }

    /**
     * Liste d'identifiants : tableau, ou chaîne « 1,2 » (multipart). Les éléments vides sont ignorés (champ de formulaire laissé vide),
     * les doublons retirés ; un élément qui n'est pas un entier > 0 refuse le champ (`INVALID_ID`). Absente : liste vide.
     *
     * @return int[]
     */
    public function ids(string $field): array
    {
        $value = $this->data[$field] ?? [];
        if (is_string($value) || is_int($value)) {
            $value = explode(',', (string) $value);
        }
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $item = is_string($item) ? trim($item) : $item;
            if ($item === null || $item === '') {
                continue;
            }
            if (!(is_int($item) || (is_string($item) && ctype_digit($item))) || (int) $item <= 0) {
                $this->errors[$field] = 'INVALID_ID';

                return [];
            }
            if ((int) $item > self::MAX_INT) {
                $this->errors[$field] = 'TOO_LARGE';

                return [];
            }
            $ids[(int) $item] = (int) $item;
        }

        return array_values($ids);
    }

    /** Lève l'exception si une erreur a été accumulée */
    public function check(): void
    {
        if ($this->errors !== []) {
            throw new GestionValidationException($this->errors);
        }
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
