<?php

namespace App\Entity;

use App\Repository\HomeSliderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HomeSliderRepository::class)]
class HomeSlider
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $buttonMessage = null;

    #[ORM\Column(length: 255)]
    private ?string $buttonUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isDiplayed = null;

    /**
     * @var Collection<int, HomeSliderTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'homeSlider', 
    targetEntity: HomeSliderTranslation::class, 
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

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getButtonMessage(): ?string
    {
        return $this->buttonMessage;
    }

    public function setButtonMessage(string $buttonMessage): self
    {
        $this->buttonMessage = $buttonMessage;

        return $this;
    }

    public function getButtonUrl(): ?string
    {
        return $this->buttonUrl;
    }

    public function setButtonUrl(string $buttonUrl): self
    {
        $this->buttonUrl = $buttonUrl;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function isIsDiplayed(): ?bool
    {
        return $this->isDiplayed;
    }

    public function setIsDiplayed(?bool $isDiplayed): self
    {
        $this->isDiplayed = $isDiplayed;

        return $this;
    }

    /**
     * @return Collection<int, HomeSliderTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(HomeSliderTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setHomeSlider($this);
        }

        return $this;
    }

    public function removeTranslation(HomeSliderTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getHomeSlider() === $this) {
                $translation->setHomeSlider(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?HomeSliderTranslation
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
