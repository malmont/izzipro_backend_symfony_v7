<?php

namespace App\Services\Group\Message;

/**
 * Lot G-1a : une invitation à rejoindre un groupe, traitée en file (la réponse au siège ne dit jamais si le site existe). Lot G-INVITES :
 * `requestId`, le numéro de la demande (événement `invitation_requested`), noté sur l'invitation créée ; nul pour un message d'avant le lot.
 * `requestId` est une propriété DÉCLARÉE avec sa valeur par défaut, et non un paramètre promu : un message sérialisé par l'ancien code (en
 * file au moment de la bascule) n'a pas cette propriété, et la désérialisation lui donne alors sa valeur par défaut au lieu de la laisser
 * non initialisée (relecture neuve de G-INVITES).
 */
final class SendGroupInvitationMessage
{
    public ?int $requestId = null;

    public function __construct(public readonly int $groupId, public readonly string $siteCode, public readonly ?string $by, public readonly string $locale = 'fr', ?int $requestId = null)
    {
        $this->requestId = $requestId;
    }
}
