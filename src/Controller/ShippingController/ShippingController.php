<?php
// src/Controller/ShippingController/ShippingController.php

namespace App\Controller\ShippingController;

use App\Dto\CartItemDto;
use App\UseCase\ShippingUseCase\GetShippingRatesForCart;
use App\UseCase\ShippingUseCase\GetShippingSummaryForCart;
use App\UseCase\ShippingUseCase\GetParcelSummariesForCart;
use App\Exception\ItemTooLargeForPackagingException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface; 

class ShippingController extends AbstractController
{
    public function __construct(
        private GetShippingRatesForCart    $getRatesForCart,
        private GetParcelSummariesForCart  $getParcelSummariesForCart,
        private GetShippingSummaryForCart  $getShippingSummaryForCart,
        private LoggerInterface            $logger,
        private \App\Services\TenantEntityManagerProvider $emProvider,
        private \App\Services\Http\ClientSafeError $safeError,
    ) {}

    /**
     * POST /api/shipping/rates
     */
    #[Route('/api/shipping/rates', methods: ['POST'])]
    public function rates(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $cart = array_map(
                fn(array $i) => new CartItemDto($i['productId'], $i['quantity']),
                $data['cartItems'] ?? []
            );
            $rates = $this->getRatesForCart->execute(
                $cart,
                $data['to']       ?? [],
                $data['from']     ?? [],
                $data['carriers'] ?? []
            );
            return $this->json($rates);

        } catch (ItemTooLargeForPackagingException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST 
            );
        } catch (\Exception $e) {
            $this->logger->error('Erreur API Shipping/Rates: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(
                ['error' => 'Une erreur serveur est survenue lors du calcul des tarifs.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * POST /api/shipping/parcels
     */
    #[Route('/api/shipping/parcels', methods: ['POST'])]
    public function parcels(Request $request): JsonResponse
    {
        // 2. AJOUTER LE BLOC TRY...CATCH
        try {
            $data = json_decode($request->getContent(), true);
            $cart = array_map(
                fn(array $i) => new CartItemDto($i['productId'], $i['quantity']),
                $data['cartItems'] ?? []
            );
            $summary = $this->getParcelSummariesForCart->execute(
                $cart
            );
            return $this->json($summary);

        } catch (ItemTooLargeForPackagingException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $this->logger->error('Erreur API Shipping/Parcels: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(
                ['error' => 'Une erreur serveur est survenue lors du calcul des colis.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    // POST /api/shipping/buy retiré (lot CHECKOUT-LIVRAISON, 21/11/2026) : un client y achetait des étiquettes aux frais du marchand ou de
    // la plateforme (adresse, compte et service du corps, sans débit ni contrôle) ; aucun front ne l'appelait. L'administration achète
    // l'étiquette d'une commande par /admin/orders/{id}/etiquette (AdminLabelPurchaseService : solde débité, commande vérifiée).

    /**
     * POST /api/shipping/summary
     */
    #[Route('/api/shipping/summary', methods: ['POST'])]
    public function summary(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $cartItems = array_map(
                fn(array $i) => new CartItemDto($i['productId'], $i['quantity']),
                $data['cartItems'] ?? []
            );

            $result = $this->getShippingSummaryForCart->execute(
                $cartItems,
                $data['to']       ?? [],
                $data['from']     ?? [],
                $data['carriers'] ?? []
            );

            return $this->json($result);

        } catch (ItemTooLargeForPackagingException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST
            );
        } catch (\App\Services\ShippingService\ShippingAddressRefusedException $e) {
            $text = 'Cette adresse de livraison est refusée par le transporteur : vérifiez-la.';

            return $this->json(['error' => $text, 'code' => 'SHIPPING_ADDRESS_INVALID', 'message' => $text], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\App\Services\ShippingService\ShippingRatesUnavailableException $e) {
            // lot CHECKOUT-LIVRAISON : EasyPost ne répond pas : 503 clair, aucun tarif,
            // jamais un prix à 0 ; le retrait et les transporteurs à prix fixe restent possibles
            $this->logger->error('Erreur API Shipping/Summary: ' . $e->getMessage(), ['exception' => $e::class]);
            $text = 'Les tarifs de livraison sont indisponibles pour le moment : réessayez, ou choisissez le retrait ou un autre transporteur.';

            return $this->json(['error' => $text, 'code' => 'SHIPPING_RATES_UNAVAILABLE', 'message' => $text], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            $this->logger->error('Erreur API Shipping/Summary: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(
                ['error' => 'Une erreur serveur est survenue lors du calcul du résumé.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
