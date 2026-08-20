<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\CategoriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: CategoriesRepository::class)]
#[ApiResource]
class Categories implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'category')]
    private Collection $products;
#[ORM\Column(nullable: true)]
    private ?int $externalShippingClassId = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $isRentalCategory = false;

    #[ORM\Column(nullable: true)]
    private ?int $categoryType = null;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $syncWeb = true;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $isVisible = true;

    /**
     * @var Collection<int, RentalPack>
     */
    #[ORM\ManyToMany(targetEntity: RentalPack::class, mappedBy: 'categories')]
    private Collection $rentalPacks;

    #[ORM\OneToMany(
        mappedBy: 'category',
        targetEntity: CategoriesTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->rentalPacks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): self
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->addCategory($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): self
    {
        if ($this->products->removeElement($product)) {
            $product->removeCategory($this);
        }

        return $this;
    }

    public function __toString()
    {
        return $this->name;
    }

    public function getExternalShippingClassId(): ?int
    {
        return $this->externalShippingClassId;
    }

    public function setExternalShippingClassId(?int $externalShippingClassId): static
    {
        $this->externalShippingClassId = $externalShippingClassId;

        return $this;
    }

    /**
     * @return Collection<int, CategoriesTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setCategory($this);
        }
    }

    public function removeTranslation(CategoriesTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getCategory() === $this) {
                $translation->setCategory(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?CategoriesTranslation
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
        return ['name', 'description'];
    }

    public function getTranslationEntityClass(): string
    {
        return CategoriesTranslation::class;
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

    public function isRentalCategory(): ?bool
    {
        return $this->isRentalCategory;
    }

    public function setIsRentalCategory(bool $isRentalCategory): static
    {
        $this->isRentalCategory = $isRentalCategory;

        return $this;
    }

    public function getCategoryType(): ?int
    {
        return $this->categoryType;
    }

    public function setCategoryType(?int $categoryType): static
    {
        $this->categoryType = $categoryType;

        return $this;
    }

    public function isSyncWeb(): ?bool
    {
        return $this->syncWeb;
    }

    public function setSyncWeb(bool $syncWeb): static
    {
        $this->syncWeb = $syncWeb;

        return $this;
    }

    public function isVisible(): ?bool
    {
        return $this->isVisible;
    }

    public function setIsVisible(bool $isVisible): static
    {
        $this->isVisible = $isVisible;

        return $this;
    }

    /**
     * @return Collection<int, RentalPack>
     */
    public function getRentalPacks(): Collection
    {
        return $this->rentalPacks;
    }

    public function addRentalPack(RentalPack $rentalPack): static
    {
        if (!$this->rentalPacks->contains($rentalPack)) {
            $this->rentalPacks->add($rentalPack);
            $rentalPack->addCategory($this);
        }

        return $this;
    }

    public function removeRentalPack(RentalPack $rentalPack): static
    {
        if ($this->rentalPacks->removeElement($rentalPack)) {
            $rentalPack->removeCategory($this);
        }

        return $this;
    }
}
