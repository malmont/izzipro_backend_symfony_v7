<?php
namespace App\UseCase\PaymentUseCase;

use App\Services\PaymentService\PaymentService;
use App\Dto\PaymentDTO;

class GetPaymentsByOrderSourceUseCase
{
    private $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function execute(int $orderSourceId, string $host, string $locale, ?int $days = null): array
    {
        $payments = $this->paymentService->getPaymentsByOrderSource($orderSourceId, $days);
        $paymentDTOs = [];

        foreach ($payments as $payment) {

            $paymentMethod = $payment->getPaymentMethod();
            $methodTranslation = $paymentMethod ? $paymentMethod->getTranslation($locale) : null;
            $paymentMethodName = $methodTranslation ? $methodTranslation->getName() : ($paymentMethod ? $paymentMethod->getName() : 'N/A');

            $statusPayment = $payment->getStatutPayment();
            $statusTranslation = $statusPayment ? $statusPayment->getTranslation($locale) : null;
            $statusName = $statusTranslation ? $statusTranslation->getName() : ($statusPayment ? $statusPayment->getName() : 'N/A');


            $paymentDTOs[] = new PaymentDTO(
                $payment->getId(),
                $payment->getAmount(),
                $payment->getPaymentDate()->format('Y-m-d H:i:s'),
                $payment->getOrderPayment()->getReference(),
                $paymentMethodName,
                $statusName 
            );
        }

        return $paymentDTOs;
    }
}