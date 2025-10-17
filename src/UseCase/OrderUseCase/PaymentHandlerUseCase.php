<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\PaymentMethod;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use App\Services\OrderService\PaymentService;
use App\Dto\ICreateOrderDTO;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException; // Pour les erreurs

class PaymentHandlerUseCase
{
    // MODIFICATION 1 : Le constructeur est simplifié
    private TenantEntityManagerProvider $emProvider;
    private PaymentService $paymentService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        PaymentService $paymentService
    ) {
        $this->emProvider = $emProvider;
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
        $em = $this->emProvider->getEntityManager();

        $paymentMethod = $paymentMethodId
            ? $em->getRepository(PaymentMethod::class)->find($paymentMethodId)
            : $order->getPayments()->first()->getPaymentMethod();

        if (!$paymentMethod) {
            throw new NotFoundHttpException('Payment method not found');
        }
    
        $paymentType = $em->getRepository(PaymentType::class)->find($paymentTypeId);
        if (!$paymentType) {
            throw new NotFoundHttpException('Payment type not found');
        }

        $statusPayment = $em->getRepository(StatusPayment::class)->find($statusPaymentId);
        if (!$statusPayment) {
            throw new NotFoundHttpException('Status payment not found');
        }
    
        // L'appel à ce service est correct, en supposant qu'il est aussi tenant-aware
        $payment = $this->paymentService->createPayment(
            $order,
            $amount,
            $paymentMethod,
            $paymentType,
            $statusPayment,
            $paymentDate,
            $orderDTO
        );
        // On persiste le nouveau paiement sur l'EM du tenant
        $em->persist($payment);
        $em->flush();
    }
}