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

    public function execute(int $orderSourceId, string $host, ?int $days = null): array
    {
        $payments = $this->paymentService->getPaymentsByOrderSource($orderSourceId, $days);
        $paymentDTOs = [];

        foreach ($payments as $payment) {
            $paymentDTOs[] = new PaymentDTO(
                $payment->getId(),
                $payment->getAmount(),
                $payment->getPaymentDate()->format('Y-m-d H:i:s'),
                $payment->getOrderPayment()->getReference(),
                $payment->getPaymentMethod()->getName(),
                $payment->getStatutPayment()->getName()
            );
        }

        return $paymentDTOs;
    }
}
