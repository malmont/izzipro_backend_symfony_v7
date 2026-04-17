<?php

namespace App\Entity;

use App\Repository\RentalPackRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\RentalPackRepository::class)]
class RentalPack
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(nullable: true)]
    private ?int $gemsuiteProductId = null;

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

    public function __construct()
    {
        $this->categories = new ArrayCollection();
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

    public function getGemsuiteProductId(): ?int
    {
        return $this->gemsuiteProductId;
    }

    public function setGemsuiteProductId(?int $gemsuiteProductId): static
    {
        $this->gemsuiteProductId = $gemsuiteProductId;

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
}
