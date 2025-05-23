<?php


namespace App\Controller\ShippingController;

use App\Dto\CartItemDto;
use App\UseCase\ShippingUseCase\GetShippingRatesForCart;
use App\UseCase\ShippingUseCase\GetShippingSummaryForCart;
use App\UseCase\ShippingUseCase\GetParcelSummariesForCart;
use App\UseCase\ShippingUseCase\PurchaseShippingForCart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ShippingController extends AbstractController
{
    public function __construct(
        private GetShippingRatesForCart    $getRatesForCart,
        private GetParcelSummariesForCart  $getParcelSummariesForCart,
        private PurchaseShippingForCart    $purchaseShippingForCart,
        private GetShippingSummaryForCart  $getShippingSummaryForCart
    ) {}

    /**
     * POST /api/shipping/rates
     */
    #[Route('/api/shipping/rates', methods: ['POST'])]
    public function rates(Request $request): JsonResponse
    {
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
    }

    /**
     * POST /api/shipping/parcels
     */
    #[Route('/api/shipping/parcels', methods: ['POST'])]
    public function parcels(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $cart = array_map(
            fn(array $i) => new CartItemDto($i['productId'], $i['quantity']),
            $data['cartItems'] ?? []
        );
        $summary = $this->getParcelSummariesForCart->execute(
            $cart,
            $data['to']       ?? [],
            $data['from']     ?? [],
            $data['carriers'] ?? []
        );
        return $this->json($summary);
    }

    /**
     * POST /api/shipping/buy
     */
    #[Route('/api/shipping/buy', methods: ['POST'])]
    public function buy(Request $request): JsonResponse
    {
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
    }

    /**
     * POST /api/shipping/summary
     */
    #[Route('/api/shipping/summary', methods: ['POST'])]
    public function summary(Request $request): JsonResponse
    {
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
    }
}
