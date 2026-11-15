<?php

namespace App\Services\Agency\Message;

/** L'invitation de l'administrateur d'un site créé par une agence est à renvoyer (lot AG-2) : `requestId` est la demande aboutie de ce site */
final class ResendAgencySiteInvitationMessage
{
    public function __construct(public readonly int $requestId, public readonly ?string $by = null)
    {
    }
}
