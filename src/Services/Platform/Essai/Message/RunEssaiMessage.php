<?php

namespace App\Services\Platform\Essai\Message;

/** Remplir un site d'essai de données fictives (lot ESSAI-DONNÉES, bouton du web) : exécuté par le worker `media` */
final class RunEssaiMessage
{
    public function __construct(public readonly string $tenantCode, public readonly string $by)
    {
    }
}
