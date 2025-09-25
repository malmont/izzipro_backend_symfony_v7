<?php

namespace App\Entity;

use App\Repository\CategorieMarqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategorieMarqueRepository::class)]
class CategorieMarque
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * @var Collection<int, Marque>
     */
    #[ORM\ManyToMany(targetEntity: Marque::class, mappedBy: 'categories')]
    private Collection $marques;

    /**
     * @var Collection<int, CategorieMarqueTranslation>
     */
    #[ORM\OneToMany(mappedBy: 'categorieMarque', targetEntity: CategorieMarqueTranslation::class,cascade: ['persist', 'remove'],orphanRemoval: true)]
    private Collection $translations;

    public function __construct()
    {
        $this->marques = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /**
     * @return Collection<int, Marque>
     */
    public function getMarques(): Collection
    {
        return $this->marques;
    }

    public function addMarque(Marque $marque): static
    {
        if (!$this->marques->contains($marque)) {
            $this->marques->add($marque);
            $marque->addCategory($this);
        }

        return $this;
    }

    public function removeMarque(Marque $marque): static
    {
        if ($this->marques->removeElement($marque)) {
            $marque->removeCategory($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, CategorieMarqueTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(CategorieMarqueTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setCategorieMarque($this);
        }

        return $this;
    }

    public function removeTranslation(CategorieMarqueTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getCategorieMarque() === $this) {
                $translation->setCategorieMarque(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?CategorieMarqueTranslation
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
