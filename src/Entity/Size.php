<?php

namespace App\Entity;

use App\Repository\SizeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;


#[ORM\Entity(repositoryClass: SizeRepository::class)]
class Size implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\OneToMany(mappedBy: 'size', targetEntity: ProductVariant::class)]
    private Collection $variantSize;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $code = null;

    /**
     * @var Collection<int, SizeTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'size', 
    targetEntity: SizeTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->variantSize = new ArrayCollection();
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
     * @return Collection<int, ProductVariant>
     */
    public function getVariantSize(): Collection
    {
        return $this->variantSize;
    }

    public function addVariantSize(ProductVariant $variantSize): static
    {
        if (!$this->variantSize->contains($variantSize)) {
            $this->variantSize->add($variantSize);
            $variantSize->setSize($this);
        }

        return $this;
    }

    public function removeVariantSize(ProductVariant $variantSize): static
    {
        if ($this->variantSize->removeElement($variantSize)) {
            // set the owning side to null (unless already changed)
            if ($variantSize->getSize() === $this) {
                $variantSize->setSize(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->name ?: 'N/A';
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
     * @return Collection<int, SizeTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setSize($this);
        }
    }

    public function removeTranslation(SizeTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getSize() === $this) {
                $translation->setSize(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?SizeTranslation
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
        return SizeTranslation::class;
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
