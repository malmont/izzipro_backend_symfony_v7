<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\PaymentMethod;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use App\Dto\ICreateOrderDTO;
use App\Services\OrderService\PaymentService;
use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    public function testCreatePaymentWithSquareData(): void
    {
        $service = new PaymentService();

        $order = $this->createMock(Order::class);
        $paymentMethod = $this->createMock(PaymentMethod::class);
        $paymentType = $this->createMock(PaymentType::class);
        $statusPayment = $this->createMock(StatusPayment::class);
        $paymentDate = new \DateTime('2023-01-01 12:00:00');

        $dto = $this->createMock(ICreateOrderDTO::class);
        $dto->method('getSquarePaymentId')->willReturn('sq_pay_123');
        $dto->method('getSquareOrderId')->willReturn('sq_order_456');
        $dto->method('getSquareReceiptUrl')->willReturn('https://square.com/receipt');
        $dto->method('getSquareStatus')->willReturn('COMPLETED');
        $dto->method('getSquareCardBrand')->willReturn('VISA');
        $dto->method('getSquareLast4')->willReturn('1234');
        $dto->method('getSquareRiskLevel')->willReturn('NORMAL');

        $payment = $service->createPayment(
            $order,
            100.50,
            $paymentMethod,
            $paymentType,
            $statusPayment,
            $paymentDate,
            $dto
        );

        $this->assertInstanceOf(Payments::class, $payment);
        $this->assertEquals($order, $payment->getOrderPayment());
        $this->assertEquals(100.50, $payment->getAmount());
        $this->assertEquals($paymentMethod, $payment->getPaymentMethod());
        $this->assertEquals($paymentType, $payment->getPaymentType());
        $this->assertEquals($statusPayment, $payment->getStatutPayment());
        $this->assertEquals($paymentDate, $payment->getPaymentDate());

        // Square assertions
        $this->assertEquals('sq_pay_123', $payment->getSquarePaymentId());
        $this->assertEquals('sq_order_456', $payment->getSquareOrderId());
        $this->assertEquals('https://square.com/receipt', $payment->getSquareReceiptUrl());
        $this->assertEquals('COMPLETED', $payment->getSquareStatus());
        $this->assertEquals('VISA', $payment->getSquareCardBrand());
        $this->assertEquals('1234', $payment->getSquareLast4());
        $this->assertEquals('NORMAL', $payment->getSquareRiskLevel());
    }

    public function testCreatePaymentWithStripeData(): void
    {
        $service = new PaymentService();

        $order = $this->createMock(Order::class);
        $paymentMethod = $this->createMock(PaymentMethod::class);
        $paymentType = $this->createMock(PaymentType::class);
        $statusPayment = $this->createMock(StatusPayment::class);

        $dto = $this->createMock(ICreateOrderDTO::class);
        $dto->method('getStripePaymentId')->willReturn('pi_123');
        $dto->method('getStripeReceiptUrl')->willReturn('https://stripe.com/receipt');
        $dto->method('getStripeStatus')->willReturn('succeeded');
        $dto->method('getStripeCardBrand')->willReturn('mastercard');
        $dto->method('getStripeLast4')->willReturn('5678');
        $dto->method('getStripeRiskLevel')->willReturn('low');

        $payment = $service->createPayment(
            $order,
            50.00,
            $paymentMethod,
            $paymentType,
            $statusPayment,
            null, // Test default date
            $dto
        );

        $this->assertInstanceOf(Payments::class, $payment);
        $this->assertNotNull($payment->getPaymentDate()); // Should default to now
        $this->assertEquals(50.00, $payment->getAmount());

        // Stripe assertions
        $this->assertEquals('pi_123', $payment->getStripePaymentId());
        $this->assertEquals('https://stripe.com/receipt', $payment->getStripeReceiptUrl());
        $this->assertEquals('succeeded', $payment->getStripeStatus());
        $this->assertEquals('mastercard', $payment->getStripeCardBrand());
        $this->assertEquals('5678', $payment->getStripeLast4());
        $this->assertEquals('low', $payment->getStripeRiskLevel());
    }

    public function testCreatePaymentWithoutDTO(): void
    {
        $service = new PaymentService();

        $order = $this->createMock(Order::class);
        $paymentMethod = $this->createMock(PaymentMethod::class);
        $paymentType = $this->createMock(PaymentType::class);
        $statusPayment = $this->createMock(StatusPayment::class);

        $payment = $service->createPayment(
            $order,
            75.00,
            $paymentMethod,
            $paymentType,
            $statusPayment
        );

        $this->assertInstanceOf(Payments::class, $payment);
        $this->assertEquals(75.00, $payment->getAmount());

        // Ensure no errors occurred and fields are empty/null if no DTO
        $this->assertNull($payment->getSquarePaymentId());
        $this->assertNull($payment->getStripePaymentId());
    }
}
