<?php

namespace App\Services\Agency\Assistance;

/** Refus d'une demande d'assistance : code stable (les applications traduisent), statut HTTP */
final class AssistanceException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
