<?php

namespace App\ESG\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Inscription d'une entreprise avec une adresse déjà connue, d'un compte de la Boussole ou d'un compte du site (lot BOUSSOLE-CONSEILLER,
 * 13/11/2026) : 409, un seul code et un seul message pour les deux cas, pour ne pas dire à un inconnu de quel côté le compte existe.
 */
final class EmailUnavailableException extends ConflictHttpException
{
    public const CODE = 'EMAIL_UNAVAILABLE';
    public const MESSAGE = 'Cette adresse est déjà utilisée. Si c\'est votre compte Boussole, connectez-vous ; sinon, choisissez une autre adresse.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
