<?php

namespace App\Entity;

use App\Repository\EntrepriseTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntrepriseTranslationRepository::class)]
class EntrepriseTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditionOfUse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $LegalNotice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyPolicy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $Apropos = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?Entreprise $entreprise = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getConditionOfUse(): ?string
    {
        return $this->conditionOfUse;
    }

    public function setConditionOfUse(?string $conditionOfUse): static
    {
        $this->conditionOfUse = $conditionOfUse;

        return $this;
    }

    public function getLegalNotice(): ?string
    {
        return $this->LegalNotice;
    }

    public function setLegalNotice(?string $LegalNotice): static
    {
        $this->LegalNotice = $LegalNotice;

        return $this;
    }

    public function getPrivacyPolicy(): ?string
    {
        return $this->privacyPolicy;
    }

    public function setPrivacyPolicy(?string $privacyPolicy): static
    {
        $this->privacyPolicy = $privacyPolicy;

        return $this;
    }

    public function getApropos(): ?string
    {
        return $this->Apropos;
    }

    public function setApropos(?string $Apropos): static
    {
        $this->Apropos = $Apropos;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }
}
