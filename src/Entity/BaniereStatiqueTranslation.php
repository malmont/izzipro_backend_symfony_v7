<?php

namespace App\Entity;

use App\Repository\BaniereStatiqueTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BaniereStatiqueTranslationRepository::class)]
class BaniereStatiqueTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $texte = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texteBouton = null;

    #[ORM\Column(length: 15)]
    private ?string $language = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?BaniereStatique $baniereStatique = null;

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

    public function getBaniereStatique(): ?BaniereStatique
    {
        return $this->baniereStatique;
    }

    public function setBaniereStatique(?BaniereStatique $baniereStatique): static
    {
        $this->baniereStatique = $baniereStatique;

        return $this;
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
}
