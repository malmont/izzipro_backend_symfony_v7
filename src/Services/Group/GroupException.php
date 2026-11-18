<?php

namespace App\Services\Group;

/** Un refus du multi-boutiques (lot G-1a) : code stable que les applications traduisent, et statut HTTP */
final class GroupException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message = '', public readonly array $extra = [])
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
