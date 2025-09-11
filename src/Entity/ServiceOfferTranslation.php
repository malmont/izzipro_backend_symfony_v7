<?php

namespace App\Entity;

use App\Repository\ServiceOfferTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceOfferTranslationRepository::class)]
class ServiceOfferTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titreCommentaire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descriptions = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?ServiceOffer $serviceOffer = null;

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

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getTitreCommentaire(): ?string
    {
        return $this->titreCommentaire;
    }

    public function setTitreCommentaire(?string $titreCommentaire): static
    {
        $this->titreCommentaire = $titreCommentaire;

        return $this;
    }

    public function getDescriptions(): ?string
    {
        return $this->descriptions;
    }

    public function setDescriptions(?string $descriptions): static
    {
        $this->descriptions = $descriptions;

        return $this;
    }

    public function getServiceOffer(): ?ServiceOffer
    {
        return $this->serviceOffer;
    }

    public function setServiceOffer(?ServiceOffer $serviceOffer): static
    {
        $this->serviceOffer = $serviceOffer;

        return $this;
    }
}
