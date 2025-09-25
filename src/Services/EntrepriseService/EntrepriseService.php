<?php
namespace App\Services\EntrepriseService;

use App\Entity\Entreprise;
use App\Dto\EntrepriseDto;
use App\Repository\EntrepriseRepository;
use App\Services\TenantEntityManagerProvider; 

class EntrepriseService
{
    private EntrepriseRepository $repository;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(Entreprise::class);
    }
    
    public function getEntrepriseByIdAndLocale(int $id, string $host, string $locale): ?EntrepriseDto
    {
        $entreprise = $this->repository->findByIdAndLocale($id, $locale);
        
        if (!$entreprise) {
            return null;
        }
        return EntrepriseDto::fromEntity($entreprise, $host, $locale);
    }
    
   public function createEntreprise(EntrepriseDto $dto): EntrepriseDto
    {
        $em = $this->emProvider->getEntityManager();

        $entreprise = new Entreprise();
        $entreprise->setName($dto->name);
        $entreprise->setLogo($dto->logo);
        $entreprise->setEmail($dto->email);
        $entreprise->setTel($dto->tel);
        $entreprise->setWebsite($dto->website);
        $entreprise->setEin($dto->ein);
        $entreprise->setTvaIntracommunautaire($dto->tvaIntracommunautaire);
        $entreprise->setAdress($dto->adress);
        $translation = new EntrepriseTranslation();
        $translation->setLanguage('fr');
        $translation->setConditionOfUse($dto->conditionOfUse);
        $translation->setLegalNotice($dto->LegalNotice);
        $translation->setPrivacyPolicy($dto->privacyPolicy);
        $translation->setApropos($dto->apropos);
        $entreprise->addTranslation($translation);

        $em->persist($entreprise);
        $em->flush();

        $dto->id = $entreprise->getId();
        return $dto;
    }
}