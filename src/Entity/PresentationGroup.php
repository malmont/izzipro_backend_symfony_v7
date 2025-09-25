<?php

namespace App\Entity;

use App\Repository\PresentationGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PresentationGroupRepository::class)]
class PresentationGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    /**
     * @var Collection<int, Presentation>
     */
    #[ORM\ManyToMany(targetEntity: Presentation::class, inversedBy: 'presentationGroups')]
    private Collection $presentations;

    /**
     * @var Collection<int, PresentationGroupTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'presentationGroup', 
    targetEntity: PresentationGroupTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->presentations = new ArrayCollection();
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

    /**
     * @return Collection<int, Presentation>
     */
    public function getPresentations(): Collection
    {
        return $this->presentations;
    }

    public function addPresentation(Presentation $presentation): static
    {
        if (!$this->presentations->contains($presentation)) {
            $this->presentations->add($presentation);
        }

        return $this;
    }

    public function removePresentation(Presentation $presentation): static
    {
        $this->presentations->removeElement($presentation);

        return $this;
    }

    /**
     * @return Collection<int, PresentationGroupTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(PresentationGroupTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setPresentationGroup($this);
        }

        return $this;
    }

    public function removeTranslation(PresentationGroupTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getPresentationGroup() === $this) {
                $translation->setPresentationGroup(null);
            }
        }

        return $this;
    }

     public function getTranslation(string $locale): ?PresentationGroupTranslation
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
