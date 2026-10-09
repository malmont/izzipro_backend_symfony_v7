<?php

namespace App\Dto;

use App\Services\OrderService\CartQuoteException;
use App\Services\OrderService\TaxEngine;

/**
 * Demande de devis d'un panier (POST /api/cart/quote, create-intent, order/create) :
 * items[] { productVariantId, quantity, booking?: { start, end, rateId?, durationType?, passengers? }, customizationId? },
 * carrierId?, shippingPrice? (cents : tarif choisi dans shipping/summary, lu seulement pour un transporteur EasyPost),
 * shippingAddress? { country, province|state, city, postalCode } (taxes par région ; sans adresse : aucune taxe, taxStatus
 * address_required).
 */
final class CartQuoteInputDto
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array{country: string, province: ?string, city: ?string, postalCode: ?string}|null $address normalisée (TaxEngine::normalizeAddress)
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $carrierId = null,
        public readonly ?int $shippingPrice = null,
        public readonly ?array $address = null
    ) {
    }

    /**
     * @param array<string, mixed> $data corps décodé (order/create, create-intent : mêmes clés, plus les anciennes
     *                                  priceShipping et booking global)
     * @throws CartQuoteException 400
     */
    public static function fromArray(array $data): self
    {
        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new CartQuoteException(400, 'items : au moins un article attendu', [['path' => 'items', 'message' => 'au moins un article attendu']]);
        }
        $globalBooking = $data['booking'] ?? $data['rental'] ?? null; // ancien envoi du tunnel : une réservation pour tout le panier
        $lines = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                throw new CartQuoteException(400, "items[$i] : objet attendu", [['path' => "items[$i]", 'message' => 'objet attendu']]);
            }
            if (!isset($item['booking']) && !isset($item['rental']) && is_array($globalBooking) && $globalBooking !== []) {
                $item['booking'] = $globalBooking;
            }
            $lines[] = $item;
        }
        $carrierId = $data['carrierId'] ?? null;
        $shipping = $data['shippingPrice'] ?? $data['priceShipping'] ?? null;
        if ($shipping !== null && (!is_numeric($shipping) || (float) $shipping < 0)) {
            throw new CartQuoteException(400, 'Frais de livraison invalides', [['path' => 'shippingPrice', 'message' => 'montant en cents, positif ou nul, attendu']]);
        }

        $address = is_array($data['shippingAddress'] ?? null) ? TaxEngine::normalizeAddress($data['shippingAddress']) : null;

        return new self($lines, is_numeric($carrierId) && (int) $carrierId > 0 ? (int) $carrierId : null, $shipping !== null ? (int) round((float) $shipping) : null, $address);
    }
}
