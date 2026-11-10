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

    // #[ORM\Column(length: 255, nullable: true)]
    // private ?string $image = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tracknumber = null;

    #[ORM\Column(nullable: true)]
    private ?int $price = null; // cents (lot G1, 09/11/2026)

    /** Côté propriétaire : jamais de `remove` en cascade (jusqu'au 09/11/2026, supprimer le frais de port supprimait la commande et ses produits) */
    #[ORM\OneToOne(inversedBy: 'fraisDePort', cascade: ['persist'])]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(inversedBy: 'fraisDePorts')]
    private ?Transporteur $transporteur = null;

    /** Justificatif lu par IA (lot L1, 10/11/2026) ; effacé avec le document (ON DELETE SET NULL) */
    #[ORM\ManyToOne(targetEntity: GestionDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: true, onDelete: 'SET NULL')]
    private ?GestionDocument $document = null;

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

    // public function getImage(): ?string
    // {
    //     return $this->image;
    // }

    // public function setImage(?string $image): static
    // {
    //     $this->image = $image;

    //     return $this;
    // }

    public function gettracknumber(): ?string
    {
        return $this->tracknumber;
    }

    public function settracknumber(?string $tracknumber): static
    {
        $this->tracknumber = $tracknumber;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
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

    public function getDocument(): ?GestionDocument
    {
        return $this->document;
    }

    public function setDocument(?GestionDocument $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function setTransporteur(?Transporteur $transporteur): static
    {
        $this->transporteur = $transporteur;

        return $this;
    }

    public function getTransporteurLogoPath(): ?string
    {
        return $this->transporteur?->getLogo();
    }
}
