<?php

namespace App\Controller\SiteFeaturesController;

use App\Services\Http\ListPage;
use App\Services\ReservationService\ShopBookingException;
use App\Services\ReservationService\ShopBookingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Lot CMD-WEB-1 (20/11/2026) : les rendez-vous de la BOUTIQUE pour la gestion web (jamais un entretien de Mémoires Vivantes : 404).
 * Droits (security.yaml, garde de l'Assistance, garde du mode) : administrateur du site, agent d'Assistance, agence pendant une assistance
 * « clients » ; le directeur entré lit, il ne confirme ni n'annule (garde du mode, 403 MODE_FORBIDDEN).
 * - GET `?status=pending|confirmed|cancelled&from=&to=&page=&limit=` → liste (à venir d'abord) ; en-têtes X-Total-Count, X-Page, X-Limit
 * - GET `/{id}` → le rendez-vous ; 404 RESERVATION_NOT_FOUND
 * - POST `/{id}/confirmer`, `/{id}/annuler` → `{ reservation, changed, emailRequested }` ; 409 RESERVATION_CANCELLED (confirmer un annulé)
 */
#[Route('/api/site/reservations-boutique')]
class SiteShopBookingController extends AbstractController
{
    public function __construct(private readonly ShopBookingService $bookings, private readonly ?\App\Services\CaisseService\SiteLocaleResolver $siteLocale = null)
    {
    }

    #[Route('', name: 'api_site_shop_bookings', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $paging = ListPage::fromRequest($request) ?? ListPage::fromRequest(new Request(['page' => 1]));
        try {
            $found = $this->bookings->list(self::text($request, 'status'), self::text($request, 'from'), self::text($request, 'to'), $paging->offset(), $paging->limit);
        } catch (ShopBookingException $e) {
            return $this->json($e->body(), $e->status);
        }

        return $this->json(['items' => $found['items'], 'total' => $found['total']], 200, $paging->headers($found['total']));
    }

    #[Route('/{id}', name: 'api_site_shop_booking', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function one(int $id): JsonResponse
    {
        try {
            return $this->json($this->bookings->one($id));
        } catch (ShopBookingException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    #[Route('/{id}/confirmer', name: 'api_site_shop_booking_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        return $this->change($id, ShopBookingService::CONFIRMED, $request);
    }

    #[Route('/{id}/annuler', name: 'api_site_shop_booking_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        return $this->change($id, ShopBookingService::CANCELLED, $request);
    }

    private function change(int $id, string $status, Request $request): JsonResponse
    {
        $host = $request->headers->get('X-Tenant-Host') ?: $request->getHost();
        try {
            return $this->json($this->bookings->changeStatus($id, $status, true, (string) $this->getUser()?->getUserIdentifier(), $this->siteLocale?->siteLocale() ?? 'fr', $host));
        } catch (ShopBookingException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
