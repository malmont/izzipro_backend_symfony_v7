<?php

namespace App\Controller\CartController;

use App\Services\OrderService\CartQuoteException;
use App\UseCase\CartUseCase\QuoteCartUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Devis d'un panier (boutique réglable, 08/10/2026), public : le visiteur n'est pas forcément connecté quand il
 * compose son panier. Lecture seule : aucune réservation ni commande ; les mêmes règles servent à create-intent et à
 * la création de commande (CartQuoteCalculator).
 */
#[Route('/api/cart')]
class CartQuoteController extends AbstractController
{
    private const MAX_BODY_BYTES = 65536;

    public function __construct(private readonly QuoteCartUseCase $useCase)
    {
    }

    #[Route('/quote', name: 'api_cart_quote', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->json(['error' => 'Corps trop volumineux'], 413);
        }
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Corps JSON invalide', 'errors' => [['path' => '', 'message' => 'objet JSON attendu']]], 400);
        }
        try {
            return $this->json($this->useCase->execute($body));
        } catch (CartQuoteException $e) {
            return $this->json(['error' => $e->getMessage(), 'errors' => $e->errors], $e->getStatusCode());
        }
    }
}
