<?php
namespace App\UseCase\AdressUseCase;

use App\Dto\AddressDetailsInputDto;
use App\Dto\AddressDetailsResultDto;
use App\Services\AdressService\AddressAutocompleteService;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class GetAddressDetailsUseCase
{
    public function __construct(
        private AddressAutocompleteService $autocompleteService,
        private ValidatorInterface         $validator
    ) {}

    public function execute(AddressDetailsInputDto $dto): AddressDetailsResultDto
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $msg = [];
            foreach ($errors as $v) {
                $msg[] = $v->getPropertyPath().': '.$v->getMessage();
            }
            throw new BadRequestHttpException(implode('; ', $msg));
        }

        $raw = $this->autocompleteService->getDetails($dto->placeId);
        return new AddressDetailsResultDto($raw);
    }
}
