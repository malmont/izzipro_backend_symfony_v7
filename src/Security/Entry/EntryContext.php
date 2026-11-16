<?php

namespace App\Security\Entry;

use Symfony\Contracts\Service\ResetInterface;

/**
 * L'entrée de la REQUÊTE en cours (lot AG-3b) : posée par le fournisseur de comptes quand le jeton présenté est un jeton d'entrée,
 * lue par la garde du mode, la route d'entrée (un jeton d'entrée n'en obtient jamais un autre) et les codes d'échec du jeton.
 */
final class EntryContext implements ResetInterface
{
    /** @var array{id: int, mode: string, home: string}|null */
    private ?array $entry = null;
    private ?string $failure = null;

    /** @param array{id: int, mode: string, home: string} $entry */
    public function set(array $entry): void
    {
        $this->entry = $entry;
        $this->failure = null;
    }

    /** Le jeton présenté était un jeton d'entrée, refusé (entrée fermée, lien rompu) */
    public function fail(string $reason): void
    {
        $this->entry = null;
        $this->failure = $reason;
    }

    public function active(): bool
    {
        return $this->entry !== null;
    }

    /** @return array{id: int, mode: string, home: string}|null */
    public function entry(): ?array
    {
        return $this->entry;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function reset(): void
    {
        $this->entry = null;
        $this->failure = null;
    }
}
