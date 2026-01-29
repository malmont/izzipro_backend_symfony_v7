<?php

namespace App\Tests\UseCase\AdressUseCase;

use App\Dto\AddressSuggestionInputDto;
use App\Dto\AddressSuggestionResultDto;
use App\Services\AdressService\AddressAutocompleteService;
use App\UseCase\AdressUseCase\SuggestAddressUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SuggestAddressUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {
        // Arrange
        $autocompleteService = $this->createMock(AddressAutocompleteService::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $inputDto = new AddressSuggestionInputDto(['q' => 'Montreal']);

        $validator->expects($this->once())
            ->method('validate')
            ->with($inputDto)
            ->willReturn(new ConstraintViolationList());

        $expectedRawResults = [
            ['description' => 'Montreal, QC, Canada', 'place_id' => 'place1'],
            ['description' => 'Montreal-Est, QC, Canada', 'place_id' => 'place2'],
        ];

        $autocompleteService->expects($this->once())
            ->method('suggest')
            ->with('Montreal')
            ->willReturn($expectedRawResults);

        $useCase = new SuggestAddressUseCase($autocompleteService, $validator);

        // Act
        $results = $useCase->execute($inputDto);

        // Assert
        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(AddressSuggestionResultDto::class, $results);
        $this->assertEquals('Montreal, QC, Canada', $results[0]->description);
        $this->assertEquals('place1', $results[0]->placeId);
    }

    public function testExecuteThrowsExceptionOnValidationError()
    {
        // Arrange
        $autocompleteService = $this->createMock(AddressAutocompleteService::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $inputDto = new AddressSuggestionInputDto([]); // Empty query

        $violation = new ConstraintViolation('Error message', null, [], $inputDto, 'query', 'invalid');
        $violations = new ConstraintViolationList([$violation]);

        $validator->expects($this->once())
            ->method('validate')
            ->with($inputDto)
            ->willReturn($violations);

        $useCase = new SuggestAddressUseCase($autocompleteService, $validator);

        // Assert
        $this->expectException(BadRequestHttpException::class);

        // Act
        $useCase->execute($inputDto);
    }
}
