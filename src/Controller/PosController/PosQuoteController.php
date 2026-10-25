<?php

namespace App\Controller\PosController;

use App\Services\CaisseService\CashRegisterException;
use App\Services\PosDiscountService\PosDiscountService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Devis de caisse (F1, 24/10/2026), route propre au POS : articles avec remises de ligne, remise de ticket, taxes du
 * magasin sur le net, total et cashTotal (arrondi des espèces). /api/cart/quote (web) est inchangé. Lecture : pas
 * d'Idempotency-Key.
 */
class PosQuoteController extends AbstractController
{
    public function __construct(private readonly PosDiscountService $discounts, private readonly \App\Services\PosGiftCardService\PosGiftCardService $giftCards,
        private readonly \App\Services\PosCouponService\PosCouponService $coupons) // coupons de fabricant (F17, 01/11/2026)
    {
    }

    /** {items: [{productVariantId, quantity, customizationId?, discounts?: [{type, value, reasonCode?, reasonText?}]}], ticketDiscount?: {type, value, reasonCode?, reasonText?}, cashRegisterId?} */
    #[Route('/api/pos/quote', name: 'pos_quote', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => 'items : au moins un article attendu', 'errors' => [['path' => 'items', 'message' => 'au moins un article attendu']]], 422);
        }
        try {
            $quote = $this->discounts->quote(array_values($data['items']), is_array($data['ticketDiscount'] ?? null) ? $data['ticketDiscount'] : null);
            // Cartes-cadeaux et avoirs présentés (F9) : solde, part applicable et reste à payer, sans débit ; clés ajoutées seulement si giftCards est envoyé
            if (!empty($data['giftCards'])) {
                $gift = $this->giftCards->preview($data['giftCards'], (int) $quote['total']);
                $quote += ['giftCards' => $gift['giftCards'], 'giftCardApplied' => $gift['giftCardApplied'], 'amountDue' => $gift['amountDue'], 'cashAmountDue' => $gift['cashAmountDue']];
            }
            // Coupons de fabricant (F17) : contrôlés contre les lignes (montant ≤ prix de l'article, expiration, doublon de série) ; taxes et total
            // inchangés (prix plein), seul le dû diminue ; clés ajoutées seulement si coupons est envoyé
            if (!empty($data['coupons'])) {
                $due = (int) ($quote['amountDue'] ?? $quote['total']);
                $preview = $this->coupons->preview($data['coupons'], $quote, $due);
                $quote['manufacturerCoupons'] = ['count' => $preview['count'], 'total' => $preview['total'], 'coupons' => $preview['coupons']];
                $quote['amountDue'] = $preview['amountDue'];
                $quote['cashAmountDue'] = $this->discounts->cashRound($preview['amountDue']);
            }

            return $this->json($quote);
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }
}
