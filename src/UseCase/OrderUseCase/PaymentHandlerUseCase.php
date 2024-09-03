<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\Payments;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\PaymentMethodRepository;
use App\Repository\PaymentTypeRepository;
use App\Repository\StatusPaymentRepository;

class PaymentHandlerUseCase
{
    private $em;
    private $paymentMethodRepository;
    private $paymentTypeRepository;
    private $statusPaymentRepository;

    public function __construct(
        EntityManagerInterface $em,
        PaymentMethodRepository $paymentMethodRepository,
        PaymentTypeRepository $paymentTypeRepository,
        StatusPaymentRepository $statusPaymentRepository
    ) {
        $this->em = $em;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentTypeRepository = $paymentTypeRepository;
        $this->statusPaymentRepository = $statusPaymentRepository;
    }

    public function handlePayment(Order $order, float $amount, int $paymentMethodId = null, int $paymentTypeId, int $statusPaymentId, \DateTime $paymentDate = null): void
    {
        $paymentMethod = $paymentMethodId ? $this->paymentMethodRepository->find($paymentMethodId) : $order->getPayments()->first()->getPaymentMethod();
        $paymentType = $this->paymentTypeRepository->find($paymentTypeId);
        $statusPayment = $this->statusPaymentRepository->find($statusPaymentId);

        $payment = new Payments();
        $payment->setOrderPayment($order);
        $payment->setAmount($amount);
        $payment->setPaymentMethod($paymentMethod);
        $payment->setPaymentType($paymentType);
        $payment->setStatutPayment($statusPayment);
        $payment->setPaymentDate($paymentDate ?: new \DateTime());

        $this->em->persist($payment);
        $this->em->flush();
    }
}
