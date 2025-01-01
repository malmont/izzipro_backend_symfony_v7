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
    private ?float $value = null;

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
