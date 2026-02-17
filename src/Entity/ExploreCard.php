<?php

namespace App\Entity;

use App\Repository\ExploreCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: ExploreCardRepository::class)]
class ExploreCard implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?bool $isDifferent = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $standardTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $differentTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoPath = null;

    /**
     * @var Collection<int, ExploreCardTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'exploreCard',
        targetEntity: ExploreCardTranslation::class,
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

    public function getIsDifferent(): ?bool
    {
        return $this->isDifferent;
    }

    public function setIsDifferent(bool $isDifferent): static
    {
        $this->isDifferent = $isDifferent;

        return $this;
    }

    public function getStandardTitle(): ?string
    {
        return $this->standardTitle;
    }

    public function setStandardTitle(?string $standardTitle): static
    {
        $this->standardTitle = $standardTitle;

        return $this;
    }

    public function getDifferentTitle(): ?string
    {
        return $this->differentTitle;
    }

    public function setDifferentTitle(?string $differentTitle): static
    {
        $this->differentTitle = $differentTitle;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getVideoPath(): ?string
    {
        return $this->videoPath;
    }

    public function setVideoPath(?string $videoPath): static
    {
        $this->videoPath = $videoPath;

        return $this;
    }

    /**
     * @return Collection<int, ExploreCardTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setExploreCard($this);
        }
    }

    public function removeTranslation(ExploreCardTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getExploreCard() === $this) {
                $translation->setExploreCard(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?ExploreCardTranslation
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
        return ['standardTitle', 'differentTitle', 'description'];
    }

    public function getTranslationEntityClass(): string
    {
        return ExploreCardTranslation::class;
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
