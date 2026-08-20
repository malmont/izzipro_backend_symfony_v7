<?php

namespace App\Entity;

use App\Repository\RentalPackRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\RentalPackRepository::class)]
class RentalPack implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;
#[ORM\Column(nullable: true)]
    private ?float $hourRate = null;

    #[ORM\Column(nullable: true)]
    private ?float $halfDayRate = null;

    #[ORM\Column(nullable: true)]
    private ?float $dayRate = null;

    #[ORM\Column(nullable: true)]
    private ?float $weekRate = null;

    #[ORM\Column(nullable: true)]
    private ?float $monthRate = null;

    /**
     * @var Collection<int, Categories>
     */
    #[ORM\ManyToMany(targetEntity: Categories::class, inversedBy: 'rentalPacks')]
    private Collection $categories;

    /**
     * @var Collection<int, RentalPackTranslation>
     */
    #[ORM\OneToMany(mappedBy: 'rentalPack', targetEntity: RentalPackTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getHourRate(): ?float
    {
        return $this->hourRate;
    }

    public function setHourRate(?float $hourRate): static
    {
        $this->hourRate = $hourRate;

        return $this;
    }

    public function getHalfDayRate(): ?float
    {
        return $this->halfDayRate;
    }

    public function setHalfDayRate(?float $halfDayRate): static
    {
        $this->halfDayRate = $halfDayRate;

        return $this;
    }

    public function getDayRate(): ?float
    {
        return $this->dayRate;
    }

    public function setDayRate(?float $dayRate): static
    {
        $this->dayRate = $dayRate;

        return $this;
    }

    public function getWeekRate(): ?float
    {
        return $this->weekRate;
    }

    public function setWeekRate(?float $weekRate): static
    {
        $this->weekRate = $weekRate;

        return $this;
    }

    public function getMonthRate(): ?float
    {
        return $this->monthRate;
    }

    public function setMonthRate(?float $monthRate): static
    {
        $this->monthRate = $monthRate;

        return $this;
    }

    /**
     * @return Collection<int, Categories>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Categories $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->addRentalPack($this);
        }

        return $this;
    }

    public function removeCategory(Categories $category): static
    {
        if ($this->categories->removeElement($category)) {
            $category->removeRentalPack($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, RentalPackTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setRentalPack($this);
        }
    }

    public function removeTranslation(RentalPackTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getRentalPack() === $this) {
                $translation->setRentalPack(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?RentalPackTranslation
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
        return ['name'];
    }

    public function getTranslationEntityClass(): string
    {
        return RentalPackTranslation::class;
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
