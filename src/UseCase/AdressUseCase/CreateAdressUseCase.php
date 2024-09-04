<?php
namespace App\UseCase\AdressUseCase;

use App\Dto\AdressInputDTO;
use App\Services\AdressService\AdressService;

class CreateAdressUseCase
{
    private AdressService $adressService;

    public function __construct(AdressService $adressService)
    {
        $this->adressService = $adressService;
    }

    public function execute(AdressInputDTO $inputDTO, $user)
    {
        return $this->adressService->createAdress($inputDTO, $user);
    }
}
