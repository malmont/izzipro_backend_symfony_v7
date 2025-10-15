<?php
namespace App\Entity;

use App\Repository\AdressRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdressRepository::class)]
#[ORM\HasLifecycleCallbacks] // Ajout de l'annotation pour les callbacks
class Adress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\Column(length: 255)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255)]
    private ?string $lastname = null;

    #[ORM\Column(length: 255)]
    private ?string $fullname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 20)]
    private ?string $phone = null; 

    #[ORM\Column(length: 255)]
    private ?string $city = null;

    #[ORM\Column(length: 10)]
    private ?string $codepostal = null; 

    #[ORM\Column(length: 255)]
    private ?string $country = null;

    #[ORM\ManyToOne(inversedBy: 'adresses')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $userAdress = null;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(mappedBy: 'shippingAdress', targetEntity: Order::class)]
    private Collection $orders;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $province = null;

    #[ORM\OneToOne(mappedBy: 'primaryAddress', cascade: ['persist', 'remove'])]
    private ?User $userPrimaryAdress = null;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;
        $this->updateFullname(); // Mise à jour du fullname
        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;
        $this->updateFullname(); // Mise à jour du fullname
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

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getComplement(): ?string
    {
        return $this->complement;
    }

    public function setComplement(?string $complement): self
    {
        $this->complement = $complement;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getCodepostal(): ?string
    {
        return $this->codepostal;
    }

    public function setCodepostal(string $codepostal): self
    {
        $this->codepostal = $codepostal;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getUserAdress(): ?User
    {
        return $this->userAdress;
    }

    public function setUserAdress(?User $userAdress): self
    {
        $this->userAdress = $userAdress;
        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateFullname(): void
    {
        $this->fullname = $this->lastname . ' ' . $this->firstname;
    }

    public function __toString()
    {
        $result = $this->fullname . "[spr]";
        if ($this->getCompany()) {
            $result .= $this->company . "[spr]";
        }
        $result .= $this->address . "[spr]";
        $result .= $this->complement . "[spr]";
        $result .= $this->codepostal . " - " . $this->city . "[spr]";
        $result .= $this->country . "[spr]";

        return $result;
    }
    public function getFormattedForChoice(): string
    {
        $parts = [];
        $parts[] = $this->fullname;
        if ($this->company) {
            $parts[] = $this->company;
        }
        $parts[] = $this->address;
        if ($this->complement) {
            $parts[] = $this->complement;
        }
        $parts[] = $this->codepostal . ' ' . $this->city;
        $parts[] = $this->country;

        // On retire les éléments vides (ex: si complement est null)
        // et on les joint avec une virgule et un espace.
        return implode(', ', array_filter($parts));
    }

    /**
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setShippingAdress($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): static
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getShippingAdress() === $this) {
                $order->setShippingAdress(null);
            }
        }

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

    public function getUserPrimaryAdress(): ?User
    {
        return $this->userPrimaryAdress;
    }

     public function setUserPrimaryAdress(?User $userPrimaryAdress): static
    {
        $this->userPrimaryAdress = $userPrimaryAdress;
        return $this;
    }
}
