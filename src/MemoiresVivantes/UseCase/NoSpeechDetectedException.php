<?php

namespace App\MemoiresVivantes\UseCase;

/**
 * L'enregistrement ne contient aucune parole exploitable. Le message est destiné à l'utilisateur.
 */
class NoSpeechDetectedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Aucune parole n\'a été détectée dans l\'enregistrement. Réessayez en parlant plus près du micro.');
    }
}
