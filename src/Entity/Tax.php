<?php

namespace App\Entity;

use App\Repository\TaxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxRepository::class)]
class Tax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $rate = null;

    #[ORM\Column(length: 255)]
    private ?string $type = null;

    /** Région : « Toutes », ou codes séparés par des virgules (QC ; ON,NB,NL,PE,NS) ; les noms de provinces canadiennes sont acceptés */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $province = null;

    /** Pays ISO à 2 lettres (vide = tous) ; taxes par région, 09/10/2026 */
    #[ORM\Column(length: 2, nullable: true, options: ['default' => 'CA'])]
    private ?string $country = 'CA';

    /** Catégorie fiscale visée (F18 partie 2a) : null = tous les produits non exonérés ; sinon les produits de cette catégorie seulement (TaxCategory) */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $category = null;

    /** Code stable de la taxe (PAYS-1, 12/11/2026) : gst | qst | hst | pst | vat | other ; null = déduit du libellé (TaxCode::fromLabel) */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $code = null;

    /** Archivée (PAYS-1) : n'est plus appliquée ; reste lisible sur les commandes passées */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /** Validité (PAYS-1), en jours du magasin : du (inclus) au (exclu) ; null = sans borne. Un nouveau taux = une nouvelle taxe datée */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    /**
     * @var Collection<int, OrderTax>
     */
    #[ORM\OneToMany(mappedBy: 'tax', targetEntity: OrderTax::class)]
    private Collection $orderTaxes;

    public function __construct()
    {
        $this->orderTaxes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getRate(): ?float
    {
        return $this->rate;
    }

    public function setRate(float $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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
            $orderTax->setTax($this);
        }

        return $this;
    }

    public function removeOrderTax(OrderTax $orderTax): static
    {
        if ($this->orderTaxes->removeElement($orderTax)) {
            // set the owning side to null (unless already changed)
            if ($orderTax->getTax() === $this) {
                $orderTax->setTax(null);
            }
        }

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country !== null ? strtoupper(trim($country)) : null;

        return $this;
    }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): static { $this->category = \App\Services\OrderService\TaxCategory::isValid($category) ? $category : null; return $this; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = $code !== null && trim($code) !== '' ? strtolower(trim($code)) : null; return $this; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function setArchivedAt(?\DateTimeImmutable $at): static { $this->archivedAt = $at; return $this; }
    public function isArchived(): bool { return $this->archivedAt !== null; }
    public function setArchived(bool $archived): static { $this->archivedAt = $archived ? ($this->archivedAt ?? new \DateTimeImmutable()) : null; return $this; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeImmutable $d): static { $this->validFrom = $d; return $this; }
    public function getValidTo(): ?\DateTimeImmutable { return $this->validTo; }
    public function setValidTo(?\DateTimeImmutable $d): static { $this->validTo = $d; return $this; }

    /** Appliquée le jour $day (Y-m-d, jour du magasin) : non archivée, validFrom ≤ jour < validTo */
    public function isActiveOn(string $day): bool
    {
        return $this->archivedAt === null
            && ($this->validFrom === null || $this->validFrom->format('Y-m-d') <= $day)
            && ($this->validTo === null || $day < $this->validTo->format('Y-m-d'));
    }
}
