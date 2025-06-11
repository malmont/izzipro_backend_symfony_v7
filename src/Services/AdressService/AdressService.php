<?php
namespace App\Services\AdressService;

use App\Dto\AdressInputDTO;
use App\Dto\AdressOutputDTO;
use App\Entity\Adress;
use App\Services\TenantEntityManagerProvider;

class AdressService
{
    private TenantEntityManagerProvider $tenantEmProvider;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider)
    {
        
        $this->tenantEmProvider = $tenantEmProvider;
    }

    private function getAdressRepository()
    {
        $entityManager = $this->tenantEmProvider->getEntityManager();
        $entityManager->clear(Adress::class); 
        return  $entityManager->getRepository(Adress::class);
    }

    public function getUserAdresses($user): array
    {
        $adresses = $this->getAdressRepository()->findBy(['userAdress' => $user]);
        return array_map(fn($adress) => new AdressOutputDTO($adress), $adresses);
    }

    public function createAdress(AdressInputDTO $inputDTO, $user): Adress
    {
        $adress = new Adress();
        $adress->setFirstname($inputDTO->firstname);
        $adress->setLastname($inputDTO->lastname);
        $adress->setFullname($inputDTO->lastname . ' ' . $inputDTO->firstname);
        $adress->setCompany($inputDTO->company);
        $adress->setAddress($inputDTO->addressLineOne);
        $adress->setComplement($inputDTO->addressLineTwo);
        $adress->setPhone($inputDTO->contactNumber);
        $adress->setCity($inputDTO->city);
        $adress->setCodepostal($inputDTO->zipCode);
        $adress->setProvince($inputDTO->province);
        $adress->setCountry($inputDTO->country);
        $adress->setUserAdress($user);

        $repo = $this->getAdressRepository();
        $repo->save($adress, true);

        return $adress;
    }

    public function editAdress(AdressInputDTO $inputDTO, Adress $adress): Adress
    {
        $adress->setFirstname($inputDTO->firstname);
        $adress->setLastname($inputDTO->lastname);
        $adress->setFullname($inputDTO->lastname . ' ' . $inputDTO->firstname);
        $adress->setCompany($inputDTO->company);
        $adress->setAddress($inputDTO->addressLineOne);
        $adress->setComplement($inputDTO->addressLineTwo);
        $adress->setPhone($inputDTO->contactNumber);
        $adress->setCity($inputDTO->city);
        $adress->setProvince($inputDTO->province);
        $adress->setCodepostal($inputDTO->zipCode);
        $adress->setCountry($inputDTO->country);

        $repo = $this->getAdressRepository();
        $repo->save($adress, true);

        return $adress;
    }

    public function deleteAdress(Adress $adress): void
    {
        $repo = $this->getAdressRepository();
        $repo->remove($adress, true);
    }
}
