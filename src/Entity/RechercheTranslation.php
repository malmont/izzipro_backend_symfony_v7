<?php

namespace App\Entity;

use App\Repository\RechercheTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RechercheTranslationRepository::class)]
class RechercheTranslation
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
    private ?string $texte1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texte2 = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?Recherche $recherche = null;

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

    public function getTexte1(): ?string
    {
        return $this->texte1;
    }

    public function setTexte1(?string $texte1): static
    {
        $this->texte1 = $texte1;

        return $this;
    }

    public function getTexte2(): ?string
    {
        return $this->texte2;
    }

    public function setTexte2(?string $texte2): static
    {
        $this->texte2 = $texte2;

        return $this;
    }

    public function getRecherche(): ?Recherche
    {
        return $this->recherche;
    }

    public function setRecherche(?Recherche $recherche): static
    {
        $this->recherche = $recherche;

        return $this;
    }
}
