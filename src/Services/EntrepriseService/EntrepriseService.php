<?php
namespace App\Services\EntrepriseService;

use App\Entity\Entreprise;
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
        $entreprise->setConditionOfUse($dto->conditionOfUse);
        $entreprise->setLegalNotice($dto->LegalNotice);
        $entreprise->setPrivacyPolicy($dto->privacyPolicy);
        $entreprise->setAdress($dto->adress);

        // On utilise l'EM du tenant
        $em->persist($entreprise);
        $em->flush();

        $dto->id = $entreprise->getId();
        return $dto;
    }

    public function getEntrepriseById(int $id, string $host): ?EntrepriseDto
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->find($id);
        
        if (!$entreprise) {
            return null;
        }

        $dto = new EntrepriseDto();
        $dto->id = $entreprise->getId();
        $dto->name = $entreprise->getName();

        // === Résolution du logo, même modèle que ProductOutputDTO ===
        $imagePath = $entreprise->getLogo();
        if ($imagePath) {
            if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                $dto->logo = $imagePath;
            } else {
                $dto->logo = $host . '/assets/uploads/email-logos/' . $imagePath;
            }
        } else {
            $dto->logo = null;
        }
        $dto->email = $entreprise->getEmail();
        $dto->apropos = $entreprise->getApropos();
        $dto->tel = $entreprise->getTel();
        $dto->website = $entreprise->getWebsite();
        $dto->ein = $entreprise->getEin();
        $dto->tvaIntracommunautaire = $entreprise->getTvaIntracommunautaire();
        $dto->conditionOfUse = $entreprise->getConditionOfUse();
        $dto->LegalNotice = $entreprise->getLegalNotice();
        $dto->privacyPolicy = $entreprise->getPrivacyPolicy();
        $dto->adress = $entreprise->getAdress();

        return $dto;
    }
}
