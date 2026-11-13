<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\Console\ConsoleService;

/** Console IA générale de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md`) : cas d'usage des cinq routes */
class GestionConsoleUseCase
{
    public function __construct(private readonly ConsoleService $console)
    {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function ask(array $body, string $locale, ?string $user, bool $platformOwner): array
    {
        return $this->console->ask($body['text'] ?? null, $body['conversationId'] ?? null, $body['context'] ?? null, $locale, $user, $platformOwner, $body['mode'] ?? null);
    }

    /** @return array<string, mixed> */
    public function conversation(int $id): array
    {
        return $this->console->conversation($id);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function list(int $page, int $limit): array
    {
        return $this->console->search($page, $limit);
    }

    public function delete(int $id): void
    {
        $this->console->delete($id);
    }

    /** @return list<array<string, mixed>> */
    public function analyses(string $locale): array
    {
        return $this->console->catalog($locale);
    }
}
