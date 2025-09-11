<?php

namespace App\Entity;

use App\Repository\RechercheRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RechercheRepository::class)]
class Recherche
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texte1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texte2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageDeFond = null;

    /**
     * @var Collection<int, RechercheTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'recherche', 
    targetEntity: RechercheTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
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

    public function getImageDeFond(): ?string
    {
        return $this->imageDeFond;
    }

    public function setImageDeFond(?string $imageDeFond): static
    {
        $this->imageDeFond = $imageDeFond;

        return $this;
    }

    /**
     * @return Collection<int, RechercheTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(RechercheTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setRecherche($this);
        }

        return $this;
    }

    public function removeTranslation(RechercheTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getRecherche() === $this) {
                $translation->setRecherche(null);
            }
        }

        return $this;
    }
    public function getTranslation(string $locale): ?RechercheTranslation
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
