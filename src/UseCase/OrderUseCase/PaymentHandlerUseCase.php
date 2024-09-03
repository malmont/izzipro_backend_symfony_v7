<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\PaymentMethod;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\EntityRetrieverService;

class PaymentHandlerUseCase
{
    private $em;
    private $entityRetrieverService;

    public function __construct(
        EntityManagerInterface $em,
        EntityRetrieverService $entityRetrieverService
    ) {
        $this->em = $em;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    public function handlePayment(Order $order, float $amount, int $paymentMethodId = null, int $paymentTypeId, int $statusPaymentId, \DateTime $paymentDate = null): void
    {
        // Utilisation de EntityRetrieverService pour récupérer les entités
        $paymentMethod = $paymentMethodId 
            ? $this->entityRetrieverService->findOrFail(PaymentMethod::class, $paymentMethodId, 'Payment method not found') 
            : $order->getPayments()->first()->getPaymentMethod();
        
        $paymentType = $this->entityRetrieverService->findOrFail(PaymentType::class, $paymentTypeId, 'Payment type not found');
        $statusPayment = $this->entityRetrieverService->findOrFail(StatusPayment::class, $statusPaymentId, 'Status payment not found');

        // Création et persistance de l'entité Payments
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
