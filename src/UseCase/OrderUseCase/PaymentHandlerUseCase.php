<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\PaymentMethod;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\PaymentService;
use App\Dto\ICreateOrderDTO; 

class PaymentHandlerUseCase
{
    private $em;
    private $entityRetrieverService;
    private $paymentService;

    public function __construct(
        EntityManagerInterface $em,
        EntityRetrieverService $entityRetrieverService,
        PaymentService $paymentService
    ) {
        $this->em = $em;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->paymentService = $paymentService;
    }

    public function handlePayment(
        Order $order,
        float $amount,
        int $paymentMethodId = null,
        int $paymentTypeId,
        int $statusPaymentId,
        \DateTime $paymentDate = null,
        ICreateOrderDTO $orderDTO = null
    ): void {
        // Récupération des entités nécessaires
        $paymentMethod = $paymentMethodId
            ? $this->entityRetrieverService->findOrFail(PaymentMethod::class, $paymentMethodId, 'Payment method not found')
            : $order->getPayments()->first()->getPaymentMethod();
    
        $paymentType = $this->entityRetrieverService->findOrFail(PaymentType::class, $paymentTypeId, 'Payment type not found');
        $statusPayment = $this->entityRetrieverService->findOrFail(StatusPayment::class, $statusPaymentId, 'Status payment not found');
    
        // Création du paiement
        $payment = $this->paymentService->createPayment(
            $order,
            $amount,
            $paymentMethod,
            $paymentType,
            $statusPayment,
            $paymentDate,
            $orderDTO  // ✅ Peut être null
        );
    
        // Persistance du remboursement
        $this->em->persist($payment);
        $this->em->flush();
    }
    
}