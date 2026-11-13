<?php

namespace App\Gestion\Message;

/** Réponse à une question de la console IA de la gestion (lot W2, 13/11/2026), transport `gestion_ai` (worker de la gestion) */
final class AnswerConsoleQuestionMessage
{
    public function __construct(public readonly int $messageId, public readonly string $tenantCode)
    {
    }
}
