<?php

namespace App\Entity;

use App\Repository\OrderTaxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderTaxRepository::class)]
class OrderTax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orderTaxes')]
    private ?Order $orderTax = null;

    #[ORM\ManyToOne(inversedBy: 'orderTaxes')]
    private ?Tax $tax = null;

    #[ORM\Column]
    private ?float $amount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderTax(): ?Order
    {
        return $this->orderTax;
    }

    public function setOrderTax(?Order $orderTax): static
    {
        $this->orderTax = $orderTax;

        return $this;
    }

    public function getTax(): ?Tax
    {
        return $this->tax;
    }

    public function setTax(?Tax $tax): static
    {
        $this->tax = $tax;

        return $this;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): static
    {
        $this->amount = $amount;

        return $this;
    }
}
