<?php

namespace App\Entity;

use App\Repository\PresentationGroupTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PresentationGroupTranslationRepository::class)]
class PresentationGroupTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?PresentationGroup $presentationGroup = null;

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

    public function getPresentationGroup(): ?PresentationGroup
    {
        return $this->presentationGroup;
    }

    public function setPresentationGroup(?PresentationGroup $presentationGroup): static
    {
        $this->presentationGroup = $presentationGroup;

        return $this;
    }
}
