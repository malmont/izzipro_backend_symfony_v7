<?php

namespace App\Dto;

use App\Entity\Caisse;
use App\Entity\Entreprise;
use App\Entity\Order;

/**
 * Bloc « ticket de caisse » ajouté à la réponse 201 de POST /api/order/create-multi-payment (16/10/2026) : de quoi
 * imprimer un ticket conforme sans second appel. Montants en dollars (unités monétaires), comme les paymentMethods
 * envoyés par le POS. Champs existants de la réponse (success, orderId, message) inchangés. Contrat : docs/caisse.md.
 */
class PosReceiptDto
{
    /** @param array<string, \App\Entity\PosReceipt> $receipts numéros de la commande (C2) */
    public static function fromOrder(Order $order, ?Entreprise $entreprise, ?Caisse $caisse, string $locale, array $receipts = [],
        ?\App\Entity\PosTerminalPayment $terminal = null, ?string $receiptUrl = null, bool $duplicate = false, ?\App\Entity\PosReceipt $receiptOverride = null,
        array $giftCards = ['redemptions' => [], 'issued' => null, 'storeCredit' => null], ?array $customer = null, array $coupons = []): array
    {
        $dollars = fn (?float $cents) => round(((float) $cents) / 100, 2);
        // Ticket complet (F3, 24/10/2026) : lignes, caissier, devise, monnaie rendue, détail des cartes, nature, lien public, duplicata
        $lines = [];
        foreach ($order->getOrderItems() as $item) {
            $lines[] = [
                'productVariantId' => $item->getProductVariant()?->getId(), 'name' => $item->displayName(), 'saleUnit' => $item->getSaleUnit(),
                'quantity' => $item->getQuantity(), 'unitPrice' => $dollars($item->getUnitPrice()), 'gross' => $dollars($item->grossTotal()),
                'discountAmount' => $dollars($item->getDiscountAmount()), 'total' => $dollars($item->getTotalPrice()),
                'discounts' => array_map(fn ($d) => ['type' => $d['type'], 'value' => $d['value'], 'amount' => $dollars($d['amount']), 'scope' => $d['scope'], 'reason' => ['code' => $d['reasonCode'] ?? null, 'text' => $d['reasonText'] ?? null]], $item->getDiscounts()),
                // Article libre et prix manuel (F7, 26/10/2026) : le ticket montre le prix modifié ET le prix catalogue
                'custom' => $item->isCustom(), 'taxable' => $item->isTaxable(),
                'catalogUnitPrice' => $item->getCatalogUnitPrice() !== null ? $dollars($item->getCatalogUnitPrice()) : null,
                'priceReason' => $item->hasPriceOverride() ? ['code' => $item->getPriceReasonCode(), 'text' => $item->getPriceReasonText()] : null,
            ] + (($vp = $item->getVariantPromotion()) !== null && $item->getVariantCatalogUnitPrice() !== null ? ['promotion' => [ // lot VAR (11/11/2026) : promotion figée à la vente, clé absente sinon
                'kind' => 'variant', 'percent' => floor($vp->getPercent()) === $vp->getPercent() ? (int) $vp->getPercent() : $vp->getPercent(),
                'amount' => $dollars($item->getVariantCatalogUnitPrice() - $vp->getPrice()), 'catalogUnitPrice' => $dollars($item->getVariantCatalogUnitPrice()),
            ]] : []);
        }
        $cashier = $order->getUserId();
        $taxes = [];
        foreach ($order->getOrderTaxes() as $orderTax) {
            if ((int) round((float) $orderTax->getAmount()) === 0 && ($orderTax->getBase() ?? 0) === 0) {
                continue; // taxe à 0 d'une commande antérieure : non imprimée
            }
            // F18 partie 2a : label (= name), base HT et total TTC du taux en dollars (null sur une commande antérieure sans assiette), catégorie
            // PAYS-1 : valeurs gelées à la vente (une réimpression ne change plus) ; code, ratePpm, jurisdiction, rateSource ajoutés
            $snap = $orderTax->snapshot();
            $base = $orderTax->getBase();
            $taxes[] = ['name' => $snap['label'], 'label' => $snap['label'], 'rate' => $snap['rate'], 'amount' => $dollars($orderTax->getAmount()),
                'base' => $base !== null ? $dollars($base) : null, 'total' => $base !== null ? $dollars($base + (int) round((float) $orderTax->getAmount())) : null, 'category' => $snap['category'],
                'code' => $snap['code'], 'ratePpm' => $snap['ratePpm'], 'jurisdiction' => $snap['jurisdiction'], 'rateSource' => $snap['rateSource']];
        }
        $payments = [];
        $paid = 0;
        foreach ($order->getPayments() as $payment) {
            $method = $payment->getPaymentMethod();
            $amount = (int) round((float) $payment->getAmount());
            $paid += max(0, $amount);
            $card = $method?->getId() === 1;
            $payments[] = [
                'type' => $method?->getId(),
                'label' => $method ? ($method->getTranslation($locale)?->getName() ?? $method->getName()) : null,
                'amount' => $dollars($payment->getAmount()),
                // Détail carte (F3) : marque et 4 derniers chiffres, type card_present | interac_present (terminal) ou declared (carte déclarée)
                'cardBrand' => $card ? ($payment->getStripeCardBrand() ?? $terminal?->getCardBrand()) : null,
                'last4' => $card ? ($payment->getStripeLast4() ?? $terminal?->getLast4()) : null,
                'paymentMethodType' => $card ? ($terminal && $payment->getStripePaymentId() === $terminal->getStripePaymentIntentId() ? $terminal->getPaymentMethodType() : ($payment->getStripePaymentId() ? ($terminal?->getPaymentMethodType() ?? 'card') : 'declared')) : null,
            ];
        }
        // Coupons de fabricant (F17) : une ligne « Coupon fabricant » par coupon dans les paiements ; taxes et total sur le prix plein
        foreach ($coupons as $c) {
            $payments[] = ['type' => 6, 'label' => 'Coupon fabricant – ' . $c['manufacturer'] . ($c['offerCode'] ? ' (offre ' . $c['offerCode'] . ')' : ''), 'amount' => $dollars($c['amount']), 'cardBrand' => null, 'last4' => null, 'paymentMethodType' => null, 'coupon' => ['manufacturer' => $c['manufacturer'], 'offerCode' => $c['offerCode'], 'status' => $c['status']]];
            $paid += max(0, (int) $c['amount']);
        }
        $register = $caisse?->getCashRegister();

        $receipt = $receiptOverride ?? $receipts[\App\Entity\PosReceipt::TICKET] ?? $receipts[\App\Entity\PosReceipt::CREDIT_NOTE] ?? $receipts[\App\Entity\PosReceipt::GIFT_CARD] ?? null;
        $isCreditNote = $receipt?->getKind() === \App\Entity\PosReceipt::CREDIT_NOTE;
        // Nature : vente, échange, retour, annulation ; carte-cadeau vendue (F9) ou annulation d'une carte (avoir sur une commande de type 3)
        $kind = $isCreditNote ? ($order->getOrderType()?->getId() === 2 ? 'return' : ($order->getOrderType()?->getId() === 3 ? 'gift_card_cancellation' : 'cancellation'))
            : ($receipt?->getKind() === \App\Entity\PosReceipt::GIFT_CARD ? 'gift_card' : ($order->getCreditApplied() > 0 ? 'exchange' : 'sale'));
        // Montant dû = total + arrondi − crédit d'échange − part carte-cadeau (F9 : 0 sans carte, comme avant)
        $amountDueCents = (int) round($order->getTotalAmount() + $order->getCashRounding() - $order->getCreditApplied() - $order->getGiftCardApplied() - $order->getManufacturerCouponApplied()); // − coupons (F17)

        return [
            // Ticket numérique (F3) : nature du document, duplicata, lien public (QR), réimpressions, émission
            'kind' => $kind,
            'duplicate' => $duplicate,
            'reprintCount' => $receipt?->getReprintCount() ?? 0,
            'issuedAt' => $receipt?->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'receiptUrl' => $receiptUrl,
            'cashier' => $cashier ? ['id' => $cashier->getId(), 'name' => trim($cashier->getFirstname() . ' ' . $cashier->getLastname())] : null,
            'currency' => $entreprise?->getCurrency() ?? 'CAD',
            'lines' => $lines,
            // Monnaie rendue (vente) : espèces reçues au-delà du montant dû
            'change' => $kind === 'sale' || $kind === 'exchange' || $kind === 'gift_card' ? $dollars(max(0, $paid - $amountDueCents)) : null,
            // Numéro de ticket (vente) ou d'avoir (retour), suite continue par poste (C2)
            'receiptNumber' => $receipt?->getNumber(),
            'receiptKind' => $receipt?->getKind(),
            // Vente hors ligne (C5 partie 2) : numéro provisoire imprimé par la tablette, conservé à côté du numéro officiel
            'provisionalNumber' => $receipt?->getProvisionalNumber(),
            // Numéro présenté avec le préfixe du poste (R1) ; receiptNumber reste l'entier de la suite continue
            'receiptReference' => $receipt ? $receipt->getCashRegister()->receiptReference($receipt->getNumber()) : null,
            // Code-barres Code 128 du ticket, de l'avoir (F2) ou du ticket carte-cadeau (F9) : IZ-T- / IZ-A- / IZ-C-<poste>-<numéro>
            'barcode' => $receipt ? \App\Services\PosReturnService\PosReturnService::barcode($receipt) : null,
            'reference' => $order->getReference(),
            'createdAt' => $order->getOrderDate()?->format(\DateTimeInterface::ATOM),
            'subTotal' => $dollars($order->getSubTotal()),
            // Remises de caisse (F1) : brut avant remises, remises par ligne et de ticket avec motif (0 / vide sans remise)
            'grossSubTotal' => $dollars($order->getSubTotal() + array_sum(array_map(fn ($i) => $i->getDiscountAmount(), $order->getOrderItems()->toArray()))),
            'discounts' => [
                'total' => $dollars(array_sum(array_map(fn ($i) => $i->getDiscountAmount(), $order->getOrderItems()->toArray()))),
                'ticket' => $order->getDiscountAmount() > 0 ? ['amount' => $dollars($order->getDiscountAmount()), 'percent' => $order->getDiscountPercentBp() !== null ? $order->getDiscountPercentBp() / 100 : null, 'reason' => ['code' => $order->getDiscountReasonCode(), 'text' => $order->getDiscountReasonText()]] : null,
                'lines' => array_values(array_map(fn ($i) => ['productVariantId' => $i->getProductVariant()?->getId(), 'quantity' => $i->getQuantity(), 'gross' => $dollars($i->grossTotal()), 'net' => $dollars($i->getTotalPrice()), 'amount' => $dollars($i->getDiscountAmount()),
                    'discounts' => array_map(fn ($d) => ['type' => $d['type'], 'value' => $d['value'], 'amount' => $dollars($d['amount']), 'scope' => $d['scope'], 'reason' => ['code' => $d['reasonCode'] ?? null, 'text' => $d['reasonText'] ?? null]], $i->getDiscounts())],
                    array_filter($order->getOrderItems()->toArray(), fn ($i) => $i->getDiscountAmount() > 0))),
            ],
            'totalTax' => $dollars($order->getTotalTax()),
            'totalAmount' => $dollars($order->getTotalAmount()),
            // Arrondi des espèces (19/10/2026) : montant réellement dû = totalAmount + cashRounding
            'cashRounding' => $dollars($order->getCashRounding()),
            'amountDue' => $dollars($amountDueCents),
            // Échange (F2) : crédit du retour appliqué à la vente ; amountDue en tient compte (0 sans échange)
            'creditApplied' => $dollars($order->getCreditApplied()),
            // Cartes-cadeaux et avoirs (F9, 27/10/2026) : part réglée par carte ou avoir [{kind, last4, amount}], carte émise par ce ticket
            // (type gift_card, code masqué : le code en clair n'est donné qu'à l'émission), avoir émis par ce retour
            'giftCardApplied' => $dollars($order->getGiftCardApplied()),
            'giftCardPayments' => array_map(fn ($g) => ['kind' => $g['kind'], 'last4' => $g['last4'], 'amount' => $dollars($g['amount'])], $giftCards['redemptions'] ?? []),
            'giftCard' => $giftCards['issued'] ?? null,
            'storeCredit' => $giftCards['storeCredit'] ?? null,
            // Coupons de fabricant (F17, 01/11/2026) : part réglée par coupons (taxes sur le prix plein), détail dans payments (type 6)
            'manufacturerCouponApplied' => $dollars($order->getManufacturerCouponApplied()),
            // Fiche client et fidélité (F8, 28/10/2026) : client rattaché (prénom et initiale), points de la vente et solde ; null sans client
            'customer' => $customer,
            'taxes' => $taxes,
            'pricesIncludeTax' => $order->isPricesIncludeTax(), // lignes TTC de cette commande (F18 partie 2b) : subTotal et total TTC, taxes incluses
            'payments' => $payments,
            'cashRegister' => $register ? ['id' => $register->getId(), 'name' => $register->getName()] : null,
            'store' => StoreInfoDto::fromEntreprise($entreprise, $locale), // identifiants dans la langue du site (F18 partie 2a)
            // PAYS-1b (12/11/2026) : vente annulée (avoir d'annulation émis sur la vente elle-même ; ceux des retours F2 sont sur la commande de
            // retour) : la réimpression garde les montants de la vente et imprime « ANNULÉE le … — avoir n° … » ; null sinon
            'cancellation' => $order->getOrderType()?->getId() === 1 && ($cn = $receipts[\App\Entity\PosReceipt::CREDIT_NOTE] ?? null) !== null
                ? ['at' => ($order->getCanceledAt() ?? $cn->getCreatedAt())->format(\DateTimeInterface::ATOM), 'creditNoteNumber' => $cn->getNumber(),
                    'creditNoteReference' => $cn->getCashRegister()->receiptReference($cn->getNumber())]
                : null,
        ];
    }
}
