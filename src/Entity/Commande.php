<?php
namespace App\Entity;

use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
/** Plus d'exposition API Platform depuis le lot P1 (10/11/2026) : toutes les routes sont dédiées (`/api/commandes`, `/api/collections/{id}/commandes`, `DELETE`/`PATCH /api/commandes/{id}`) */
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $budget = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    // #[ORM\Column(length: 255, nullable: true)]
    // private ?string $photo = null;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    private ?Collections $collections = null;

    /**
     * @var Collection<int, Product>
     */
    /** Les produits de la boutique survivent à la commande : détachés à sa suppression (FK ON DELETE SET NULL, lot G4, 09/11/2026 ; avant : supprimés en cascade) */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: Product::class, cascade: ['persist'])]
    private Collection $products;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    private ?Fournisseur $fournisseur = null;

    /** Justificatif lu par IA (lot L1, 10/11/2026) ; effacé avec le document (ON DELETE SET NULL) */
    #[ORM\ManyToOne(targetEntity: GestionDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: true, onDelete: 'SET NULL')]
    private ?GestionDocument $document = null;

    /** Lignes d'achat (lot L0), côté inverse : lecture seule ici, écrites par CommandeLineService */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: CommandeLine::class)]
    private Collection $lines;

    public const KIND_STANDARD = 'standard';
    public const KIND_REPRISE = 'reprise';

    /** `standard`, ou `reprise` pour une commande posée par la reprise d'historique (lot P3, 10/11/2026) */
    #[ORM\Column(length: 16, options: ['default' => 'standard'])]
    private string $kind = self::KIND_STANDARD;

    #[ORM\OneToOne(mappedBy: 'commande', cascade: ['persist', 'remove'])]
    private ?FraisDePort $fraisDePort = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isClosed = false;

    /**
     * @var Collection<int, CommandeStatistiques>
     */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: CommandeStatistiques::class, cascade: ['remove'])] // statistiques figées supprimées avec la commande (lot G4)
    private Collection $commandeStatistiques;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    private ?CollectionPicture $commandepictures = null;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->lines = new ArrayCollection();
        $this->commandeStatistiques = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudget(): ?int
    {
        return $this->budget;
    }

    public function setBudget(int $budget): static
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

    // public function getPhoto(): ?string
    // {
    //     return $this->photo;
    // }

    // public function setPhoto(?string $photo): static
    // {
    //     $this->photo = $photo;

    //     return $this;
    // }

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

    /** @return Collection<int, CommandeLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /** État de réception (lot R1) : none (rien reçu ou aucune ligne), partial, complete */
    public function receptionStatus(): string
    {
        $ordered = 0;
        $received = 0;
        foreach ($this->lines as $line) {
            $ordered += $line->getQuantityOrdered();
            $received += min($line->getQuantityReceived(), $line->getQuantityOrdered());
        }
        if ($ordered === 0 || $received === 0) {
            return $ordered > 0 || $this->lines->count() === 0 ? 'none' : 'complete';
        }

        return $received >= $ordered ? 'complete' : 'partial';
    }

    public function getDocument(): ?GestionDocument
    {
        return $this->document;
    }

    public function setDocument(?GestionDocument $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function isReprise(): bool
    {
        return $this->kind === self::KIND_REPRISE;
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

    /** Devise d'achat de la commande, figée à sa création d'après le fournisseur (ISO 4217, lot G6, 10/11/2026) ; null = devise du site */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    /** Taux de change vers la devise du site (1 unité de la devise d'achat = taux unités du site), null = 1 ; colonne décimale, lue en texte par DBAL */
    #[ORM\Column(name: 'exchange_rate', type: 'decimal', precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $currency = strtoupper(trim((string) $currency));
        $this->currency = $currency === '' ? null : $currency;

        return $this;
    }

    public function getExchangeRate(): ?float
    {
        return $this->exchangeRate === null ? null : (float) $this->exchangeRate;
    }

    public function setExchangeRate(?float $rate): static
    {
        $this->exchangeRate = $rate === null ? null : number_format($rate, 6, '.', '');

        return $this;
    }

    /** Taux appliqué aux montants de la commande pour les ramener dans la devise du site (1 sans devise d'achat) */
    public function rate(): float
    {
        return $this->currency === null ? 1.0 : ($this->getExchangeRate() ?? 1.0);
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

    public function getCommandepictures(): ?CollectionPicture
    {
        return $this->commandepictures;
    }

    public function setCommandepictures(?CollectionPicture $commandepictures): static
    {
        $this->commandepictures = $commandepictures;

        return $this;
    }
}
