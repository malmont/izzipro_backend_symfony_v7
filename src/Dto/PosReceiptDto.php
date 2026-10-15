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
    public static function fromOrder(Order $order, ?Entreprise $entreprise, ?Caisse $caisse, string $locale): array
    {
        $dollars = fn (?float $cents) => round(((float) $cents) / 100, 2);
        $taxes = [];
        foreach ($order->getOrderTaxes() as $orderTax) {
            $tax = $orderTax->getTax();
            $taxes[] = ['name' => $tax?->getName() ?? 'Taxe', 'rate' => $tax ? (float) $tax->getRate() : null, 'amount' => $dollars($orderTax->getAmount())];
        }
        $payments = [];
        foreach ($order->getPayments() as $payment) {
            $method = $payment->getPaymentMethod();
            $payments[] = [
                'type' => $method?->getId(),
                'label' => $method ? ($method->getTranslation($locale)?->getName() ?? $method->getName()) : null,
                'amount' => $dollars($payment->getAmount()),
            ];
        }
        $address = $entreprise?->getAddressEntreprise();
        $register = $caisse?->getCashRegister();

        return [
            'reference' => $order->getReference(),
            'createdAt' => $order->getOrderDate()?->format(\DateTimeInterface::ATOM),
            'subTotal' => $dollars($order->getSubTotal()),
            'totalTax' => $dollars($order->getTotalTax()),
            'totalAmount' => $dollars($order->getTotalAmount()),
            'taxes' => $taxes,
            'payments' => $payments,
            'cashRegister' => $register ? ['id' => $register->getId(), 'name' => $register->getName()] : null,
            'store' => $entreprise ? [
                'name' => $entreprise->getName(),
                'address' => $address
                    ? trim(implode(', ', array_filter([$address->getStreet1(), $address->getStreet2(), trim(($address->getZip() ?? '') . ' ' . ($address->getCity() ?? '')), $address->getState(), $address->getCountry()])))
                    : $entreprise->getAdress(),
                'phone' => $entreprise->getTel(),
                'taxNumber' => $entreprise->getTvaIntracommunautaire() ?: $entreprise->getEin(),
            ] : null,
        ];
    }
}
