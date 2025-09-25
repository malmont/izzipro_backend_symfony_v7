<?php

namespace App\Entity;

use App\Repository\PresentationTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PresentationTranslationRepository::class)]
class PresentationTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT , nullable: true)]
    private ?string $texte = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texteBouton = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lienBouton = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?Presentation $presentation = null;

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

    public function getTexteBouton(): ?string
    {
        return $this->texteBouton;
    }

    public function setTexteBouton(?string $texteBouton): static
    {
        $this->texteBouton = $texteBouton;

        return $this;
    }

    public function getLienBouton(): ?string
    {
        return $this->lienBouton;
    }

    public function setLienBouton(?string $lienBouton): static
    {
        $this->lienBouton = $lienBouton;

        return $this;
    }

    public function getPresentation(): ?Presentation
    {
        return $this->presentation;
    }

    public function setPresentation(?Presentation $presentation): static
    {
        $this->presentation = $presentation;

        return $this;
    }
}
