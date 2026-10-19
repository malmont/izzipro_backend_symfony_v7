<?php

namespace App\Entity;

use App\Repository\TypeCashRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypeCashRepository::class)]
class TypeCash
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(nullable: true)]
    private ?float $value = null; // en unités monétaires (dollars), historique

    // Coupures servies au POS (19/10/2026, GET /api/caisse/denominations) : valeur en cents, billet ou pièce, active,
    // devise, ordre d'affichage. Ids 1 à 10 fixés par le POS (100, 50, 10, 5, 2, 1 $, 25, 10, 5, 1 ¢), 20 $ en 11.
    #[ORM\Column(nullable: true)]
    private ?int $valueCents = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $kind = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    /**
     * @var Collection<int, CashDetails>
     */
    #[ORM\OneToMany(mappedBy: 'typeCash', targetEntity: CashDetails::class)]
    private Collection $cashDetails;

    public function __construct()
    {
        $this->cashDetails = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Valeur en cents (valueCents, sinon value en dollars) */
    public function getValueCents(): int
    {
        return $this->valueCents ?? (int) round(((float) $this->value) * 100);
    }

    public function setValueCents(?int $valueCents): static
    {
        $this->valueCents = $valueCents;

        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setValue(?float $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return Collection<int, CashDetails>
     */
    public function getCashDetails(): Collection
    {
        return $this->cashDetails;
    }

    public function addCashDetail(CashDetails $cashDetail): static
    {
        if (!$this->cashDetails->contains($cashDetail)) {
            $this->cashDetails->add($cashDetail);
            $cashDetail->setTypeCash($this);
        }

        return $this;
    }

    public function removeCashDetail(CashDetails $cashDetail): static
    {
        if ($this->cashDetails->removeElement($cashDetail)) {
            // set the owning side to null (unless already changed)
            if ($cashDetail->getTypeCash() === $this) {
                $cashDetail->setTypeCash(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?: 'N/A';
    }
}
