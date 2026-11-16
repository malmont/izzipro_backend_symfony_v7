<?php

namespace App\Services\Platform\Sites\Message;

/** Tâche de fond (file `media`) : dérouler les étapes d'une demande de création de site par la plateforme (lot TYPE-1) */
final class BuildPlatformSiteMessage
{
    public function __construct(public readonly int $requestId)
    {
    }
}
