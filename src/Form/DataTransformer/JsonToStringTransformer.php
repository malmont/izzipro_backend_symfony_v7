<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Champ texte d'une valeur JSON (colonne json d'une entité) : tableau ↔ texte JSON lisible.
 * Référencé par App\Form\Type\JsonTextType depuis sa création, mais jamais livré : le formulaire
 * « Configuration des réservations » plantait (ajouté le 31/10/2026).
 *
 * @implements DataTransformerInterface<array|null, string>
 */
class JsonToStringTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function reverseTransform(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $failure = new TransformationFailedException('JSON invalide : ' . $e->getMessage());
            $failure->setInvalidMessage('JSON invalide : {{ error }}', ['{{ error }}' => $e->getMessage()]);
            throw $failure;
        }
        if (!is_array($decoded)) {
            $failure = new TransformationFailedException('Un objet ou une liste JSON est attendu.');
            $failure->setInvalidMessage('Un objet ou une liste JSON est attendu.');
            throw $failure;
        }

        return $decoded;
    }
}
