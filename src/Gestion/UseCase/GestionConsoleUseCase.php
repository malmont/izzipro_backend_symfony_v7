<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\Console\ConsoleResponder;
use App\Gestion\Services\Console\ConsoleService;

/** Console IA générale de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md`) : cas d'usage des cinq routes */
class GestionConsoleUseCase
{
    public function __construct(private readonly ConsoleService $console, private readonly ConsoleResponder $responder)
    {
    }

    /**
     * Dépose la question. Cas général : la réponse est confiée au worker et le POST rend `state: "pending"`. Réponse directe
     * (14/11/2026) : quand le serveur répond sans aucun appel au modèle (« explique-moi cet écran »), il le fait dans la requête
     * même et le POST rend déjà `state: "done"`, les blocs, les analyses et les unités ; l'application n'a pas à relire l'échange.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function ask(array $body, string $locale, ?string $user, bool $platformOwner): array
    {
        $posted = $this->console->ask($body['text'] ?? null, $body['conversationId'] ?? null, $body['context'] ?? null, $locale, $user, $platformOwner, $body['mode'] ?? null, false);
        $messageId = (int) $posted['messageId'];
        if (!$this->responder->answersAlone($messageId)) {
            $this->console->dispatch($messageId);

            return $posted;
        }
        $this->responder->answer($messageId); // ne lève jamais : un échec est noté sur la réponse
        $answered = $this->console->posted($messageId);
        if ($answered['state'] === ConsoleService::STATE_PENDING) {
            $this->console->dispatch($messageId); // par sûreté : jamais de réponse laissée en attente sans personne pour la préparer

            return $posted;
        }

        return $answered;
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
