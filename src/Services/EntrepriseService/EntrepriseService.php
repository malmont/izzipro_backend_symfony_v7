<?php
namespace App\Services\EntrepriseService;

use App\Entity\Entreprise;
use App\Entity\EntrepriseTranslation; // On importe l'entité de traduction
use App\Dto\EntrepriseDto;
use App\Services\TenantEntityManagerProvider; 

class EntrepriseService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
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

    public function getEntrepriseById(int $id, string $host, string $locale): ?EntrepriseDto
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->find($id);
        
        if (!$entreprise) {
            return null;
        }
        $translation = $entreprise->getTranslation($locale);

        $dto = new EntrepriseDto();
        $dto->id = $entreprise->getId();
        $dto->name = $entreprise->getName();
        $dto->email = $entreprise->getEmail();
        $dto->tel = $entreprise->getTel();
        $dto->website = $entreprise->getWebsite();
        $dto->ein = $entreprise->getEin();
        $dto->tvaIntracommunautaire = $entreprise->getTvaIntracommunautaire();
        $dto->adress = $entreprise->getAdress();
        $imagePath = $entreprise->getLogo();
        if (empty($imagePath)) {
            $dto->logo = null;
        } elseif (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            $dto->logo = $imagePath; 
        } else {
            $cleanedHost = rtrim($host, '/');
            $dto->logo = $cleanedHost . '/assets/uploads/email-logos/' . $imagePath;
        }
        if ($translation) {
            $dto->conditionOfUse = $translation->getConditionOfUse();
            $dto->LegalNotice = $translation->getLegalNotice();
            $dto->privacyPolicy = $translation->getPrivacyPolicy();
            $dto->apropos = $translation->getApropos();
        }

        return $dto;
    }
}