<?php
namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
#[ApiResource]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?float $budget = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    private ?Collections $collections = null;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: Product::class, cascade: ['persist', 'remove'])]
    private Collection $products;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    private ?Fournisseur $fournisseur = null;

    #[ORM\OneToOne(mappedBy: 'commande', cascade: ['persist', 'remove'])]
    private ?FraisDePort $fraisDePort = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isClosed = false;

    /**
     * @var Collection<int, CommandeStatistiques>
     */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: CommandeStatistiques::class)]
    private Collection $commandeStatistiques;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->commandeStatistiques = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudget(): ?float
    {
        return $this->budget;
    }

    public function setBudget(float $budget): static
    {
        $this->budget = $budget;

        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getCollections(): ?Collections
    {
        return $this->collections;
    }

    public function setCollections(?Collections $collections): static
    {
        $this->collections = $collections;

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setCommande($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            if ($product->getCommande() === $this) {
                $product->setCommande(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getFraisDePort(): ?FraisDePort
    {
        return $this->fraisDePort;
    }

    public function setFraisDePort(?FraisDePort $fraisDePort): static
    {
        if ($fraisDePort === null && $this->fraisDePort !== null) {
            $this->fraisDePort->setCommande(null);
        }

        if ($fraisDePort !== null && $fraisDePort->getCommande() !== $this) {
            $fraisDePort->setCommande($this);
        }

        $this->fraisDePort = $fraisDePort;

        return $this;
    }

    public function getIsClosed(): ?bool
    {
        return $this->isClosed;
    }

    public function setIsClosed(?bool $isClosed): static
    {
        $this->isClosed = $isClosed;

        return $this;
    }

    /**
     * @return Collection<int, CommandeStatistiques>
     */
    public function getCommandeStatistiques(): Collection
    {
        return $this->commandeStatistiques;
    }

    public function addCommandeStatistiques(CommandeStatistiques $commandeStatistique): static
    {
        if (!$this->commandeStatistiques->contains($commandeStatistique)) {
            $this->commandeStatistiques->add($commandeStatistique);
            $commandeStatistique->setCommande($this);
        }

        return $this;
    }

    public function removeCommandeStatistiques(CommandeStatistiques $commandeStatistique): static
    {
        if ($this->commandeStatistiques->removeElement($commandeStatistique)) {
            if ($commandeStatistique->getCommande() === $this) {
                $commandeStatistique->setCommande(null);
            }
        }

        return $this;
    }
}
