<?php
namespace App\UseCase\PaymentUseCase;


use App\Services\SquarePaymentService\SquarePaymentService;

class CreatePaymentUseCase
{
    private $paymentService;

    public function __construct(SquarePaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function execute(string $nonce, int $amount): array
    {
        return $this->paymentService->createPayment($nonce, $amount);
    }
}
