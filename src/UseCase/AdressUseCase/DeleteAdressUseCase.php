<?php
namespace App\UseCase\AdressUseCase;

use App\Entity\Adress;
use App\Services\AdressService\AdressService;

class DeleteAdressUseCase
{
    private AdressService $adressService;

    public function __construct(AdressService $adressService)
    {
        $this->adressService = $adressService;
    }

    public function execute(Adress $adress): void
    {
        $this->adressService->deleteAdress($adress);
    }
}
