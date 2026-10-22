<?php

namespace App\Services\PosTerminalService;

/** Erreur de la passerelle Terminal, déjà traduite en code d'erreur du POS (READER_OFFLINE, READER_BUSY, …) et statut HTTP */
class PosTerminalGatewayException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 502, public readonly ?string $stripeCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
