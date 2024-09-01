<?php

namespace App\Entity;

use App\Repository\CaisseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CaisseRepository::class)]
class Caisse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?float $amountTotal = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @var Collection<int, TransactionCaisse>
     */
    #[ORM\OneToMany(mappedBy: 'caisse', targetEntity: TransactionCaisse::class)]
    private Collection $transactionCaisses;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private ?bool $isOpen = false;

    public function __construct()
    {
        $this->transactionCaisses = new ArrayCollection();
        $this->isOpen = true; // I
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmountTotal(): ?float
    {
        return $this->amountTotal;
    }

    public function setAmountTotal(float $amountTotal): static
    {
        $this->amountTotal = $amountTotal;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, TransactionCaisse>
     */
    public function getTransactionCaisses(): Collection
    {
        return $this->transactionCaisses;
    }

    public function addTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if (!$this->transactionCaisses->contains($transactionCaiss)) {
            $this->transactionCaisses->add($transactionCaiss);
            $transactionCaiss->setCaisse($this);
        }

        return $this;
    }

    public function removeTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if ($this->transactionCaisses->removeElement($transactionCaiss)) {
            // set the owning side to null (unless already changed)
            if ($transactionCaiss->getCaisse() === $this) {
                $transactionCaiss->setCaisse(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            'Caisse #%d (Montant: %.2f, Date: %s)',
            $this->id,
            $this->amountTotal,
            $this->createdAt ? $this->createdAt->format('Y-m-d H:i:s') : 'N/A'
        );
    }

    public function isOpen(): ?bool
    {
        return $this->isOpen;
    }

    public function setOpen(bool $isOpen): static
    {
        $this->isOpen = $isOpen;

        return $this;
    }
}
