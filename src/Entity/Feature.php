<?php

namespace App\Entity;

use App\Repository\FeatureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: FeatureRepository::class)]
class Feature implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $iconpath = null;

    /**
     * @var Collection<int, FeatureTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'feature', 
    targetEntity: FeatureTranslation::class, 
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getIconpath(): ?string
    {
        return $this->iconpath;
    }

    public function setIconpath(?string $iconpath): static
    {
        $this->iconpath = $iconpath;

        return $this;
    }

    /**
     * @return Collection<int, FeatureTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setFeature($this);
        }
    }

    public function removeTranslation(FeatureTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getFeature() === $this) {
                $translation->setFeature(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?FeatureTranslation
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
     public function getTranslatableFields(): array
    {
        return ['title'];
    }

    public function getTranslationEntityClass(): string
    {
        return FeatureTranslation::class;
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
