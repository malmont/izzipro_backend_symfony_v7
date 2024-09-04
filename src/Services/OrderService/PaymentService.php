<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\PaymentMethod;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;

class PaymentService
{
    public function createPayment(
        Order $order,
        float $amount,
        PaymentMethod $paymentMethod,
        PaymentType $paymentType,
        StatusPayment $statusPayment,
        \DateTime $paymentDate = null
    ): Payments {
        $payment = new Payments();
        $payment->setOrderPayment($order);
        $payment->setAmount($amount);
        $payment->setPaymentMethod($paymentMethod);
        $payment->setPaymentType($paymentType);
        $payment->setStatutPayment($statusPayment);
        $payment->setPaymentDate($paymentDate ?: new \DateTime());

        return $payment;
    }
}
