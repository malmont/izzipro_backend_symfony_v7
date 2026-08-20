<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    private ?string $plainPassword = null;

    #[ORM\Column(length: 255)]
    private ?string $username = null;

    #[ORM\Column(length: 255)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255)]
    private ?string $lastname = null;

    #[ORM\Column(type: 'boolean')]
    private $isVerified = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\OneToMany(mappedBy: 'userAdress', targetEntity: Adress::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $adresses;

    #[ORM\OneToMany(mappedBy: 'userReview', targetEntity: ReviewsProduct::class)]
    private Collection $reviewsProducts;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;


    /**
     * @var Collection<int, Collections>
     */
    #[ORM\OneToMany(mappedBy: 'userCollections', targetEntity: Collections::class)]
    private Collection $collections;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(mappedBy: 'userId', targetEntity: Order::class)]
    private Collection $userOrders;

    /**
     * @var Collection<int, TransactionCaisse>
     */
    #[ORM\OneToMany(mappedBy: 'userCaisse', targetEntity: TransactionCaisse::class)]
    private Collection $transactionCaisses;

    #[ORM\Column(nullable: true)]
    private ?bool $otpEnabled = false;

    /**
     * @var Collection<int, OtpCode>
     */
    #[ORM\OneToMany(mappedBy: 'userOtp', targetEntity: OtpCode::class)]
    private Collection $otpCodes;

    #[ORM\OneToOne(targetEntity: Adress::class, inversedBy: 'userPrimaryAdress', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: "primary_address_id", referencedColumnName: "id", onDelete: 'SET NULL')]
    private ?Adress $primaryAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $licenseNumber = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $licenseExpirationDate = null;

    public function __construct()
    {
        $this->adresses = new ArrayCollection();
        $this->reviewsProducts = new ArrayCollection();
        $this->collections = new ArrayCollection();
        $this->userOrders = new ArrayCollection();
        $this->transactionCaisses = new ArrayCollection();
        $this->otpCodes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getAdresses(): Collection
    {
        return $this->adresses;
    }

    public function addAdress(Adress $adress): self
    {
        if (!$this->adresses->contains($adress)) {
            $this->adresses[] = $adress;
            $adress->setUserAdress($this);
        }

        return $this;
    }

    public function removeAdress(Adress $adress): self
    {
        if ($this->adresses->removeElement($adress)) {
            if ($adress->getUserAdress() === $this) {
                $adress->setUserAdress(null);
            }
        }

        return $this;
    }

    public function getReviewsProducts(): Collection
    {
        return $this->reviewsProducts;
    }

    public function addReviewsProduct(ReviewsProduct $reviewsProduct): self
    {
        if (!$this->reviewsProducts->contains($reviewsProduct)) {
            $this->reviewsProducts[] = $reviewsProduct;
            $reviewsProduct->setUserReview($this);
        }

        return $this;
    }

    public function removeReviewsProduct(ReviewsProduct $reviewsProduct): self
    {
        if ($this->reviewsProducts->removeElement($reviewsProduct)) {
            if ($reviewsProduct->getUserReview() === $this) {
                $reviewsProduct->setUserReview(null);
            }
        }

        return $this;
    }

 

    /**
     * @return Collection<int, Collections>
     */
    public function getCollections(): Collection
    {
        return $this->collections;
    }

    public function addCollection(Collections $collection): static
    {
        if (!$this->collections->contains($collection)) {
            $this->collections->add($collection);
            $collection->setUserCollections($this);
        }

        return $this;
    }

    public function removeCollection(Collections $collection): static
    {
        if ($this->collections->removeElement($collection)) {
            // set the owning side to null (unless already changed)
            if ($collection->getUserCollections() === $this) {
                $collection->setUserCollections(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->firstname . ' ' . $this->lastname; // Ou toute autre combinaison de champs souhaitée
    }
    public function getFullName(): string
    {
        return $this->firstname . ' ' . $this->lastname;
    }

    /**
     * @return Collection<int, Order>
     */
    public function getUserOrders(): Collection
    {
        return $this->userOrders;
    }

    public function addUserOrder(Order $userOrder): static
    {
        if (!$this->userOrders->contains($userOrder)) {
            $this->userOrders->add($userOrder);
            $userOrder->setUserId($this);
        }

        return $this;
    }

    public function removeUserOrder(Order $userOrder): static
    {
        if ($this->userOrders->removeElement($userOrder)) {
            // set the owning side to null (unless already changed)
            if ($userOrder->getUserId() === $this) {
                $userOrder->setUserId(null);
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
            $transactionCaiss->setUserCaisse($this);
        }

        return $this;
    }

    public function removeTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if ($this->transactionCaisses->removeElement($transactionCaiss)) {
            // set the owning side to null (unless already changed)
            if ($transactionCaiss->getUserCaisse() === $this) {
                $transactionCaiss->setUserCaisse(null);
            }
        }

        return $this;
    }


    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $verificationToken): self
    {
        $this->verificationToken = $verificationToken;
        return $this;
    }

   public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeInterface $resetTokenExpiresAt): self
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    public function isOtpEnabled(): ?bool
    {
        return $this->otpEnabled;
    }

    public function setOtpEnabled(?bool $otpEnabled): static
    {
        $this->otpEnabled = $otpEnabled;

        return $this;
    }

    /**
     * @return Collection<int, OtpCode>
     */
    public function getOtpCodes(): Collection
    {
        return $this->otpCodes;
    }

    public function addOtpCode(OtpCode $otpCode): static
    {
        if (!$this->otpCodes->contains($otpCode)) {
            $this->otpCodes->add($otpCode);
            $otpCode->setUserOtp($this);
        }

        return $this;
    }

    public function removeOtpCode(OtpCode $otpCode): static
    {
        if ($this->otpCodes->removeElement($otpCode)) {
            // set the owning side to null (unless already changed)
            if ($otpCode->getUserOtp() === $this) {
                $otpCode->setUserOtp(null);
            }
        }

        return $this;
    }



    public function getPrimaryAddress(): ?Adress
    {
        return $this->primaryAddress;
    }

    public function setPrimaryAddress(?Adress $primaryAddress): static
    {

        if ($this->primaryAddress !== null && $this->primaryAddress !== $primaryAddress) {
            $this->primaryAddress->setUserPrimaryAdress(null);
        }

        $this->primaryAddress = $primaryAddress;
        if ($primaryAddress !== null) {
            $primaryAddress->setUserPrimaryAdress($this);
        }

        return $this;
    }




    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    public function setLicenseNumber(?string $licenseNumber): self
    {
        $this->licenseNumber = $licenseNumber;
        return $this;
    }

    public function getLicenseExpirationDate(): ?\DateTimeInterface
    {
        return $this->licenseExpirationDate;
    }

    public function setLicenseExpirationDate(?\DateTimeInterface $licenseExpirationDate): self
    {
        $this->licenseExpirationDate = $licenseExpirationDate;
        return $this;
    }
}
