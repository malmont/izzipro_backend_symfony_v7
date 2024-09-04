<?php
namespace App\UseCase\AdressUseCase;

use App\Services\AdressService\AdressService;

class GetUserAdressesUseCase
{
    private AdressService $adressService;

    public function __construct(AdressService $adressService)
    {
        $this->adressService = $adressService;
    }

    public function execute($user)
    {
        return $this->adressService->getUserAdresses($user);
    }
}
