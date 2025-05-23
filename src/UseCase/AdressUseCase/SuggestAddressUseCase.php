<?php

namespace App\UseCase\AdressUseCase;

use App\Dto\AddressSuggestionInputDto;
use App\Dto\AddressSuggestionResultDto;
use App\Services\AdressService\AddressAutocompleteService;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SuggestAddressUseCase
{
    public function __construct(
        private AddressAutocompleteService $autocompleteService,
        private ValidatorInterface         $validator
    ) {}

    /**
     * @return AddressSuggestionResultDto[]
     */
    public function execute(AddressSuggestionInputDto $dto): array
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $msg = [];
            foreach ($errors as $v) {
                $msg[] = $v->getPropertyPath().': '.$v->getMessage();
            }
            throw new BadRequestHttpException(implode('; ', $msg));
        }

        $raw = $this->autocompleteService->suggest($dto->query);

        return array_map(fn(array $r) =>
            new AddressSuggestionResultDto($r['description'], $r['place_id']),
            $raw
        );
    }
}
