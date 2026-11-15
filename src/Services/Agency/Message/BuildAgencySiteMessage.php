<?php

namespace App\Services\Agency\Message;

/** Une demande de création d'un site par une agence est à fabriquer (lot AG-2) : worker « media », jamais relancé */
final class BuildAgencySiteMessage
{
    public function __construct(public readonly int $requestId)
    {
    }
}
