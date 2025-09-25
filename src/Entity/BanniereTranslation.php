<?php

namespace App\Entity;

use App\Repository\BanniereTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BanniereTranslationRepository::class)]
class BanniereTranslation
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
    private ?string $texte = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?Banniere $banniere = null;

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

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): static
    {
        $this->texte = $texte;

        return $this;
    }

    public function getBanniere(): ?Banniere
    {
        return $this->banniere;
    }

    public function setBanniere(?Banniere $banniere): static
    {
        $this->banniere = $banniere;

        return $this;
    }
}
