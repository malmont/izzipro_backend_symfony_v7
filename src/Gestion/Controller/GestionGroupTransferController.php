<?php

namespace App\Gestion\Controller;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Group\GroupException;
use App\Services\Group\Transfer\GroupTransferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot G-5 (19/11/2026, `docs/ecrans-et-contextes.md` § 17) : le DIRECTEUR demande, lit et annule les transferts entre boutiques du groupe, au
 * siège, avec son compte (jamais en entrée : 403 `GROUP_TRANSFER_FORBIDDEN`). `ROLE_ADMIN` (`access_control` `/api/gestion`). L'ENVOI est le
 * geste de la boutique source (`/api/site/group-transfers`), jamais celui du directeur ; la RÉCEPTION est celle des commandes fournisseurs.
 */
class GestionGroupTransferController extends AbstractController
{
    public function __construct(private readonly GroupTransferService $transfers, private readonly EntryContext $entry)
    {
    }

    /** { source, target, lines: [{ variantId, quantity }] } → 201 le transfert, état `requested` */
    #[Route('/api/gestion/groupe/transferts', name: 'gestion_groupe_transferts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);

        return $this->act(fn (User $user) => $this->json($this->transfers->request($user, is_array($body) ? $body : []), 201));
    }

    #[Route('/api/gestion/groupe/transferts', name: 'gestion_groupe_transferts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json(['transfers' => $this->transfers->list($user)]));
    }

    /** La recherche des variantes d'une boutique du groupe (source d'un transfert) : `q`, `limit` (20, au plus 50), `target` facultatif */
    #[Route('/api/gestion/groupe/boutiques/{shop}/variantes', name: 'gestion_groupe_boutique_variantes', methods: ['GET'], requirements: ['shop' => '[a-z0-9][a-z0-9_-]{0,49}'])]
    public function variants(string $shop, Request $request): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json(['variants' => $this->transfers->searchVariants($user, $shop, $request->query->get('q'), $request->query->get('limit'), $request->query->get('target'))]));
    }

    #[Route('/api/gestion/groupe/transferts/{id}', name: 'gestion_groupe_transfert', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->transfers->show($user, $id)));
    }

    #[Route('/api/gestion/groupe/transferts/{id}/cancel', name: 'gestion_groupe_transfert_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->transfers->cancel($user, $id)));
    }

    /** @param callable(User): JsonResponse $action */
    private function act(callable $action): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || $this->entry->active()) {
            return $this->json(['error' => 'GROUP_TRANSFER_FORBIDDEN', 'code' => 'GROUP_TRANSFER_FORBIDDEN', 'message' => 'Transfert : réservé au directeur du groupe, sur le site du siège.'], 403);
        }
        try {
            return $action($user);
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }
    }
}
