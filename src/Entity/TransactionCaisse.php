<?php

namespace App\Entity;

use App\Repository\TransactionCaisseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionCaisseRepository::class)]
class TransactionCaisse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCaisses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Caisse $caisse = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCaisses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $userCaisse = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCaisses')]
    private ?Order $orderCaisse = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCaisses')]
    private ?Payments $payment = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $transactionDate = null;

    #[ORM\Column]
    private ?float $amount = null;

    #[ORM\ManyToOne(inversedBy: 'transactionCaisses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TransactionType $transactionType = null;

    /**
     * @var Collection<int, CashDetails>
     */
    #[ORM\OneToMany(mappedBy: 'transactionCaisse', targetEntity: CashDetails::class, cascade: ['persist', 'remove'])]
    private Collection $cashdetails;

    public function __construct()
    {
        $this->cashdetails = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaisse(): ?Caisse
    {
        return $this->caisse;
    }

    public function setCaisse(?Caisse $caisse): static
    {
        $this->caisse = $caisse;

        return $this;
    }

    public function getUserCaisse(): ?User
    {
        return $this->userCaisse;
    }

    public function setUserCaisse(?User $userCaisse): static
    {
        $this->userCaisse = $userCaisse;

        return $this;
    }

    public function getOrderCaisse(): ?Order
    {
        return $this->orderCaisse;
    }

    public function setOrderCaisse(?Order $orderCaisse): static
    {
        $this->orderCaisse = $orderCaisse;

        return $this;
    }

    public function getPayment(): ?Payments
    {
        return $this->payment;
    }

    public function setPayment(?Payments $payment): static
    {
        $this->payment = $payment;

        return $this;
    }

    public function getTransactionDate(): ?\DateTimeInterface
    {
        return $this->transactionDate;
    }

    public function setTransactionDate(\DateTimeInterface $transactionDate): static
    {
        $this->transactionDate = $transactionDate;

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

    public function getTransactionType(): ?TransactionType
    {
        return $this->transactionType;
    }

    public function setTransactionType(?TransactionType $transactionType): static
    {
        $this->transactionType = $transactionType;

        return $this;
    }

    /**
     * @return Collection<int, CashDetails>
     */
    public function getCashDetails(): Collection
    {
        return $this->cashdetails;
    }

    public function addCashDetail(CashDetails $cashDetail): static
    {
        if (!$this->cashdetails->contains($cashDetail)) {
            $this->cashdetails->add($cashDetail);
            $cashDetail->setTransactionCaisse($this);
        }

        return $this;
    }

    public function removeCashDetail(CashDetails $cashDetail): static
    {
        if ($this->cashdetails->removeElement($cashDetail)) {
            if ($cashDetail->getTransactionCaisse() === $this) {
                $cashDetail->setTransactionCaisse(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->id ?: 'N/A';
    }
}
