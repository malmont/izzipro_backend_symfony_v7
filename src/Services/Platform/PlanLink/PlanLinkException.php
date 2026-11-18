<?php

namespace App\Services\Platform\PlanLink;

/** Refus d'un lien « Mettre à jour mon forfait » (lot PROVISION-1) : un code stable, un message sans le jeton */
class PlanLinkException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
