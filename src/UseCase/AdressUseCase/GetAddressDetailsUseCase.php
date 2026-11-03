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

    /** @param string|null $language langue des noms de pays et de ville (fr, en), celle de la requête */
    public function execute(AddressDetailsInputDto $dto, ?string $language = null): AddressDetailsResultDto
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $msg = [];
            foreach ($errors as $v) {
                $msg[] = $v->getPropertyPath().': '.$v->getMessage();
            }
            throw new BadRequestHttpException(implode('; ', $msg));
        }

        $raw = $this->autocompleteService->getDetails($dto->placeId, $language);
        return new AddressDetailsResultDto($raw);
    }
}
