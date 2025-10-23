<?php
// src/Controller/ShippingController/ShippingController.php

namespace App\Controller\ShippingController;

use App\Dto\CartItemDto;
use App\UseCase\ShippingUseCase\GetShippingRatesForCart;
use App\UseCase\ShippingUseCase\GetShippingSummaryForCart;
use App\UseCase\ShippingUseCase\GetParcelSummariesForCart;
use App\UseCase\ShippingUseCase\PurchaseShippingForCart;
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
        private PurchaseShippingForCart    $purchaseShippingForCart,
        private GetShippingSummaryForCart  $getShippingSummaryForCart,
        private LoggerInterface            $logger 
    ) {}

    /**
     * POST /api/shipping/rates
     */
    #[Route('/api/shipping/rates', methods: ['POST'])]
    public function rates(Request $request): JsonResponse
    {
        // 2. AJOUTER LE BLOC TRY...CATCH
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
            // Erreur 400 (Bad Request) : Le produit est trop grand.
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST 
            );
        } catch (\Exception $e) {
            // Erreur 500 (Interne) : API EasyPost HS, autre...
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
                // Note: les adresses et transporteurs ne sont pas
                // nécessaires pour getParcelSummaries selon notre ShippingService
            );
            return $this->json($summary);

        } catch (ItemTooLargeForPackagingException $e) {
            // Erreur 400 (Bad Request)
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            // Erreur 500 (Interne)
            $this->logger->error('Erreur API Shipping/Parcels: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(
                ['error' => 'Une erreur serveur est survenue lors du calcul des colis.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * POST /api/shipping/buy
     */
    #[Route('/api/shipping/buy', methods: ['POST'])]
    public function buy(Request $request): JsonResponse
    {
        // 2. AJOUTER LE BLOC TRY...CATCH
        try {
            $data = json_decode($request->getContent(), true);
            $orderId = (int) ($data['orderId'] ?? 0);
            $cart = array_map(
                fn(array $i) => new CartItemDto($i['productId'], $i['quantity']),
                $data['cartItems'] ?? []
            );
            $labels = $this->purchaseShippingForCart->execute(
                $cart,
                $data['to']               ?? [],
                $data['from']             ?? [],
                $data['carrierAccountId'] ?? '',
                $data['service']          ?? '',
                $orderId
            );
            return $this->json($labels);

        } catch (ItemTooLargeForPackagingException $e) {
            // Erreur 400 (Bad Request)
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            // Erreur 500 (Interne)
            $this->logger->error('Erreur API Shipping/Buy: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(
                ['error' => 'Une erreur serveur est survenue lors de l\'achat des étiquettes.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * POST /api/shipping/summary
     */
    #[Route('/api/shipping/summary', methods: ['POST'])]
    public function summary(Request $request): JsonResponse
    {
        // 2. AJOUTER LE BLOC TRY...CATCH
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
            // Erreur 400 (Bad Request)
            return $this->json(
                ['error' => $e->getMessage()],
                JsonResponse::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            // Erreur 500 (Interne)
            $this->logger->error('Erreur API Shipping/Summary: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(
                ['error' => 'Une erreur serveur est survenue lors du calcul du résumé.'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
