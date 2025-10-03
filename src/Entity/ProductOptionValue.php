<?php

namespace App\Entity;

use App\Repository\ProductOptionValueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: ProductOptionValueRepository::class)]
class ProductOptionValue implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $value = null;

    #[ORM\ManyToOne(inversedBy: 'productOptionValues')]
    private ?ProductOption $productOption = null;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\ManyToMany(targetEntity: ProductVariant::class, mappedBy: 'optionValues')]
    private Collection $productVariants;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $code = null;

    /**
     * @var Collection<int, ProductOptionValueTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'productOptionValue', 
    targetEntity: ProductOptionValueTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->productVariants = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getProductOption(): ?ProductOption
    {
        return $this->productOption;
    }

    public function setProductOption(?ProductOption $productOption): static
    {
        $this->productOption = $productOption;

        return $this;
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function getProductVariants(): Collection
    {
        return $this->productVariants;
    }

    public function addProductVariant(ProductVariant $productVariant): static
    {
        if (!$this->productVariants->contains($productVariant)) {
            $this->productVariants->add($productVariant);
            $productVariant->addOptionValue($this);
        }

        return $this;
    }

    public function removeProductVariant(ProductVariant $productVariant): static
    {
        if ($this->productVariants->removeElement($productVariant)) {
            $productVariant->removeOptionValue($this);
        }

        return $this;
    }

    public function __toString(): string
{
    // On vérifie que l'option parente existe pour éviter les erreurs
    if ($this->getProductOption()) {
        return $this->getProductOption()->getName() . ': ' . $this->getValue();
    }

    // Sinon, on retourne juste la valeur comme solution de secours
    return (string) $this->getValue();
}

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    /**
     * @return Collection<int, ProductOptionValueTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

   public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setProductOptionValue($this);
        }
    }

    public function removeTranslation(ProductOptionValueTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getProductOptionValue() === $this) {
                $translation->setProductOptionValue(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?ProductOptionValueTranslation
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
        return ['value'];
    }

    public function getTranslationEntityClass(): string
    {
        return ProductOptionValueTranslation::class;
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
