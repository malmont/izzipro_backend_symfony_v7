<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Group\GroupException;
use App\Services\Group\Transfer\GroupTransferService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lot G-5 : les transferts à ENVOYER, côté boutique SOURCE. Administrateur DU site seulement : l'envoi est le geste d'une personne dans la
 * boutique, qui voit partir la marchandise (ni le directeur entré, ni l'agence, ni le compte de la plateforme, ni l'assistant IA : 403).
 * - GET → `{ transfers: [...] }` (demandés, en échec d'envoi, en cours d'envoi)
 * - POST `/{id}/send` → 200 le transfert `sent` ; 409 TRANSFER_STOCK_SHORT (détail), TRANSFER_STOCK_BUSY, TRANSFER_ALREADY_SENDING,
 *   TRANSFER_ALREADY_SENT, TRANSFER_CANCELLED ; 422 TRANSFER_ASSOCIATED_SHOP ; 503 TRANSFER_SEND_FAILED (stock rendu, renvoi possible)
 */
#[Route('/api/site/group-transfers')]
#[IsGranted('ROLE_ADMIN')]
class SiteGroupTransferController extends AbstractController
{
    public function __construct(private readonly GroupTransferService $transfers, private readonly EntryContext $entry, private readonly TenantConnectionProvider $tenants)
    {
    }

    #[Route('', name: 'api_site_group_transfers', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->merchant() === null ? $this->forbidden() : $this->json(['transfers' => $this->transfers->toSend((string) $this->tenants->getTenantCode())]);
    }

    #[Route('/{id}/send', name: 'api_site_group_transfer_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(int $id): JsonResponse
    {
        $user = $this->merchant();
        if ($user === null) {
            return $this->forbidden();
        }
        try {
            return $this->json($this->transfers->send((string) $this->tenants->getTenantCode(), $id, $user));
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    private function merchant(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User && !$this->entry->active() && !PlatformAccessJournal::isPlatformAccount($user->getRoles()) ? $user : null;
    }

    private function forbidden(): JsonResponse
    {
        return $this->json(['error' => 'GROUP_FORBIDDEN', 'code' => 'GROUP_FORBIDDEN', 'message' => 'Seul un administrateur de la boutique envoie un transfert.'], 403);
    }
}
