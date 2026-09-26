<?php

namespace App\MemoiresVivantes\BookType;

/**
 * Règle métier non respectée lors de l'administration des types de livre.
 * Le message est destiné à l'admin (affiché tel quel par le front).
 */
class BookTypeAdminException extends \RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: 422;
    }
}
