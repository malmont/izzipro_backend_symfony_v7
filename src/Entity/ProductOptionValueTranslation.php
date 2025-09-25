<?php

namespace App\Entity;

use App\Repository\ProductOptionValueTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductOptionValueTranslationRepository::class)]
class ProductOptionValueTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $value = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?ProductOptionValue $productOptionValue = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
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

    public function getProductOptionValue(): ?ProductOptionValue
    {
        return $this->productOptionValue;
    }

    public function setProductOptionValue(?ProductOptionValue $productOptionValue): static
    {
        $this->productOptionValue = $productOptionValue;

        return $this;
    }
}
