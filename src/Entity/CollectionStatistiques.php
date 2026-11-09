<?php

namespace App\Entity;

use App\Repository\CollectionStatistiquesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionStatistiquesRepository::class)]
class CollectionStatistiques
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'collectionStatistiques')]
    private ?Collections $collection = null;

    #[ORM\Column]
    private ?int $generalBudget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $usedBudget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $remainingBudget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $totalItemCost = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $totalShippingCost = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $totalExpenseCost = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $orderCount = null;

    #[ORM\Column]
    private ?int $itemCount = null;

    #[ORM\Column]
    private ?int $modelCount = null;

    #[ORM\Column]
    private ?int $stockValue = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?int $margin = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column]
    private ?float $tauxMarge = null;

    #[ORM\Column]
    private ?float $tauxMarque = null;

    #[ORM\Column]
    private ?float $averageMultiplier = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column]
    private ?int $durationDays = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollection(): ?Collections
    {
        return $this->collection;
    }

    public function setCollection(?Collections $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function getGeneralBudget(): ?int
    {
        return $this->generalBudget;
    }

    public function setGeneralBudget(int $generalBudget): static
    {
        $this->generalBudget = $generalBudget;

        return $this;
    }

    public function getUsedBudget(): ?int
    {
        return $this->usedBudget;
    }

    public function setUsedBudget(int $usedBudget): static
    {
        $this->usedBudget = $usedBudget;

        return $this;
    }

    public function getRemainingBudget(): ?int
    {
        return $this->remainingBudget;
    }

    public function setRemainingBudget(int $remainingBudget): static
    {
        $this->remainingBudget = $remainingBudget;

        return $this;
    }

    public function getTotalItemCost(): ?int
    {
        return $this->totalItemCost;
    }

    public function setTotalItemCost(int $totalItemCost): static
    {
        $this->totalItemCost = $totalItemCost;

        return $this;
    }

    public function getTotalShippingCost(): ?int
    {
        return $this->totalShippingCost;
    }

    public function setTotalShippingCost(int $totalShippingCost): static
    {
        $this->totalShippingCost = $totalShippingCost;

        return $this;
    }

    public function getTotalExpenseCost(): ?int
    {
        return $this->totalExpenseCost;
    }

    public function setTotalExpenseCost(int $totalExpenseCost): static
    {
        $this->totalExpenseCost = $totalExpenseCost;

        return $this;
    }

    public function getOrderCount(): ?int
    {
        return $this->orderCount;
    }

    public function setOrderCount(int $orderCount): static
    {
        $this->orderCount = $orderCount;

        return $this;
    }

    public function getItemCount(): ?int
    {
        return $this->itemCount;
    }

    public function setItemCount(int $itemCount): static
    {
        $this->itemCount = $itemCount;

        return $this;
    }

    public function getModelCount(): ?int
    {
        return $this->modelCount;
    }

    public function setModelCount(int $modelCount): static
    {
        $this->modelCount = $modelCount;

        return $this;
    }

    public function getStockValue(): ?int
    {
        return $this->stockValue;
    }

    public function setStockValue(int $stockValue): static
    {
        $this->stockValue = $stockValue;

        return $this;
    }

    public function getMargin(): ?int
    {
        return $this->margin;
    }

    public function setMargin(int $margin): static
    {
        $this->margin = $margin;

        return $this;
    }

    public function getTauxMarge(): ?float
    {
        return $this->tauxMarge;
    }

    public function setTauxMarge(float $tauxMarge): static
    {
        $this->tauxMarge = $tauxMarge;

        return $this;
    }

    public function getTauxMarque(): ?float
    {
        return $this->tauxMarque;
    }

    public function setTauxMarque(float $tauxMarque): static
    {
        $this->tauxMarque = $tauxMarque;

        return $this;
    }

    public function getAverageMultiplier(): ?float
    {
        return $this->averageMultiplier;
    }

    public function setAverageMultiplier(float $averageMultiplier): static
    {
        $this->averageMultiplier = $averageMultiplier;

        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getDurationDays(): ?int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): static
    {
        $this->durationDays = $durationDays;

        return $this;
    }
}
