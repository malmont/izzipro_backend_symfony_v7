<?php

namespace App\Entity;

use App\Repository\ServiceOfferRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: ServiceOfferRepository::class)]
class ServiceOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titreCommentaire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)] 
    private ?string $descriptions = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

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
}
