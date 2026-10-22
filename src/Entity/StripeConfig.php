<?php

namespace App\Entity;

use App\Repository\StripeConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StripeConfigRepository::class)]
class StripeConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $accountId = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    // Location Stripe Terminal du site (C4, 23/10/2026), créée sur le compte connecté depuis l'adresse de l'entreprise
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $terminalLocationId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function setAccountId(string $accountId): static
    {
        $this->accountId = $accountId;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getTerminalLocationId(): ?string { return $this->terminalLocationId; }
    public function setTerminalLocationId(?string $id): static { $this->terminalLocationId = $id; return $this; }
}
