<?php

namespace App\Entity;

use App\Repository\ProductOptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductOptionRepository::class)]
class ProductOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, ProductOptionValue>
     */
    #[ORM\OneToMany(mappedBy: 'productOption', targetEntity: ProductOptionValue::class)]
    private Collection $productOptionValues;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $code = null;

    /**
     * @var Collection<int, ProductOptionTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'productOption', 
    targetEntity: ProductOptionTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->productOptionValues = new ArrayCollection();
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

    /**
     * @return Collection<int, ProductOptionValue>
     */
    public function getProductOptionValues(): Collection
    {
        return $this->productOptionValues;
    }

    public function addProductOptionValue(ProductOptionValue $productOptionValue): static
    {
        if (!$this->productOptionValues->contains($productOptionValue)) {
            $this->productOptionValues->add($productOptionValue);
            $productOptionValue->setProductOption($this);
        }

        return $this;
    }

    public function removeProductOptionValue(ProductOptionValue $productOptionValue): static
    {
        if ($this->productOptionValues->removeElement($productOptionValue)) {
            // set the owning side to null (unless already changed)
            if ($productOptionValue->getProductOption() === $this) {
                $productOptionValue->setProductOption(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->name ?? 'N/A';
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
     * @return Collection<int, ProductOptionTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ProductOptionTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setProductOption($this);
        }

        return $this;
    }

    public function removeTranslation(ProductOptionTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getProductOption() === $this) {
                $translation->setProductOption(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?ProductOptionTranslation
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
