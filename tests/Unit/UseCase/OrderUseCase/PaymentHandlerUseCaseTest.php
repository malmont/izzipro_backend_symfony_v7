<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\PaymentMethod;
use App\Entity\Payments;
use App\Entity\PaymentType;
use App\Entity\StatusPayment;
use App\Services\OrderService\PaymentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Dto\CreateOrderDTO;
use Doctrine\Common\Collections\ArrayCollection;

class PaymentHandlerUseCaseTest extends TestCase
{
    private $emProvider;
    private $paymentService;
    private $entityManager;
    private $useCase;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->paymentService = $this->createMock(PaymentService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->useCase = new PaymentHandlerUseCase(
            $this->emProvider,
            $this->paymentService
        );
    }

    public function testHandlePaymentSuccessWithProvidedMethodId(): void
    {
        // 1. Setup Data
        $order = $this->createMock(Order::class);
        $amount = 100.0;
        $paymentMethodId = 1;
        $paymentTypeId = 2;
        $statusPaymentId = 3;
        $paymentDate = new \DateTime();
        $orderDTO = $this->createMock(CreateOrderDTO::class);

        // 2. Mock Repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $paymentTypeRepo = $this->createMock(EntityRepository::class);
        $statusPaymentRepo = $this->createMock(EntityRepository::class);

        $paymentMethod = new PaymentMethod();
        $paymentType = new PaymentType();
        $statusPayment = new StatusPayment();

        $this->entityManager->expects($this->exactly(3))
            ->method('getRepository')
            ->will($this->returnValueMap([
                [PaymentMethod::class, $paymentMethodRepo],
                [PaymentType::class, $paymentTypeRepo],
                [StatusPayment::class, $statusPaymentRepo],
            ]));

        $paymentMethodRepo->expects($this->once())->method('find')->with($paymentMethodId)->willReturn($paymentMethod);
        $paymentTypeRepo->expects($this->once())->method('find')->with($paymentTypeId)->willReturn($paymentType);
        $statusPaymentRepo->expects($this->once())->method('find')->with($statusPaymentId)->willReturn($statusPayment);

        // 3. Mock Service Call
        $payment = new Payments();
        $this->paymentService->expects($this->once())
            ->method('createPayment')
            ->with($order, $amount, $paymentMethod, $paymentType, $statusPayment, $paymentDate, $orderDTO)
            ->willReturn($payment);

        // 4. Mock Persistence
        $this->entityManager->expects($this->once())->method('persist')->with($payment);
        $this->entityManager->expects($this->once())->method('flush');

        // 5. Execute
        $this->useCase->handlePayment(
            $order,
            $amount,
            $paymentMethodId,
            $paymentTypeId,
            $statusPaymentId,
            $paymentDate,
            $orderDTO
        );
    }

    public function testHandlePaymentSuccessWithOrderPaymentMethodFallback(): void
    {
        // 1. Setup Data
        $order = $this->createMock(Order::class);
        $amount = 50.0;
        $paymentMethodId = null; // Forces fallback
        $paymentTypeId = 2;
        $statusPaymentId = 3;

        // Mock Order Payments for fallback
        $existingPayment = $this->createMock(Payments::class);
        $paymentMethod = new PaymentMethod();
        $existingPayment->method('getPaymentMethod')->willReturn($paymentMethod);

        $collection = new ArrayCollection([$existingPayment]);
        $order->method('getPayments')->willReturn($collection);

        // 2. Mock Repositories
        $paymentTypeRepo = $this->createMock(EntityRepository::class);
        $statusPaymentRepo = $this->createMock(EntityRepository::class);
        // Note: PaymentMethod repo is NOT called for find() when ID is null, 
        // BUT might be called if implementation explicitly checks repo even for fallback (code check needed).
        // Code says: $paymentMethod = $paymentMethodId ? repo->find... : $order->getPayments()->first()->getPaymentMethod();
        // So repo is NOT used for PaymentMethod.

        $paymentType = new PaymentType();
        $statusPayment = new StatusPayment();

        $this->entityManager->expects($this->exactly(2)) // Only Type and Status repos fetched
            ->method('getRepository')
            ->will($this->returnValueMap([
                [PaymentType::class, $paymentTypeRepo],
                [StatusPayment::class, $statusPaymentRepo],
            ]));

        $paymentTypeRepo->expects($this->once())->method('find')->with($paymentTypeId)->willReturn($paymentType);
        $statusPaymentRepo->expects($this->once())->method('find')->with($statusPaymentId)->willReturn($statusPayment);

        // 3. Mock Service Call
        $payment = new Payments();
        $this->paymentService->expects($this->once())
            ->method('createPayment')
            ->with($order, $amount, $paymentMethod, $paymentType, $statusPayment)
            ->willReturn($payment);

        // 4. Persistence
        $this->entityManager->expects($this->once())->method('persist')->with($payment);
        $this->entityManager->expects($this->once())->method('flush');

        // 5. Execute
        $this->useCase->handlePayment(
            $order,
            $amount,
            $paymentMethodId,
            $paymentTypeId,
            $statusPaymentId
        );
    }

    public function testHandlePaymentThrowsExceptionWhenPaymentMethodNotFound(): void
    {
        $order = $this->createMock(Order::class);
        $paymentMethodId = 999;

        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(PaymentMethod::class)->willReturn($paymentMethodRepo);
        $paymentMethodRepo->method('find')->with($paymentMethodId)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Payment method not found');

        $this->useCase->handlePayment(
            $order,
            100.0,
            $paymentMethodId,
            1,
            1
        );
    }
}
