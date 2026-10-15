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
    public function getSessions(): Collection
    {
        return $this->sessions;
    }

    public function __toString(): string
    {
        return $this->name . ($this->isActive ? '' : ' (inactif)');
    }
}
