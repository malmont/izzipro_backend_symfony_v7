<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\CollectionsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionsRepository::class)]
// #[ApiResource]
class Collections
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $budgetCollection = null; // cents (lot G1, 09/11/2026)

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $startDateCollection = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $endDateCollection = null;

    #[ORM\Column]
    private ?bool $del = null;

    #[ORM\Column(length: 255)]
    private ?string $nomCollection = null;

    // #[ORM\Column(length: 255)]
    // private ?string $photoCollection = null;

    #[ORM\ManyToOne(inversedBy: 'collections')]
    private ?User $userCollections = null;

    /**
     * @var Collection<int, Commande>
     */
    #[ORM\OneToMany(mappedBy: 'collections', targetEntity: Commande::class)]
    private Collection $commandes;

    /**
     * @var Collection<int, NoteDeFrais>
     */
    #[ORM\OneToMany(mappedBy: 'Collection', targetEntity: NoteDeFrais::class)]
    private Collection $noteDeFrais;

    /**
     * @var Collection<int, CollectionStatistiques>
     */
    #[ORM\OneToMany(mappedBy: 'collection', targetEntity: CollectionStatistiques::class, cascade: ['remove'])] // lot G4 (09/11/2026)
    private Collection $collectionStatistiques;

    #[ORM\Column(nullable: true)]
    private ?bool $isClosed = false;

    #[ORM\ManyToOne(inversedBy: 'collections')]
    private ?CollectionPicture $photoCollections = null;

    public function __construct()
    {
        $this->commandes = new ArrayCollection();
        $this->noteDeFrais = new ArrayCollection();
        $this->collectionStatistiques = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudgetCollection(): ?int
    {
        return $this->budgetCollection;
    }

    public function setBudgetCollection(int $budgetCollection): static
    {
        $this->budgetCollection = $budgetCollection;

        return $this;
    }

    public function getStartDateCollection(): ?\DateTimeInterface
    {
        return $this->startDateCollection;
    }

    public function setStartDateCollection(\DateTimeInterface $startDateCollection): static
    {
        $this->startDateCollection = $startDateCollection;

        return $this;
    }

    public function getEndDateCollection(): ?\DateTimeInterface
    {
        return $this->endDateCollection;
    }

    public function setEndDateCollection(\DateTimeInterface $endDateCollection): static
    {
        $this->endDateCollection = $endDateCollection;

        return $this;
    }

    public function isDel(): ?bool
    {
        return $this->del;
    }

    public function setDel(bool $del): static
    {
        $this->del = $del;

        return $this;
    }

    public function getNomCollection(): ?string
    {
        return $this->nomCollection;
    }

    public function setNomCollection(string $nomCollection): static
    {
        $this->nomCollection = $nomCollection;

        return $this;
    }

    // public function getPhotoCollection(): ?string
    // {
    //     return $this->photoCollection;
    // }

    // public function setPhotoCollection(string $photoCollection): static
    // {
    //     $this->photoCollection = $photoCollection;

    //     return $this;
    // }

    public function getUserCollections(): ?User
    {
        return $this->userCollections;
    }

    public function setUserCollections(?User $userCollections): static
    {
        $this->userCollections = $userCollections;

        return $this;
    }

    /**
     * @return Collection<int, Commande>
     */
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function addCommande(Commande $commande): static
    {
        if (!$this->commandes->contains($commande)) {
            $this->commandes->add($commande);
            $commande->setCollections($this);
        }

        return $this;
    }

    public function removeCommande(Commande $commande): static
    {
        if ($this->commandes->removeElement($commande)) {
            // set the owning side to null (unless already changed)
            if ($commande->getCollections() === $this) {
                $commande->setCollections(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->nomCollection;
    }

    /**
     * @return Collection<int, NoteDeFrais>
     */
    public function getNoteDeFrais(): Collection
    {
        return $this->noteDeFrais;
    }

    public function addNoteDeFrai(NoteDeFrais $noteDeFrai): static
    {
        if (!$this->noteDeFrais->contains($noteDeFrai)) {
            $this->noteDeFrais->add($noteDeFrai);
            $noteDeFrai->setCollection($this);
        }

        return $this;
    }

    public function removeNoteDeFrai(NoteDeFrais $noteDeFrai): static
    {
        if ($this->noteDeFrais->removeElement($noteDeFrai)) {
            // set the owning side to null (unless already changed)
            if ($noteDeFrai->getCollection() === $this) {
                $noteDeFrai->setCollection(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CollectionStatistiques>
     */
    public function getCollectionStatistiques(): Collection
    {
        return $this->collectionStatistiques;
    }

    public function addCollectionStatistique(CollectionStatistiques $collectionStatistique): static
    {
        if (!$this->collectionStatistiques->contains($collectionStatistique)) {
            $this->collectionStatistiques->add($collectionStatistique);
            $collectionStatistique->setCollection($this);
        }

        return $this;
    }

    public function removeCollectionStatistique(CollectionStatistiques $collectionStatistique): static
    {
        if ($this->collectionStatistiques->removeElement($collectionStatistique)) {
            // set the owning side to null (unless already changed)
            if ($collectionStatistique->getCollection() === $this) {
                $collectionStatistique->setCollection(null);
            }
        }

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

    // Périodes de gestion (lot P1, 10/11/2026, docs/periodes-gestion.md) : collection créée par le calendrier du site
    public const KIND_MANUAL = 'manual';
    public const KIND_PERIOD = 'period';

    #[ORM\Column(length: 16, options: ['default' => 'manual'])]
    private string $kind = self::KIND_MANUAL;

    /** `2026-Q2`, `2026-S1`, `2026-M07` ; unique parmi les périodes ; null pour une collection manuelle */
    #[ORM\Column(name: 'period_key', length: 16, nullable: true)]
    private ?string $periodKey = null;

    /** Réouverture manuelle d'une période fermée par la date : reste ouverte jusqu'à une clôture manuelle */
    #[ORM\Column(name: 'reopened_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reopenedAt = null;

    /** Clôture manuelle (close) ; null : état calculé d'après la date (fin + 15 jours) */
    #[ORM\Column(name: 'closed_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $closedAt = null;

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind === self::KIND_PERIOD ? self::KIND_PERIOD : self::KIND_MANUAL; return $this; }
    public function isPeriod(): bool { return $this->kind === self::KIND_PERIOD; }
    public function getPeriodKey(): ?string { return $this->periodKey; }
    public function setPeriodKey(?string $periodKey): static { $this->periodKey = $periodKey; return $this; }
    public function getReopenedAt(): ?\DateTimeInterface { return $this->reopenedAt; }
    public function setReopenedAt(?\DateTimeInterface $at): static { $this->reopenedAt = $at; return $this; }
    public function getClosedAt(): ?\DateTimeInterface { return $this->closedAt; }
    public function setClosedAt(?\DateTimeInterface $at): static { $this->closedAt = $at; return $this; }

    public function getPhotoCollections(): ?CollectionPicture
    {
        return $this->photoCollections;
    }

    public function setPhotoCollections(?CollectionPicture $photoCollections): static
    {
        $this->photoCollections = $photoCollections;

        return $this;
    }
    
}
