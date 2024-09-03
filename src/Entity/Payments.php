<?php

namespace App\Entity;

use App\Repository\PaymentsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentsRepository::class)]
class Payments
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?Order $orderPayment = null;

    #[ORM\Column]
    private ?float $amount = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $paymentDate = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?PaymentMethod $paymentMethod = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?StatusPayment $statutPayment = null;

    /**
     * @var Collection<int, TransactionCaisse>
     */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: TransactionCaisse::class)]
    private Collection $transactionCaisses;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?PaymentType $paymentType = null;


    public function __construct()
    {
        $this->transactionCaisses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderPayment(): ?Order
    {
        return $this->orderPayment;
    }

    public function setOrderPayment(?Order $orderPayment): static
    {
        $this->orderPayment = $orderPayment;

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

    public function getPaymentDate(): ?\DateTimeInterface
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(\DateTimeInterface $paymentDate): static
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethod $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getStatutPayment(): ?StatusPayment
    {
        return $this->statutPayment;
    }

    public function setStatutPayment(?StatusPayment $statutPayment): static
    {
        $this->statutPayment = $statutPayment;

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
            $transactionCaiss->setPayment($this);
        }

        return $this;
    }

    public function removeTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if ($this->transactionCaisses->removeElement($transactionCaiss)) {
            // set the owning side to null (unless already changed)
            if ($transactionCaiss->getPayment() === $this) {
                $transactionCaiss->setPayment(null);
            }
        }

        return $this;
    }

    public function getPaymentType(): ?PaymentType
    {
        return $this->paymentType;
    }

    public function setPaymentType(?PaymentType $paymentType): static
    {
        $this->paymentType = $paymentType;

        return $this;
    }
    public function __toString(): string
    {
        return $this->id;
    }
 
}
