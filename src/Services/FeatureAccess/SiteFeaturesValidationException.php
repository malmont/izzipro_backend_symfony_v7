<?php

namespace App\Services\FeatureAccess;

/** Écriture du registre refusée : clé inconnue, non modifiable ou valeur invalide (422 VALIDATION_FAILED, erreurs par clé) */
final class SiteFeaturesValidationException extends \RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['path'] . ' : ' . $errors[0]['message']);
    }
}
