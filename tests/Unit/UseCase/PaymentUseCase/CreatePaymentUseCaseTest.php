<?php

namespace App\Tests\Unit\UseCase\PaymentUseCase;

use App\Services\StripePaymentService\StripePaymentService;
use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use PHPUnit\Framework\TestCase;
use Stripe\PaymentIntent;

class CreatePaymentUseCaseTest extends TestCase
{
    private $stripePaymentService;
    private $useCase;

    protected function setUp(): void
    {
        $this->stripePaymentService = $this->createMock(StripePaymentService::class);
        $this->useCase = new CreatePaymentUseCase($this->stripePaymentService);
    }

    public function testExecuteSuccess(): void
    {
        // 1. Setup Data
        $paymentIntentId = 'pi_12345';

        // 2. Mock Stripe Objects
        // We simulate the structure: $paymentIntent->charges->data[0]->receipt_url, etc.
        $charge = new \stdClass();
        $charge->receipt_url = 'https://receipt.url';

        $card = new \stdClass();
        $card->brand = 'visa';
        $card->last4 = '4242';

        $paymentMethodDetails = new \stdClass();
        $paymentMethodDetails->card = $card;
        $charge->payment_method_details = $paymentMethodDetails;

        $outcome = new \stdClass();
        $outcome->risk_level = 'normal';
        $charge->outcome = $outcome;

        $charges = new \stdClass();
        $charges->data = [$charge];

        $paymentIntent = new \stdClass();
        $paymentIntent->id = 'pi_12345';
        $paymentIntent->status = 'succeeded';
        $paymentIntent->charges = $charges;

        // 3. Mock Service Response
        $this->stripePaymentService->expects($this->once())
            ->method('createPayment')
            ->with($paymentIntentId)
            ->willReturn([
                'success' => true,
                'payment' => $paymentIntent
            ]);

        // 4. Execute
        $response = $this->useCase->execute($paymentIntentId);

        // 5. Verify
        $this->assertTrue($response['success']);
        $this->assertEquals('pi_12345', $response['payment']['stripePaymentId']);
        $this->assertEquals('succeeded', $response['payment']['status']);
        $this->assertEquals('https://receipt.url', $response['payment']['receiptUrl']);
        $this->assertEquals('visa', $response['payment']['cardBrand']);
        $this->assertEquals('normal', $response['payment']['riskLevel']);
    }

    public function testExecuteFailure(): void
    {
        $paymentIntentId = 'pi_fail';
        $errors = ['error' => 'Something went wrong'];

        $this->stripePaymentService->expects($this->once())
            ->method('createPayment')
            ->with($paymentIntentId)
            ->willReturn([
                'success' => false,
                'errors' => $errors
            ]);

        $response = $this->useCase->execute($paymentIntentId);

        $this->assertFalse($response['success']);
        $this->assertEquals($errors, $response['errors']);
    }
}
