<?php

namespace App\Entity;

use App\Repository\CashRegisterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Poste de caisse (15/10/2026) : un site peut en avoir plusieurs, chacun avec sa propre session (Caisse) ouverte.
 * Jamais supprimé (historique des sessions et des ventes) : désactivé. Schéma : migrations/Version20261015120000.php.
 */
#[ORM\Entity(repositoryClass: CashRegisterRepository::class)]
#[ORM\Table(name: 'cash_register')]
#[ORM\UniqueConstraint(name: 'uniq_cash_register_name', columns: ['name'])]
class CashRegister
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    /** @var Collection<int, Caisse> sessions du poste */
    #[ORM\OneToMany(mappedBy: 'cashRegister', targetEntity: Caisse::class)]
    private Collection $sessions;

    // Terminal de paiement Stripe du poste (C4, 23/10/2026) : lecteur rattaché (Reader sur le compte connecté du site)
    #[ORM\Column(options: ['default' => false])]
    private bool $terminalEnabled = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeReaderId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeReaderLabel = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $stripeReaderSimulated = false;

    /** Saisies EasyAdmin non persistées : code d'appairage d'un lecteur réel, création d'un lecteur simulé (clé de test) */
    public ?string $pairingCode = null;
    public bool $createSimulatedReader = false;
    public bool $detachReader = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->sessions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /** @return Collection<int, Caisse> */
    public function isTerminalEnabled(): bool { return $this->terminalEnabled; }
    public function setTerminalEnabled(bool $enabled): static { $this->terminalEnabled = $enabled; return $this; }
    public function getStripeReaderId(): ?string { return $this->stripeReaderId; }
    public function setStripeReaderId(?string $id): static { $this->stripeReaderId = $id; return $this; }
    public function getStripeReaderLabel(): ?string { return $this->stripeReaderLabel; }
    public function setStripeReaderLabel(?string $label): static { $this->stripeReaderLabel = $label !== null ? mb_substr($label, 0, 100) : null; return $this; }
    public function isStripeReaderSimulated(): bool { return $this->stripeReaderSimulated; }
    public function setStripeReaderSimulated(bool $simulated): static { $this->stripeReaderSimulated = $simulated; return $this; }
    /** Terminal utilisable : activé et lecteur rattaché */
    public function hasTerminal(): bool { return $this->terminalEnabled && $this->stripeReaderId !== null; }

    public function getSessions(): Collection
    {
        return $this->sessions;
    }

    public function __toString(): string
    {
        return $this->name . ($this->isActive ? '' : ' (inactif)');
    }
}
