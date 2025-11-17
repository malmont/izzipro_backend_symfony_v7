<?php
// src/Entity/SyncJob.php

namespace App\Entity;

use App\Repository\SyncJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncJobRepository::class)]
class SyncJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; 

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $currentStep = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalItems = 0;

    #[ORM\Column(options: ['default' => 0])] 
    private int $processedItems = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCurrentStep(): ?string
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?string $currentStep): static
    {
        $this->currentStep = $currentStep;
        return $this;
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function setTotalItems(int $totalItems): static
    {
        $this->totalItems = $totalItems;
        return $this;
    }

    public function getProcessedItems(): int
    {
        return $this->processedItems;
    }

    public function setProcessedItems(int $processedItems): static
    {
        $this->processedItems = $processedItems;
        return $this;
    }
    
 
    public function incrementProcessedItems(int $count = 1): static
    {
        $this->processedItems += $count;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;
        return $this;
    }

    // --- NOTRE MÉTHODE UTILE ---
    public function getPercent(): float
    {
        if ($this->totalItems === 0) {
            return 0.0;
        }
        $percent = ($this->processedItems / $this->totalItems) * 100;
        return min($percent, 100.0); // Évite de dépasser 100%
    }
}