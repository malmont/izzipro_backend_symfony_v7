<?php

namespace App\Controller\ShippingController;

use App\Dto\CartItemDto;
use App\UseCase\ShippingUseCase\GetShippingRatesForCart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ShippingController extends AbstractController
{
    public function __construct(private GetShippingRatesForCart $getRatesForCart) {}

    /**
     * POST /api/shipping/rates
     * {
     *   "cartItems": [{"productId":12,"quantity":2},…],
     *   "to": {…},
     *   "from": {…},
     *   "carriers": ["ca_xxx","fedex_yyy"]
     * }
     */
    #[Route('/api/shipping/rates', methods: ['POST'])]
    public function rates(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Transformer en DTO
        $cartItems = array_map(
            fn(array $i) => new CartItemDto($i['productId'], $i['quantity']),
            $data['cartItems'] ?? []
        );

        $rates = $this->getRatesForCart->execute(
            $cartItems,
            $data['to']       ?? [],
            $data['from']     ?? [],
            $data['carriers'] ?? []
        );

        return $this->json($rates);
    }
}
