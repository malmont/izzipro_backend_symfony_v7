<?php

namespace App\Entity;

use App\Repository\CategorieMarqueTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategorieMarqueTranslationRepository::class)]
class CategorieMarqueTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?CategorieMarque $categorieMarque = null;

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

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getCategorieMarque(): ?CategorieMarque
    {
        return $this->categorieMarque;
    }

    public function setCategorieMarque(?CategorieMarque $categorieMarque): static
    {
        $this->categorieMarque = $categorieMarque;

        return $this;
    }
}
