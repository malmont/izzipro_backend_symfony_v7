<?php

namespace App\Entity;

use App\Repository\BanniereRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: BanniereRepository::class)]
class Banniere implements TranslatableInterface 
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

    /**
     * @var Collection<int, BanniereTranslation>
     */
    #[ORM\OneToMany(mappedBy: 'banniere', targetEntity: BanniereTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getTranslation(string $locale): ?BanniereTranslation
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

    /**
     * @return Collection<int, BanniereTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setBanniere($this);
        }
    }

    public function removeTranslation(BanniereTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getBanniere() === $this) {
                $translation->setBanniere(null);
            }
        }

        return $this;
    }
    public function getTranslatableFields(): array
    {
        // On déclare les champs à traduire pour l'entité Banniere
        return ['titre', 'texte'];
    }

    public function getTranslationEntityClass(): string
    {
        return BanniereTranslation::class;
    }

    public function findTranslationByLocale(string $locale): ?object
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }
        return null;
    }
}
