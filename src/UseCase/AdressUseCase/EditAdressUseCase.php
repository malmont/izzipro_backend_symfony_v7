<?php
namespace App\UseCase\AdressUseCase;

use App\Dto\AdressInputDTO;
use App\Entity\Adress;
use App\Services\AdressService\AdressService;

class EditAdressUseCase
{
    private AdressService $adressService;

    public function __construct(AdressService $adressService)
    {
        $this->adressService = $adressService;
    }

    public function execute(AdressInputDTO $inputDTO, Adress $adress)
    {
        return $this->adressService->editAdress($inputDTO, $adress);
    }
}
