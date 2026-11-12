<?php

namespace App\Entity;

use App\Repository\OrderTaxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderTaxRepository::class)]
class OrderTax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orderTaxes')]
    private ?Order $orderTax = null;

    #[ORM\ManyToOne(inversedBy: 'orderTaxes')]
    private ?Tax $tax = null;

    #[ORM\Column]
    private ?float $amount = null;

    /** Assiette HT de cette taxe sur la commande, en cents (F18 partie 2a) ; null sur les commandes antérieures */
    #[ORM\Column(nullable: true)]
    private ?int $base = null;

    /*
     * Taxe gelée à la vente (PAYS-1, 12/11/2026) : écrite une fois par freeze(), jamais modifiée ensuite (déclencheur PostgreSQL
     * order_tax_frozen_guard). Ticket, X, exports, archive et retours lisent ces valeurs ; repli sur la table tax seulement si
     * taxSource est null (rateSource « live »).
     */
    #[ORM\Column(name: 'tax_label', length: 120, nullable: true)]
    private ?string $taxLabel = null;

    /** Taux en millionièmes (TPS 5 % = 50000 ; TVQ 9,975 % = 99750) ; null = inconnu (abonnement) */
    #[ORM\Column(name: 'tax_rate_ppm', nullable: true)]
    private ?int $taxRatePpm = null;

    /** gst | qst | hst | pst | vat | other */
    #[ORM\Column(name: 'tax_code', length: 20, nullable: true)]
    private ?string $taxCode = null;

    /** Subdivision de l'adresse de calcul (CA-QC, US-NY…) */
    #[ORM\Column(name: 'tax_jurisdiction', length: 20, nullable: true)]
    private ?string $taxJurisdiction = null;

    #[ORM\Column(name: 'tax_category', length: 16, nullable: true)]
    private ?string $taxCategory = null;

    #[ORM\Column(name: 'prices_include_tax', nullable: true)]
    private ?bool $pricesIncludeTax = null;

    /** table | stripe | offline | return | subscription | backfill ; null = jamais gelée */
    #[ORM\Column(name: 'tax_source', length: 12, nullable: true)]
    private ?string $taxSource = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderTax(): ?Order
    {
        return $this->orderTax;
    }

    public function setOrderTax(?Order $orderTax): static
    {
        $this->orderTax = $orderTax;

        return $this;
    }

    public function getTax(): ?Tax
    {
        return $this->tax;
    }

    public function setTax(?Tax $tax): static
    {
        $this->tax = $tax;

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

    public function getBase(): ?int { return $this->base; }
    public function setBase(?int $base): static { $this->base = $base; return $this; }

    public const SOURCES = ['table', 'stripe', 'offline', 'return', 'subscription', 'backfill'];

    /**
     * Gèle la taxe telle qu'appliquée à la vente ; une seule fois.
     *
     * @param array{label: ?string, ratePpm: ?int, code: ?string, jurisdiction: ?string, category: ?string, pricesIncludeTax: ?bool, source: string} $f
     */
    public function freeze(array $f): static
    {
        if ($this->taxSource !== null) {
            throw new \LogicException('ORDER_TAX_FROZEN');
        }
        if (!in_array($f['source'], self::SOURCES, true)) {
            throw new \InvalidArgumentException('source de taxe inconnue : ' . $f['source']);
        }
        $this->taxLabel = $f['label'] !== null ? mb_substr($f['label'], 0, 120) : null;
        $this->taxRatePpm = $f['ratePpm'];
        $this->taxCode = $f['code'];
        $this->taxJurisdiction = $f['jurisdiction'] !== null ? mb_substr($f['jurisdiction'], 0, 20) : null;
        $this->taxCategory = $f['category'];
        $this->pricesIncludeTax = $f['pricesIncludeTax'];
        $this->taxSource = $f['source'];

        return $this;
    }

    public function isFrozen(): bool { return $this->taxSource !== null; }
    public function getTaxLabel(): ?string { return $this->taxLabel; }
    public function getTaxRatePpm(): ?int { return $this->taxRatePpm; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function getTaxJurisdiction(): ?string { return $this->taxJurisdiction; }
    public function getTaxCategory(): ?string { return $this->taxCategory; }
    public function getPricesIncludeTax(): ?bool { return $this->pricesIncludeTax; }
    public function getTaxSource(): ?string { return $this->taxSource; }

    /**
     * Taxe à afficher : valeurs gelées, sinon la ligne tax actuelle (rateSource « live », signalé).
     *
     * @return array{label: string, rate: ?float, ratePpm: ?int, code: ?string, jurisdiction: ?string, category: ?string, rateSource: string}
     */
    public function snapshot(): array
    {
        if ($this->taxSource !== null) {
            return ['label' => $this->taxLabel ?? 'Taxe', 'rate' => $this->taxRatePpm !== null ? $this->taxRatePpm / 1000000 : null, 'ratePpm' => $this->taxRatePpm,
                'code' => $this->taxCode, 'jurisdiction' => $this->taxJurisdiction, 'category' => $this->taxCategory,
                'rateSource' => $this->taxSource === 'backfill' ? 'backfill' : 'sale'];
        }
        $tax = $this->tax;

        return ['label' => $tax?->getName() ?? 'Taxe', 'rate' => $tax ? (float) $tax->getRate() : null, 'ratePpm' => $tax ? \App\Services\OrderService\TaxCode::ppm((float) $tax->getRate()) : null,
            'code' => $tax ? \App\Services\OrderService\TaxCode::of($tax) : null, 'jurisdiction' => null, 'category' => $tax?->getCategory(), 'rateSource' => 'live'];
    }
}
