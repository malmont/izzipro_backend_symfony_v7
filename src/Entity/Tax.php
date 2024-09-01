<?php

namespace App\Entity;

use App\Repository\TaxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxRepository::class)]
class Tax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $rate = null;

    #[ORM\Column(length: 255)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $province = null;

    /**
     * @var Collection<int, OrderTax>
     */
    #[ORM\OneToMany(mappedBy: 'tax', targetEntity: OrderTax::class)]
    private Collection $orderTaxes;

    public function __construct()
    {
        $this->orderTaxes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getRate(): ?float
    {
        return $this->rate;
    }

    public function setRate(float $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getProvince(): ?string
    {
        return $this->province;
    }

    public function setProvince(?string $province): static
    {
        $this->province = $province;

        return $this;
    }

    /**
     * @return Collection<int, OrderTax>
     */
    public function getOrderTaxes(): Collection
    {
        return $this->orderTaxes;
    }

    public function addOrderTax(OrderTax $orderTax): static
    {
        if (!$this->orderTaxes->contains($orderTax)) {
            $this->orderTaxes->add($orderTax);
            $orderTax->setTax($this);
        }

        return $this;
    }

    public function removeOrderTax(OrderTax $orderTax): static
    {
        if ($this->orderTaxes->removeElement($orderTax)) {
            // set the owning side to null (unless already changed)
            if ($orderTax->getTax() === $this) {
                $orderTax->setTax(null);
            }
        }

        return $this;
    }
}
