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
    private ?float $generalBudget = null;

    #[ORM\Column(nullable: true)]
    private ?float $usedBudget = null;

    #[ORM\Column(nullable: true)]
    private ?float $remainingBudget = null;

    #[ORM\Column(nullable: true)]
    private ?float $totalItemCost = null;

    #[ORM\Column(nullable: true)]
    private ?float $totalFraisDePort = null;

    #[ORM\Column(nullable: true)]
    private ?int $itemCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $modelCount = null;

    #[ORM\Column(nullable: true)]
    private ?float $stockValue = null;

    #[ORM\Column(nullable: true)]
    private ?float $marge = null;

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

    public function getGeneralBudget(): ?float
    {
        return $this->generalBudget;
    }

    public function setGeneralBudget(?float $generalBudget): static
    {
        $this->generalBudget = $generalBudget;

        return $this;
    }

    public function getUsedBudget(): ?float
    {
        return $this->usedBudget;
    }

    public function setUsedBudget(?float $usedBudget): static
    {
        $this->usedBudget = $usedBudget;

        return $this;
    }

    public function getRemainingBudget(): ?float
    {
        return $this->remainingBudget;
    }

    public function setRemainingBudget(?float $remainingBudget): static
    {
        $this->remainingBudget = $remainingBudget;

        return $this;
    }

    public function getTotalItemCost(): ?float
    {
        return $this->totalItemCost;
    }

    public function setTotalItemCost(?float $totalItemCost): static
    {
        $this->totalItemCost = $totalItemCost;

        return $this;
    }

    public function getTotalFraisDePort(): ?float
    {
        return $this->totalFraisDePort;
    }

    public function setTotalFraisDePort(?float $totalFraisDePort): static
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

    public function getStockValue(): ?float
    {
        return $this->stockValue;
    }

    public function setStockValue(?float $stockValue): static
    {
        $this->stockValue = $stockValue;

        return $this;
    }

    public function getMarge(): ?float
    {
        return $this->marge;
    }

    public function setMarge(?float $marge): static
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
