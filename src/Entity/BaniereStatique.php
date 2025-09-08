<?php

namespace App\Entity;

use App\Repository\BaniereStatiqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types; 

#[ORM\Entity(repositoryClass: BaniereStatiqueRepository::class)]
class BaniereStatique
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
    private ?string $imageDeFond = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $colorBackground = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $texteBouton = null;

    /**
     * @var Collection<int, BaniereStatiqueTranslation>
     */
    #[ORM\OneToMany(mappedBy: 'baniereStatique', targetEntity: BaniereStatiqueTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): static
    {
        $this->texte = $texte;

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

    public function getColorBackground(): ?string
    {
        return $this->colorBackground;
    }

    public function setColorBackground(?string $colorBackground): static
    {
        $this->colorBackground = $colorBackground;

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

    /**
     * @return Collection<int, BaniereStatiqueTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(BaniereStatiqueTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setBaniereStatique($this);
        }

        return $this;
    }

    public function removeTranslation(BaniereStatiqueTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getBaniereStatique() === $this) {
                $translation->setBaniereStatique(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?BaniereStatiqueTranslation
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
