<?php
namespace App\Services\AdressService;

use App\Dto\AdressInputDTO;
use App\Dto\AdressOutputDTO;
use App\Entity\Adress;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteClientUpdater; 
class AdressService
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private GemsuiteClientUpdater $gemsuiteUpdater;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider, GemsuiteClientUpdater $gemsuiteUpdater)
    {
        
        $this->tenantEmProvider = $tenantEmProvider;
        $this->gemsuiteUpdater = $gemsuiteUpdater;
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
        $entityManager = $this->tenantEmProvider->getEntityManager();
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
        if ($inputDTO->isPrimary) {
            $user->setPrimaryAddress($adress);
        }

        $entityManager->persist($adress);
        $this->gemsuiteUpdater->syncAddress($user, $adress);
        $entityManager->flush();
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
        $user = $adress->getUserAdress();
        if ($inputDTO->isPrimary && $user) {
            $user->setPrimaryAddress($adress);
            $this->gemsuiteUpdater->syncAddress($user, $adress);
        }
        $entityManager = $this->tenantEmProvider->getEntityManager(); // Ajouté car il manquait aussi
        $entityManager->flush();
        $entityManager->flush();

        return $adress;
    }

    public function deleteAdress(Adress $adress): void
    {
        $repo = $this->getAdressRepository();
        $repo->remove($adress, true);
    }
}
