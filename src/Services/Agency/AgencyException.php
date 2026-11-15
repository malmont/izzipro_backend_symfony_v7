<?php

namespace App\Services\Agency;

/** Refus d'un geste sur une agence, avec un code stable que les applications traduisent (lot AG-1) */
final class AgencyException extends \RuntimeException
{
    public const MESSAGES = [
        'AGENCY_NOT_FOUND' => 'Agence introuvable.',
        'AGENCY_CODE_TAKEN' => 'Ce code d\'agence est déjà pris.',
        'AGENCY_HOME_TAKEN' => 'Ce site est déjà le site d\'attache d\'une agence, ou il est géré par une agence.',
        'AGENCY_SITE_RESERVED' => 'Ce site ne peut pas être confié à une agence.',
        'AGENCY_SITE_TAKEN' => 'Ce site a déjà une agence, ou il est le site d\'attache d\'une agence.',
        'AGENCY_SITE_SHARES_DATABASE' => 'Ce site partage sa base avec un site déjà confié à une agence.',
        'AGENCY_SITE_LIMIT' => 'L\'agence gère déjà le nombre de sites que son forfait permet.',
        'AGENCY_SITE_NOT_ATTACHED' => 'Ce site n\'est pas géré par cette agence.',
        'AGENCY_NOT_OPEN' => 'Cette agence est suspendue ou fermée.',
        'AGENCY_STATE' => 'Ce geste ne s\'applique pas à l\'état de l\'agence.',
        'AGENCY_MEMBER_NOT_FOUND' => 'Aucun compte du site d\'attache ne porte cette adresse.',
        'AGENCY_SCOPE' => 'Cette route n\'est ouverte que sur le site d\'attache d\'une agence.',
        // lot AG-2 : création d'un site par l'agence
        'AGENCY_MANAGER_ONLY' => 'Ce geste est réservé au responsable de l\'agence.',
        'AGENCY_TRIAL_ENDED' => 'L\'essai de l\'agence est terminé : elle ne crée plus de site.',
        'AGENCY_REQUEST_LIMIT' => 'Le nombre de demandes de création par jour est atteint.',
        'AGENCY_SITE_CODE_RESERVED' => 'Ce nom de site est réservé.',
        'AGENCY_SITE_CODE_TAKEN' => 'Ce nom de site est déjà pris.',
        'AGENCY_REQUEST_NOT_FOUND' => 'Demande de création introuvable.',
        'AGENCY_SITE_NOT_CREATED' => 'Ce site n\'a pas été créé par l\'agence : elle ne règle ni ses modules ni ses comptes.',
        'AGENCY_MODULE_UNKNOWN' => 'Module inconnu.',
        'AGENCY_MODULE_HELD' => 'Le site tient déjà ce module d\'une autre source : l\'agence ne le change pas.',
        'AGENCY_LIMITS' => 'La limite de sites est inférieure au nombre de sites déjà gérés.',
    ];

    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? $errorCode);
    }

    /** @return array<string, mixed> corps de la réponse d'erreur */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
