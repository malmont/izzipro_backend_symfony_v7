<?php

namespace App\Entity;

use App\Repository\CommandeStatistiquesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeStatistiquesRepository::class)]
class CommandeStatistiques
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'commandeStatistiques')]
    private ?Commande $commande = null;

    #[ORM\Column(nullable: true)]
    private ?float $averageMultiplier = null;

    #[ORM\Column(nullable: true)]
    private ?int $generalBudget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?int $usedBudget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?int $remainingBudget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?int $totalItemCost = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?int $totalFraisDePort = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?int $itemCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $modelCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $stockValue = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?int $marge = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(nullable: true)]
    private ?float $tauxMarge = null;

    #[ORM\Column(nullable: true)]
    private ?float $tauxMarque = null;

    #[ORM\ManyToOne(inversedBy: 'commandeStatistiques')]
    private ?Transporteur $transporteur = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAverageMultiplier(): ?float
    {
        return $this->averageMultiplier;
    }

    public function setAverageMultiplier(?float $averageMultiplier): static
    {
        $this->averageMultiplier = $averageMultiplier;

        return $this;
    }

    public function getGeneralBudget(): ?int
    {
        return $this->generalBudget;
    }

    public function setGeneralBudget(?int $generalBudget): static
    {
        $this->generalBudget = $generalBudget;

        return $this;
    }

    public function getUsedBudget(): ?int
    {
        return $this->usedBudget;
    }

    public function setUsedBudget(?int $usedBudget): static
    {
        $this->usedBudget = $usedBudget;

        return $this;
    }

    public function getRemainingBudget(): ?int
    {
        return $this->remainingBudget;
    }

    public function setRemainingBudget(?int $remainingBudget): static
    {
        $this->remainingBudget = $remainingBudget;

        return $this;
    }

    public function getTotalItemCost(): ?int
    {
        return $this->totalItemCost;
    }

    public function setTotalItemCost(?int $totalItemCost): static
    {
        $this->totalItemCost = $totalItemCost;

        return $this;
    }

    public function getTotalFraisDePort(): ?int
    {
        return $this->totalFraisDePort;
    }

    public function setTotalFraisDePort(?int $totalFraisDePort): static
    {
        $this->totalFraisDePort = $totalFraisDePort;

        return $this;
    }

    public function getItemCount(): ?int
    {
        return $this->itemCount;
    }

    public function setItemCount(?int $itemCount): static
    {
        $this->itemCount = $itemCount;

        return $this;
    }

    public function getModelCount(): ?int
    {
        return $this->modelCount;
    }

    public function setModelCount(?int $modelCount): static
    {
        $this->modelCount = $modelCount;

        return $this;
    }

    public function getStockValue(): ?int
    {
        return $this->stockValue;
    }

    public function setStockValue(?int $stockValue): static
    {
        $this->stockValue = $stockValue;

        return $this;
    }

    public function getMarge(): ?int
    {
        return $this->marge;
    }

    public function setMarge(?int $marge): static
    {
        $this->marge = $marge;

        return $this;
    }

    public function getTauxMarge(): ?float
    {
        return $this->tauxMarge;
    }

    public function setTauxMarge(?float $tauxMarge): static
    {
        $this->tauxMarge = $tauxMarge;

        return $this;
    }

    public function getTauxMarque(): ?float
    {
        return $this->tauxMarque;
    }

    public function setTauxMarque(?float $tauxMarque): static
    {
        $this->tauxMarque = $tauxMarque;

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
