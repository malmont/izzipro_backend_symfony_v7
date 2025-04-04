<?php

namespace App\Services\EntrepriseService;

use App\Entity\Entreprise;
use App\Dto\EntrepriseDto;
use Doctrine\ORM\EntityManagerInterface;

class EntrepriseService
{
    private EntityManagerInterface $em;
    public function __construct( EntityManagerInterface $em) {
        $this->em = $em;
    }

    public function createEntreprise(EntrepriseDto $dto): EntrepriseDto
    {
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

        $this->em->persist($entreprise);
        $this->em->flush();

        // Retourner le DTO enrichi (avec l'ID généré par exemple)
        $dto->id = $entreprise->getId();
        return $dto;
    }

    public function getEntrepriseById(int $id,string $host): ?EntrepriseDto
    {
        $entreprise = $this->em->getRepository(Entreprise::class)->find($id);
        if (!$entreprise) {
            return null;
        }
        $dto = new EntrepriseDto();
        $dto->id = $entreprise->getId();
        $dto->name = $entreprise->getName();
        $dto->logo = $entreprise->getLogo() ? $host . '/assets/uploads/email-logos/' . $entreprise->getLogo() : null;
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
