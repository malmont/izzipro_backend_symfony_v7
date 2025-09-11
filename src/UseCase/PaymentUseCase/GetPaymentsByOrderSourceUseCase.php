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
            $translation = $paymentMethod ? $paymentMethod->getTranslation($locale) : null;
            $paymentMethodName = $translation ? $translation->getName() : ($paymentMethod ? $paymentMethod->getName() : 'N/A');
            $statusName = $payment->getStatutPayment() ? $payment->getStatutPayment()->getName() : 'N/A';

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