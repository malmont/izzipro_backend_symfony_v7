<?php

namespace App\Entity;

use App\Repository\CartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: '`Cart`')]
class Cart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    private ?string $fullname = null;

    #[ORM\Column(length: 255)]
    private ?string $carriername = null;

    #[ORM\Column]
    private ?float $carrierprice = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $deleveryaddress = null;

    #[ORM\Column]
    private ?bool $ispaid = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $moreinformations = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'Carts', targetEntity: CartDetails::class)]
    private Collection $CartDetails;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $userCart = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column]
    private ?float $subTotalHt = null;

    #[ORM\Column]
    private ?float $taxe = null;

    #[ORM\Column]
    private ?float $subTotalTTC = null;

    public function __construct()
    {
        $this->CartDetails = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getFullname(): ?string
    {
        return $this->fullname;
    }

    public function setFullname(string $fullname): self
    {
        $this->fullname = $fullname;

        return $this;
    }

    public function getCarriername(): ?string
    {
        return $this->carriername;
    }

    public function setCarriername(string $carriername): self
    {
        $this->carriername = $carriername;

        return $this;
    }

    public function getCarrierprice(): ?float
    {
        return $this->carrierprice *100;
    }

    public function setCarrierprice(float $carrierprice): self
    {
        $this->carrierprice = $carrierprice;

        return $this;
    }

    public function getDeleveryaddress(): ?string
    {
        return $this->deleveryaddress;
    }

    public function setDeleveryaddress(string $deleveryaddress): self
    {
        $this->deleveryaddress = $deleveryaddress;

        return $this;
    }

    public function isIspaid(): ?bool
    {
        return $this->ispaid;
    }

    public function setIspaid(bool $ispaid): self
    {
        $this->ispaid = $ispaid;

        return $this;
    }

    public function getMoreinformations(): ?string
    {
        return $this->moreinformations;
    }

    public function setMoreinformations(?string $moreinformations): self
    {
        $this->moreinformations = $moreinformations;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, CartDetails>
     */
    public function getCartDetails(): Collection
    {
        return $this->CartDetails;
    }

    public function addCartDetail(CartDetails $CartDetail): self
    {
        if (!$this->CartDetails->contains($CartDetail)) {
            $this->CartDetails->add($CartDetail);
            $CartDetail->setCarts($this);
        }

        return $this;
    }

    public function removeCartDetail(CartDetails $CartDetail): self
    {
        if ($this->CartDetails->removeElement($CartDetail)) {
            // set the owning side to null (unless already changed)
            if ($CartDetail->getCarts() === $this) {
                $CartDetail->setCarts(null);
            }
        }

        return $this;
    }

    public function getUserCart(): ?User
    {
        return $this->userCart;
    }

    public function setUserCart(?User $userCart): self
    {
        $this->userCart = $userCart;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getSubTotalHt(): ?float
    {
        return $this->subTotalHt * 100;
    }

    public function setSubTotalHt(float $subTotalHt): self
    {
        $this->subTotalHt = $subTotalHt;

        return $this;
    }

    public function getTaxe(): ?float
    {
        return $this->taxe * 100;
    }

    public function setTaxe(float $taxe): self
    {
        $this->taxe = $taxe;

        return $this;
    }

    public function getSubTotalTTC(): ?float
    {
        return $this->subTotalTTC *100;
    }

    public function setSubTotalTTC(float $subTotalTTC): self
    {
        $this->subTotalTTC = $subTotalTTC;

        return $this;
    }
}
