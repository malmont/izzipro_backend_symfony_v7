<?php

namespace App\Tests\Unit\UseCase\PaymentUseCase;

use App\Dto\PaymentDTO;
use App\Entity\Order;
use App\Entity\PaymentMethod;
use App\Entity\Payments;
use App\Entity\StatusPayment;
use App\Services\PaymentService\PaymentService;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use PHPUnit\Framework\TestCase;

class GetPaymentsByOrderSourceUseCaseTest extends TestCase
{
    private $paymentService;
    private $useCase;

    protected function setUp(): void
    {
        $this->paymentService = $this->createMock(PaymentService::class);
        $this->useCase = new GetPaymentsByOrderSourceUseCase($this->paymentService);
    }

    public function testExecuteSuccessWithTranslations(): void
    {
        // 1. Setup Data
        $orderSourceId = 1;
        $host = 'example.com';
        $locale = 'fr';

        // 2. Mock Entities
        $payment = $this->createMock(Payments::class);
        $paymentMethod = $this->createMock(PaymentMethod::class);
        $statusPayment = $this->createMock(StatusPayment::class);
        $order = $this->createMock(Order::class);

        // Translations (Mocking the translation object interface implicitly via method chaining)
        $methodTranslation = new \stdClass();
        $methodTranslation->getName = function () {
            return 'Carte Bancaire';
        }; // Assuming dynamic call or mock object
        // Actually, let's mock the translation object properly if possible, or use stdClass if it's just a getter.
        // The code uses: $paymentMethod->getTranslation($locale)->getName()

        $methodTranslationMock = $this->createMock(\App\Entity\PaymentMethodTranslation::class);
        $methodTranslationMock->method('getName')->willReturn('Carte Bancaire FR');

        $statusTranslationMock = $this->createMock(\App\Entity\StatusPaymentTranslation::class);
        $statusTranslationMock->method('getName')->willReturn('Payé FR');

        // Configure Payment Method
        $paymentMethod->method('getTranslation')->with('fr')->willReturn($methodTranslationMock);

        // Configure Status Payment
        $statusPayment->method('getTranslation')->with('fr')->willReturn($statusTranslationMock);

        // Configure Payment
        $payment->method('getId')->willReturn(101);
        $payment->method('getAmount')->willReturn(50.0);
        $payment->method('getPaymentDate')->willReturn(new \DateTime('2023-01-01 12:00:00'));
        $payment->method('getOrderPayment')->willReturn($order);
        $payment->method('getPaymentMethod')->willReturn($paymentMethod);
        $payment->method('getStatutPayment')->willReturn($statusPayment);

        // Configure Order
        $order->method('getReference')->willReturn('ORDER-123');

        // 3. Mock Service
        $this->paymentService->expects($this->once())
            ->method('getPaymentsByOrderSource')
            ->with($orderSourceId, null)
            ->willReturn([$payment]);

        // 4. Execute
        $result = $this->useCase->execute($orderSourceId, $host, $locale);

        // 5. Verify
        $this->assertCount(1, $result);
        $this->assertInstanceOf(PaymentDTO::class, $result[0]);

        $data = $result[0]->toArray();
        $this->assertEquals(101, $data['id']);
        $this->assertEquals(50.0, $data['amount']);
        $this->assertEquals('Carte Bancaire FR', $data['paymentMethod']);
        $this->assertEquals('Payé FR', $data['paymentStatus']);
    }

    public function testExecuteFallbackToDefaultNames(): void
    {
        // Case where translations are missing, should fallback to entity getName()
        $locale = 'fr';

        $payment = $this->createMock(Payments::class);
        $paymentMethod = $this->createMock(PaymentMethod::class);
        $statusPayment = $this->createMock(StatusPayment::class);
        $order = $this->createMock(Order::class);

        // Mock missing translations
        $paymentMethod->method('getTranslation')->with('fr')->willReturn(null);
        $paymentMethod->method('getName')->willReturn('Credit Card Default');

        $statusPayment->method('getTranslation')->with('fr')->willReturn(null);
        $statusPayment->method('getName')->willReturn('Paid Default');

        $payment->method('getId')->willReturn(102);
        $payment->method('getAmount')->willReturn(100.0);
        $payment->method('getPaymentDate')->willReturn(new \DateTime('2023-01-02 12:00:00'));
        $payment->method('getOrderPayment')->willReturn($order);
        $payment->method('getPaymentMethod')->willReturn($paymentMethod);
        $payment->method('getStatutPayment')->willReturn($statusPayment);

        $order->method('getReference')->willReturn('ORDER-456');

        $this->paymentService->method('getPaymentsByOrderSource')->willReturn([$payment]);

        $result = $this->useCase->execute(1, 'host', $locale);

        $data = $result[0]->toArray();
        $this->assertEquals('Credit Card Default', $data['paymentMethod']);
        $this->assertEquals('Paid Default', $data['paymentStatus']);
    }

    public function testExecuteNullMethodAndStatus(): void
    {
        // Case where payment method and status are null
        $payment = $this->createMock(Payments::class);
        $order = $this->createMock(Order::class);

        $payment->method('getId')->willReturn(103);
        $payment->method('getAmount')->willReturn(20.0);
        $payment->method('getPaymentDate')->willReturn(new \DateTime('2023-01-03 12:00:00'));
        $payment->method('getOrderPayment')->willReturn($order);
        $payment->method('getPaymentMethod')->willReturn(null);
        $payment->method('getStatutPayment')->willReturn(null);

        $order->method('getReference')->willReturn('ORDER-789');

        $this->paymentService->method('getPaymentsByOrderSource')->willReturn([$payment]);

        $result = $this->useCase->execute(1, 'host', 'fr');

        $data = $result[0]->toArray();
        $this->assertEquals('N/A', $data['paymentMethod']);
        $this->assertEquals('N/A', $data['paymentStatus']);
    }

    public function testExecuteNoPayments(): void
    {
        $this->paymentService->expects($this->once())
            ->method('getPaymentsByOrderSource')
            ->willReturn([]);

        $result = $this->useCase->execute(1, 'host', 'fr');

        $this->assertEmpty($result);
    }
}
