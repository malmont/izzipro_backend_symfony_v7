<?php

namespace App\Tests\Unit\Controller\BookingController;

use App\Controller\BookingController\BookingController;
use App\Dto\AvailabilityCheckDto;
use App\UseCase\Booking\CheckAvailabilityUseCase;
use App\UseCase\Booking\GetCalendarAvailabilityUseCase;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BookingControllerTest extends TestCase
{
    private $checkAvailabilityUseCase;
    private $getCalendarAvailabilityUseCase;
    private $validator;
    private $controller;
    private $container;
    private $serializer;

    protected function setUp(): void
    {
        $this->checkAvailabilityUseCase = $this->createMock(CheckAvailabilityUseCase::class);
        $this->getCalendarAvailabilityUseCase = $this->createMock(GetCalendarAvailabilityUseCase::class);
        $this->validator = $this->createMock(ValidatorInterface::class);

        $this->controller = new BookingController();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->controller->setContainer($this->container);

        // Setup container for abstract controller json() helper
        $this->container->method('has')->with('serializer')->willReturn(true);
        $this->container->method('get')->with('serializer')->willReturn($this->serializer);

        // Default serializer behavior
        $this->serializer->method('serialize')->willReturn('{"result": "mock"}');
    }

    public function testCheckSuccess(): void
    {
        $productId = 123;
        $request = new Request([
            'quantity' => 2,
            'start' => '2023-10-01 10:00',
            'end' => '2023-10-01 12:00'
        ]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $expectedResult = ['available' => true];
        $this->checkAvailabilityUseCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (AvailabilityCheckDto $dto) use ($productId) {
                return $dto->productId === $productId &&
                    $dto->quantity === 2 &&
                    $dto->startAt->format('Y-m-d H:i') === '2023-10-01 10:00' &&
                    $dto->endAt->format('Y-m-d H:i') === '2023-10-01 12:00';
            }))
            ->willReturn($expectedResult);

        $response = $this->controller->check($productId, $request, $this->validator, $this->checkAvailabilityUseCase);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCheckInvalidDate(): void
    {
        $productId = 123;
        $request = new Request([
            'start' => 'invalid-date',
            'end' => '2023-10-01 12:00'
        ]);

        $response = $this->controller->check($productId, $request, $this->validator, $this->checkAvailabilityUseCase);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCheckValidationErrors(): void
    {
        $productId = 123;
        $request = new Request([
            'start' => '2023-10-01 10:00',
            'end' => '2023-10-01 12:00'
        ]);

        $violation = new ConstraintViolation('Error message', null, [], null, 'property', null);
        $errors = new ConstraintViolationList([$violation]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn($errors);

        $response = $this->controller->check($productId, $request, $this->validator, $this->checkAvailabilityUseCase);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCheckStockError(): void
    {
        $productId = 123;
        $request = new Request([
            'start' => '2023-10-01 10:00',
            'end' => '2023-10-01 12:00'
        ]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->checkAvailabilityUseCase->expects($this->once())
            ->method('execute')
            ->willThrowException(new \Exception('Stock insuffisant'));

        $response = $this->controller->check($productId, $request, $this->validator, $this->checkAvailabilityUseCase);

        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testCalendarSuccess(): void
    {
        $productId = 123;
        $request = new Request([
            'start' => '2023-10-01',
            'end' => '2023-10-31'
        ]);

        $expectedResult = [['date' => '2023-10-01', 'available' => true]];

        $this->getCalendarAvailabilityUseCase->expects($this->once())
            ->method('execute')
            ->with(
                $productId,
                $this->callback(function (\DateTimeImmutable $date) {
                    return $date->format('Y-m-d H:i:s') === '2023-10-01 00:00:00';
                }),
                $this->callback(function (\DateTimeImmutable $date) {
                    return $date->format('Y-m-d H:i:s') === '2023-10-31 23:59:59';
                })
            )
            ->willReturn($expectedResult);

        $response = $this->controller->calendar($productId, $request, $this->getCalendarAvailabilityUseCase);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCalendarMissingParams(): void
    {
        $productId = 123;
        $request = new Request([]); // Missing start/end

        $response = $this->controller->calendar($productId, $request, $this->getCalendarAvailabilityUseCase);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
}
