<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\VehicleRepository;
use App\Entity\TranslatableInterface;
use Doctrine\DBAL\Types\Types;
use ApiPlatform\Metadata\ApiResource;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ApiResource]
class Vehicle implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $gemsuiteVehicleId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $picture = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(nullable: true)]
    private ?int $transmission = null;

    #[ORM\Column(nullable: true)]
    private ?int $gasType = null;

    #[ORM\Column(nullable: true)]
    private ?bool $newVehicle = null;

    #[ORM\Column(nullable: true)]
    private ?bool $featuredVehicle = null;

    #[ORM\Column(nullable: true)]
    private ?bool $webDisplay = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    /**
     * @var Collection<int, VehicleTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'vehicle',
        targetEntity: VehicleTranslation::class,
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

    public function getGemsuiteVehicleId(): ?int
    {
        return $this->gemsuiteVehicleId;
    }

    public function setGemsuiteVehicleId(int $gemsuiteVehicleId): static
    {
        $this->gemsuiteVehicleId = $gemsuiteVehicleId;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

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

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): static
    {
        $this->picture = $picture;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getTransmission(): ?int
    {
        return $this->transmission;
    }

    public function setTransmission(?int $transmission): static
    {
        $this->transmission = $transmission;

        return $this;
    }

    public function getGasType(): ?int
    {
        return $this->gasType;
    }

    public function setGasType(?int $gasType): static
    {
        $this->gasType = $gasType;

        return $this;
    }

    public function getNewVehicle(): ?bool
    {
        return $this->newVehicle;
    }

    public function setNewVehicle(?bool $newVehicle): static
    {
        $this->newVehicle = $newVehicle;

        return $this;
    }

    public function getFeaturedVehicle(): ?bool
    {
        return $this->featuredVehicle;
    }

    public function setFeaturedVehicle(?bool $featuredVehicle): static
    {
        $this->featuredVehicle = $featuredVehicle;

        return $this;
    }

    public function getWebDisplay(): ?bool
    {
        return $this->webDisplay;
    }

    public function setWebDisplay(?bool $webDisplay): static
    {
        $this->webDisplay = $webDisplay;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    // ─── Translation methods ───────────────────────────────────────────────────

    /**
     * @return Collection<int, VehicleTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setVehicle($this);
        }
    }

    public function removeTranslation(VehicleTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getVehicle() === $this) {
                $translation->setVehicle(null);
            }
        }

        return $this;
    }

    /**
     * Returns the translation for $locale, falling back to 'fr', then the first available.
     */
    public function getTranslation(string $locale): ?VehicleTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }

        // Fallback to French
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === 'fr') {
                return $translation;
            }
        }

        return $this->translations->first() ?: null;
    }

    public function getTranslatableFields(): array
    {
        return ['title', 'description'];
    }

    public function getTranslationEntityClass(): string
    {
        return VehicleTranslation::class;
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
