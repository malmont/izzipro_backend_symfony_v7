<?php

namespace App\Entity;

use App\Repository\MultilienRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MultilienRepository::class)]
class Multilien
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageDeFond = null;

    #[ORM\Column(length: 255)]
    private ?string $lien = null;

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

    public function getImageDeFond(): ?string
    {
        return $this->imageDeFond;
    }

    public function setImageDeFond(?string $imageDeFond): static
    {
        $this->imageDeFond = $imageDeFond;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(string $lien): static
    {
        $this->lien = $lien;

        return $this;
    }
}
