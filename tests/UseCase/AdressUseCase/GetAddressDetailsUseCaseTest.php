<?php

namespace App\Tests\UseCase\AdressUseCase;

use App\Dto\AddressDetailsInputDto;
use App\Dto\AddressDetailsResultDto;
use App\Services\AdressService\AddressAutocompleteService;
use App\UseCase\AdressUseCase\GetAddressDetailsUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class GetAddressDetailsUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {
        // Arrange
        $autocompleteService = $this->createMock(AddressAutocompleteService::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $inputDto = new AddressDetailsInputDto(['place_id' => 'place1']);

        $validator->expects($this->once())
            ->method('validate')
            ->with($inputDto)
            ->willReturn(new ConstraintViolationList());

        $expectedRawDetails = [
            'street1' => '123 Main St',
            'street2' => null,
            'city' => 'Montreal',
            'province' => 'QC',
            'postal_code' => 'H3Z 2Y7',
            'country' => 'Canada'
        ];

        $autocompleteService->expects($this->once())
            ->method('getDetails')
            ->with('place1')
            ->willReturn($expectedRawDetails);

        $useCase = new GetAddressDetailsUseCase($autocompleteService, $validator);

        $result = $useCase->execute($inputDto);
        $this->assertInstanceOf(AddressDetailsResultDto::class, $result);
        $this->assertEquals('123 Main St', $result->street1);
        $this->assertEquals('Montreal', $result->city);
        $this->assertEquals('QC', $result->province);
        $this->assertEquals('H3Z 2Y7', $result->postalCode);
        $this->assertEquals('Canada', $result->country);
    }

    public function testExecuteThrowsExceptionOnValidationError()
    {
        $autocompleteService = $this->createMock(AddressAutocompleteService::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $inputDto = new AddressDetailsInputDto([]); // Empty place_id

        $violation = new ConstraintViolation('Error message', null, [], $inputDto, 'placeId', 'invalid');
        $violations = new ConstraintViolationList([$violation]);

        $validator->expects($this->once())
            ->method('validate')
            ->with($inputDto)
            ->willReturn($violations);

        $useCase = new GetAddressDetailsUseCase($autocompleteService, $validator);
        $this->expectException(BadRequestHttpException::class);
        $useCase->execute($inputDto);
    }
}
