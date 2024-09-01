<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    #[ORM\ManyToOne(inversedBy: 'userOrders')]
    private ?User $userId = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $orderDate = null;

    #[ORM\Column]
    private ?float $totalAmount = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?Adress $shippingAdress = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?OrderSource $orderSource = null;

    /**
     * @var Collection<int, OrderItems>
     */
    #[ORM\OneToMany(mappedBy: 'orderAssociated', targetEntity: OrderItems::class)]
    private Collection $orderItems;

    /**
     * @var Collection<int, Payments>
     */
    #[ORM\OneToMany(mappedBy: 'orderPayment', targetEntity: Payments::class)]
    private Collection $payments;

    /**
     * @var Collection<int, TransactionCaisse>
     */
    #[ORM\OneToMany(mappedBy: 'orderCaisse', targetEntity: TransactionCaisse::class)]
    private Collection $transactionCaisses;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?Carrier $carrier = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?StatusCommande $status = null;

    #[ORM\Column(nullable: true)]
    private ?float $subTotal = null;

    #[ORM\Column(nullable: true)]
    private ?float $total_tax = null;

    /**
     * @var Collection<int, OrderTax>
     */
    #[ORM\OneToMany(mappedBy: 'orderTax', targetEntity: OrderTax::class)]
    private Collection $orderTaxes;

   
    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->transactionCaisses = new ArrayCollection();
        $this->orderTaxes = new ArrayCollection();

    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getUserId(): ?User
    {
        return $this->userId;
    }

    public function setUserId(?User $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getOrderDate(): ?\DateTimeInterface
    {
        return $this->orderDate;
    }

    public function setOrderDate(\DateTimeInterface $orderDate): static
    {
        $this->orderDate = $orderDate;

        return $this;
    }

    public function getTotalAmount(): ?float
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(float $totalAmount): static
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getShippingAdress(): ?Adress
    {
        return $this->shippingAdress;
    }

    public function setShippingAdress(?Adress $shippingAdress): static
    {
        $this->shippingAdress = $shippingAdress;

        return $this;
    }

    public function getOrderSource(): ?OrderSource
    {
        return $this->orderSource;
    }

    public function setOrderSource(?OrderSource $orderSource): static
    {
        $this->orderSource = $orderSource;

        return $this;
    }

    /**
     * @return Collection<int, OrderItems>
     */
    public function getOrderItems(): Collection
    {
        return $this->orderItems;
    }

    public function addOrderItem(OrderItems $orderItem): static
    {
        if (!$this->orderItems->contains($orderItem)) {
            $this->orderItems->add($orderItem);
            $orderItem->setOrderAssociated($this);
        }

        return $this;
    }

    public function removeOrderItem(OrderItems $orderItem): static
    {
        if ($this->orderItems->removeElement($orderItem)) {
            // set the owning side to null (unless already changed)
            if ($orderItem->getOrderAssociated() === $this) {
                $orderItem->setOrderAssociated(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Payments>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payments $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setOrderPayment($this);
        }

        return $this;
    }

    public function removePayment(Payments $payment): static
    {
        if ($this->payments->removeElement($payment)) {
            // set the owning side to null (unless already changed)
            if ($payment->getOrderPayment() === $this) {
                $payment->setOrderPayment(null);
            }
        }

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
            $transactionCaiss->setOrderCaisse($this);
        }

        return $this;
    }

    public function removeTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if ($this->transactionCaisses->removeElement($transactionCaiss)) {
            // set the owning side to null (unless already changed)
            if ($transactionCaiss->getOrderCaisse() === $this) {
                $transactionCaiss->setOrderCaisse(null);
            }
        }

        return $this;
    }

    public function getCarrier(): ?Carrier
    {
        return $this->carrier;
    }

    public function setCarrier(?Carrier $carrier): static
    {
        $this->carrier = $carrier;

        return $this;
    }

    public function getStatus(): ?StatusCommande
    {
        return $this->status;
    }

    public function setStatus(?StatusCommande $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function __toString(): string
    {
        return $this->reference ?? 'N/A';
    }

    public function getSubTotal(): ?float
    {
        return $this->subTotal;
    }

    public function setSubTotal(?float $subTotal): static
    {
        $this->subTotal = $subTotal;

        return $this;
    }

    public function getTotalTax(): ?float
    {
        return $this->total_tax;
    }

    public function setTotalTax(?float $total_tax): static
    {
        $this->total_tax = $total_tax;

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
            $orderTax->setOrderTax($this);
        }

        return $this;
    }

    public function removeOrderTax(OrderTax $orderTax): static
    {
        if ($this->orderTaxes->removeElement($orderTax)) {
            // set the owning side to null (unless already changed)
            if ($orderTax->getOrderTax() === $this) {
                $orderTax->setOrderTax(null);
            }
        }

        return $this;
    }

  
}
