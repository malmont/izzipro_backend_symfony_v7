<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\PaymentMethod;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use App\Dto\ICreateOrderDTO;

class PaymentService
{
    public function createPayment(
        Order $order,
        float $amount,
        PaymentMethod $paymentMethod,
        PaymentType $paymentType,
        StatusPayment $statusPayment,
        \DateTime $paymentDate = null,
        ?ICreateOrderDTO $orderDTO = null 
    ): Payments {
        $payment = new Payments();
        $payment->setOrderPayment($order);
        $payment->setAmount($amount);
        $payment->setPaymentMethod($paymentMethod);
        $payment->setPaymentType($paymentType);
        $payment->setStatutPayment($statusPayment);
        $payment->setPaymentDate($paymentDate ?: new \DateTime());
        if ($orderDTO !== null) {
        // 🔒 Sécurisation des données Square
        if ($orderDTO->getSquarePaymentId()) {
            $payment->setSquarePaymentId($orderDTO->getSquarePaymentId());
        }
        if ($orderDTO->getSquareOrderId()) {
            $payment->setSquareOrderId($orderDTO->getSquareOrderId());
        }
        if ($orderDTO->getSquareReceiptUrl()) {
            $payment->setSquareReceiptUrl($orderDTO->getSquareReceiptUrl());
        }
        if ($orderDTO->getSquareStatus()) {
            $payment->setSquareStatus($orderDTO->getSquareStatus());
        }
        if ($orderDTO->getSquareCardBrand()) {
            $payment->setSquareCardBrand($orderDTO->getSquareCardBrand());
        }
        if ($orderDTO->getSquareLast4()) {
            $payment->setSquareLast4($orderDTO->getSquareLast4());
        }
        if ($orderDTO->getSquareRiskLevel()) {
            $payment->setSquareRiskLevel($orderDTO->getSquareRiskLevel());
        }
        if ($orderDTO->getStripePaymentId()) {
            $payment->setStripePaymentId($orderDTO->getStripePaymentId());
        }
        if ($orderDTO->getStripeReceiptUrl()) {
            $payment->setStripeReceiptUrl($orderDTO->getStripeReceiptUrl());
        }
        if ($orderDTO->getStripeStatus()) {
            $payment->setStripeStatus($orderDTO->getStripeStatus());
        }
        if ($orderDTO->getStripeCardBrand()) {
            $payment->setStripeCardBrand($orderDTO->getStripeCardBrand());
        }
        if ($orderDTO->getStripeLast4()) {
            $payment->setStripeLast4($orderDTO->getStripeLast4());
        }
        if ($orderDTO->getStripeRiskLevel()) {
            $payment->setStripeRiskLevel($orderDTO->getStripeRiskLevel());
        }
    }
        return $payment;
    }
}
