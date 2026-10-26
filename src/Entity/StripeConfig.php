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

    // Compte Connect du mode test (celui de la boutique tant que la clé de la plateforme est de test) ; facultatif depuis M1 (magasin réel seul)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    // Location Stripe Terminal du site (C4, 23/10/2026), créée sur le compte connecté depuis l'adresse de l'entreprise
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $terminalLocationId = null;

    // Commission plateforme des paiements en ligne (R1, 23/10/2026), centièmes de % ; 100 = 1 %, comme avant
    #[ORM\Column(options: ['default' => 100])]
    private int $onlineFeeBp = 100;

    // Compte Connect RÉEL distinct (M1) : créé et suivi avec la clé réelle de la caisse ; actif quand Stripe autorise encaissement et virements
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $liveAccountId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $liveIsActive = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $liveTerminalLocationId = null;

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
    public function getLiveAccountId(): ?string { return $this->liveAccountId; }
    public function setLiveAccountId(?string $id): static { $this->liveAccountId = $id; return $this; }
    public function isLiveActive(): bool { return $this->liveIsActive; }
    public function setLiveIsActive(bool $v): static { $this->liveIsActive = $v; return $this; }
    public function getLiveTerminalLocationId(): ?string { return $this->liveTerminalLocationId; }
    public function setLiveTerminalLocationId(?string $id): static { $this->liveTerminalLocationId = $id; return $this; }
    public function getOnlineFeeBp(): int { return $this->onlineFeeBp; }
    public function setOnlineFeeBp(?int $bp): static { $this->onlineFeeBp = min(10000, max(0, (int) $bp)); return $this; }
}
