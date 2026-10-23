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
        ?\App\Entity\PosTerminalPayment $terminal = null, ?string $receiptUrl = null, bool $duplicate = false, ?\App\Entity\PosReceipt $receiptOverride = null): array
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
            ];
        }
        $cashier = $order->getUserId();
        $taxes = [];
        foreach ($order->getOrderTaxes() as $orderTax) {
            $tax = $orderTax->getTax();
            $taxes[] = ['name' => $tax?->getName() ?? 'Taxe', 'rate' => $tax ? (float) $tax->getRate() : null, 'amount' => $dollars($orderTax->getAmount())];
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
        $register = $caisse?->getCashRegister();

        $receipt = $receiptOverride ?? $receipts[\App\Entity\PosReceipt::TICKET] ?? $receipts[\App\Entity\PosReceipt::CREDIT_NOTE] ?? null;
        $isCreditNote = $receipt?->getKind() === \App\Entity\PosReceipt::CREDIT_NOTE;
        $kind = $isCreditNote ? ($order->getOrderType()?->getId() === 2 ? 'return' : 'cancellation') : ($order->getCreditApplied() > 0 ? 'exchange' : 'sale');
        $amountDueCents = (int) round($order->getTotalAmount() + $order->getCashRounding() - $order->getCreditApplied());

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
            'change' => $kind === 'sale' || $kind === 'exchange' ? $dollars(max(0, $paid - $amountDueCents)) : null,
            // Numéro de ticket (vente) ou d'avoir (retour), suite continue par poste (C2)
            'receiptNumber' => $receipt?->getNumber(),
            'receiptKind' => $receipt?->getKind(),
            // Vente hors ligne (C5 partie 2) : numéro provisoire imprimé par la tablette, conservé à côté du numéro officiel
            'provisionalNumber' => $receipt?->getProvisionalNumber(),
            // Numéro présenté avec le préfixe du poste (R1) ; receiptNumber reste l'entier de la suite continue
            'receiptReference' => $receipt ? $receipt->getCashRegister()->receiptReference($receipt->getNumber()) : null,
            // Code-barres Code 128 du ticket ou de l'avoir (F2) : IZ-T-<poste>-<numéro> / IZ-A-<poste>-<numéro>
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
            'amountDue' => $dollars($order->getTotalAmount() + $order->getCashRounding() - $order->getCreditApplied()),
            // Échange (F2) : crédit du retour appliqué à la vente ; amountDue en tient compte (0 sans échange)
            'creditApplied' => $dollars($order->getCreditApplied()),
            'taxes' => $taxes,
            'payments' => $payments,
            'cashRegister' => $register ? ['id' => $register->getId(), 'name' => $register->getName()] : null,
            'store' => StoreInfoDto::fromEntreprise($entreprise),
        ];
    }
}
