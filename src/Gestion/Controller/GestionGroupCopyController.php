<?php

namespace App\Gestion\Controller;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Group\Copy\GroupCatalogCopyService;
use App\Services\Group\GroupException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot G-4 (19/11/2026, `docs/ecrans-et-contextes.md` § 15) : copie du catalogue entre boutiques du groupe, par le DIRECTEUR, sur le site du
 * siège, avec son compte (jamais en entrée : 403 `GROUP_COPY_FORBIDDEN`). `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion.
 * Outil de groupe : pas d'en-tête `X-Group-Scope` (il refuserait l'écriture). Les erreurs : `{ error, code, message, … }`.
 */
#[Route('/api/gestion/groupe/copies')]
class GestionGroupCopyController extends AbstractController
{
    public function __construct(private readonly GroupCatalogCopyService $copies, private readonly EntryContext $entry)
    {
    }

    /** Aperçu : { source?, target, articles? } → 201 la copie et le rapport d'analyse ; rien n'est écrit dans le catalogue de la cible */
    #[Route('', name: 'gestion_groupe_copies_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->copies->analyse($user, $this->body($request)), 201));
    }

    #[Route('', name: 'gestion_groupe_copies', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json(['copies' => $this->copies->list($user)]));
    }

    #[Route('/{id}', name: 'gestion_groupe_copie', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->copies->show($user, $id)));
    }

    /** { visibility?: hidden (défaut) | as_source, pos?: bool (défaut faux), withCost?: bool } → 202 ; la copie part en tâche de fond */
    #[Route('/{id}/confirm', name: 'gestion_groupe_copie_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->copies->confirm($user, $id, $this->body($request)), 202));
    }

    /** Annulation de 7 jours (règles de l'import : un article vendu ou utilisé est gardé) */
    #[Route('/{id}/cancel', name: 'gestion_groupe_copie_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->copies->cancel($user, $id)));
    }

    /** @param callable(User): JsonResponse $action */
    private function act(callable $action): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || $this->entry->active()) {
            return $this->json(['error' => 'GROUP_COPY_FORBIDDEN', 'code' => 'GROUP_COPY_FORBIDDEN', 'message' => 'Copie du catalogue : réservée au directeur du groupe, sur le site du siège.'], 403);
        }
        try {
            return $action($user);
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
