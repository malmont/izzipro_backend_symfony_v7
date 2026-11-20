<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\Order;
use App\Services\OrderService\OrderDeskService;
use App\Services\OrderService\OrderDeskView;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Lot CMD-WEB-1 (20/11/2026) : la vue « À traiter » et la fiche d'une commande pour la gestion web, LECTURE SEULE, par le même service
 * que `/admin/commandes/…`, « Ma journée » et l'assistant du site (`OrderDeskService`). Droits (règles d'accès de security.yaml, garde
 * de l'Assistance, garde du mode) : administrateur du site, agent d'Assistance, agence pendant une assistance « commandes », directeur
 * entré. Ce que chacun reçoit : `OrderDeskView` (liste blanche). `GET /api/orders/{id}` reste la route du CLIENT.
 * - GET `/a-traiter?limit=` (1 à 500, 100 par défaut) → `{ toShip, toCapture, incomplete, inTransit, counts }`
 * - GET `/{id}?locale=fr|en` → la fiche ; 404 ORDER_NOT_FOUND
 */
#[Route('/api/site/commandes')]
class SiteOrderDeskController extends AbstractController
{
    public function __construct(private readonly OrderDeskService $desk, private readonly TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\MediaUrlResolver $media = null)
    {
    }

    #[Route('/a-traiter', name: 'api_site_orders_todo', methods: ['GET'])]
    public function todo(Request $request): JsonResponse
    {
        $limit = filter_var($request->query->get('limit', 100), FILTER_VALIDATE_INT);

        return $this->json(OrderDeskView::todo($this->desk->todo(is_int($limit) ? $limit : 100), OrderDeskView::audience($this->getUser())));
    }

    #[Route('/{id}', name: 'api_site_order_sheet', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sheet(int $id, Request $request): JsonResponse
    {
        $order = $this->emProvider->getEntityManager()->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            return $this->json(['error' => 'ORDER_NOT_FOUND', 'code' => 'ORDER_NOT_FOUND', 'message' => 'Commande introuvable.'], 404);
        }
        $locale = (string) $request->query->get('locale', 'fr');
        $host = $this->media?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();

        return $this->json(OrderDeskView::sheet($this->desk->sheet($order, $host, in_array($locale, ['fr', 'en'], true) ? $locale : 'fr'), OrderDeskView::audience($this->getUser())));
    }
}
