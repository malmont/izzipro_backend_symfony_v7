<?php

namespace App\Entity;

use App\Repository\PresentationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types; 

#[ORM\Entity(repositoryClass: PresentationRepository::class)]
class Presentation
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
    private ?string $image = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texteBouton = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lienBouton = null;

    /**
     * @var Collection<int, PresentationGroup>
     */
    #[ORM\ManyToMany(targetEntity: PresentationGroup::class, mappedBy: 'presentations')]
    private Collection $presentationGroups;

    /**
     * @var Collection<int, PresentationTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'presentation', 
    targetEntity: PresentationTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->presentationGroups = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

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

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

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

    /**
     * @return Collection<int, PresentationGroup>
     */
    public function getPresentationGroups(): Collection
    {
        return $this->presentationGroups;
    }

    public function addPresentationGroup(PresentationGroup $presentationGroup): static
    {
        if (!$this->presentationGroups->contains($presentationGroup)) {
            $this->presentationGroups->add($presentationGroup);
            $presentationGroup->addPresentation($this);
        }

        return $this;
    }

    public function removePresentationGroup(PresentationGroup $presentationGroup): static
    {
        if ($this->presentationGroups->removeElement($presentationGroup)) {
            $presentationGroup->removePresentation($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->titre ?? '';
    }

    /**
     * @return Collection<int, PresentationTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(PresentationTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setPresentation($this);
        }

        return $this;
    }

    public function removeTranslation(PresentationTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getPresentation() === $this) {
                $translation->setPresentation(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?PresentationTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }

        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === 'fr') {
                return $translation;
            }
        }
        
        return $this->translations->first() ?: null;
    }
}
