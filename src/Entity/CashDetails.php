<?php

namespace App\Entity;

use App\Repository\CashDetailsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CashDetailsRepository::class)]
class CashDetails
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TypeCash::class, inversedBy: 'cashDetails')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TypeCash $typeCash = null;

    #[ORM\Column]
    private ?int $nombreItems = null;

    #[ORM\ManyToOne(targetEntity: TransactionCaisse::class, inversedBy: 'cashdetails')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TransactionCaisse $transactionCaisse = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypeCash(): ?TypeCash
    {
        return $this->typeCash;
    }

    public function setTypeCash(?TypeCash $typeCash): static
    {
        $this->typeCash = $typeCash;

        return $this;
    }

    public function getNombreItems(): ?int
    {
        return $this->nombreItems;
    }

    public function setNombreItems(int $nombreItems): static
    {
        $this->nombreItems = $nombreItems;

        return $this;
    }

    public function getTransactionCaisse(): ?TransactionCaisse
    {
        return $this->transactionCaisse;
    }

    public function setTransactionCaisse(?TransactionCaisse $transactionCaisse): static
    {
        $this->transactionCaisse = $transactionCaisse;

        return $this;
    }
}
