<?php

namespace App\Entity;

use App\Repository\RentalPackTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RentalPackTranslationRepository::class)]
class RentalPackTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?RentalPack $rentalPack = null;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getRentalPack(): ?RentalPack
    {
        return $this->rentalPack;
    }

    public function setRentalPack(?RentalPack $rentalPack): static
    {
        $this->rentalPack = $rentalPack;

        return $this;
    }
}
