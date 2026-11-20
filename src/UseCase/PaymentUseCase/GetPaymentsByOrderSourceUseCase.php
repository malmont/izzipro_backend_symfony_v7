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
        return array_map(fn ($payment) => $this->dto($payment, $locale), $this->paymentService->getPaymentsByOrderSource($orderSourceId, $days));
    }

    /**
     * Lot CMD-WEB-1 : une page, mêmes objets que la liste entière ; `null` : toutes les sources.
     *
     * @return array{items: list<PaymentDTO>, total: int}
     */
    public function executePage(?int $orderSourceId, string $locale, ?int $days, int $offset, int $limit): array
    {
        $page = $this->paymentService->getPaymentsPageByOrderSource($orderSourceId, $days, $offset, $limit);

        return ['items' => array_map(fn ($payment) => $this->dto($payment, $locale), $page['items']), 'total' => $page['total']];
    }

    private function dto(\App\Entity\Payments $payment, string $locale): PaymentDTO
    {
        $paymentMethod = $payment->getPaymentMethod();
        $methodTranslation = $paymentMethod ? $paymentMethod->getTranslation($locale) : null;
        $paymentMethodName = $methodTranslation ? $methodTranslation->getName() : ($paymentMethod ? $paymentMethod->getName() : 'N/A');

        $statusPayment = $payment->getStatutPayment();
        $statusTranslation = $statusPayment ? $statusPayment->getTranslation($locale) : null;
        $statusName = $statusTranslation ? $statusTranslation->getName() : ($statusPayment ? $statusPayment->getName() : 'N/A');

        return new PaymentDTO(
            $payment->getId(),
            $payment->getAmount(),
            $payment->getPaymentDate()->format('Y-m-d H:i:s'),
            $payment->getOrderPayment()->getReference(),
            $paymentMethodName,
            $statusName
        );
    }
}