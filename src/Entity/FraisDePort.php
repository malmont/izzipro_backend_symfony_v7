<?php

namespace App\Entity;

use App\Repository\FraisDePortRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FraisDePortRepository::class)]
class FraisDePort
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facture = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tracknumber = null;

    #[ORM\Column(nullable: true)]
    private ?float $price = null;

    #[ORM\OneToOne(inversedBy: 'fraisDePort', cascade: ['persist', 'remove'])]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(inversedBy: 'fraisDePorts')]
    private ?Transporteur $transporteur = null;

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

    public function getFacture(): ?string
    {
        return $this->facture;
    }

    public function setFacture(?string $facture): static
    {
        $this->facture = $facture;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function gettracknumber(): ?string
    {
        return $this->tracknumber;
    }

    public function settracknumber(?string $tracknumber): static
    {
        $this->tracknumber = $tracknumber;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getCommande(): ?Commande
    {
        return $this->commande;
    }

    public function setCommande(?Commande $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getTransporteur(): ?Transporteur
    {
        return $this->transporteur;
    }

    public function setTransporteur(?Transporteur $transporteur): static
    {
        $this->transporteur = $transporteur;

        return $this;
    }
}
