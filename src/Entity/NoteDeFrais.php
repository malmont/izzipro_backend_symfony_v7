<?php

namespace App\Entity;

use App\Repository\NoteDeFraisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteDeFraisRepository::class)]
#[ORM\HasLifecycleCallbacks]
class NoteDeFrais
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $Description = null;

    // #[ORM\Column(length: 255)]
    // private ?string $imageNdf = null;

    #[ORM\Column]
    private ?float $montant = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\ManyToOne(inversedBy: 'noteDeFrais')]
    private ?Collections $Collection = null;

    #[ORM\ManyToOne(inversedBy: 'noteDeFrais')]
    private ?TypeNoteDeFrais $typeNoteDeFrais = null;

    #[ORM\PrePersist] // Annotation pour générer le nom avant de persister
    public function generateName(): void
    {
        if (!$this->name) {
            $this->name = 'noteDeFrais#' . uniqid();
        }
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

    public function getDescription(): ?string
    {
        return $this->Description;
    }

    public function setDescription(?string $Description): static
    {
        $this->Description = $Description;

        return $this;
    }

    // public function getImageNdf(): ?string
    // {
    //     return $this->imageNdf;
    // }

    // public function setImageNdf(string $imageNdf): static
    // {
    //     $this->imageNdf = $imageNdf;

    //     return $this;
    // }

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(float $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getCollection(): ?Collections
    {
        return $this->Collection;
    }

    public function setCollection(?Collections $Collection): static
    {
        $this->Collection = $Collection;

        return $this;
    }

    public function getTypeNoteDeFrais(): ?TypeNoteDeFrais
    {
        return $this->typeNoteDeFrais;
    }

    public function setTypeNoteDeFrais(?TypeNoteDeFrais $typeNoteDeFrais): static
    {
        $this->typeNoteDeFrais = $typeNoteDeFrais;

        return $this;
    }
}
