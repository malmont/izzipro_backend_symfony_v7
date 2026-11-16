<?php

namespace App\Services\Entry;

/** Une entrée refusée (lot AG-3b) : code stable que les applications traduisent, et statut HTTP */
final class EntryException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly ?string $reason = null)
    {
        parent::__construct($errorCode);
    }
}
