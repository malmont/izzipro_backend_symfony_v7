<?php

namespace App\Entity;

use App\Repository\PosManufacturerCouponRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Coupon de fabricant accepté en caisse (F17, 01/11/2026) : bon de réduction émis par une marque et remboursé au commerçant par
 * le fabricant ou une chambre de compensation. Traité comme un MOYEN DE PAIEMENT (part type 6, aucune ligne dans payments,
 * cumul dans order.manufacturer_coupon_applied) : les taxes restent calculées sur le prix plein (LTA art. 181(2), TVQ).
 * Statuts : redeemed (accepté, à réclamer), claimed (réclamé par lot), voided (vente retournée ou annulée avant réclamation),
 * returned_after_claim (retournée après réclamation : à régulariser avec le fabricant).
 */
#[ORM\Entity(repositoryClass: PosManufacturerCouponRepository::class)]
#[ORM\Table(name: 'pos_manufacturer_coupon')]
#[ORM\Index(name: 'idx_pos_mfr_coupon_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_pos_mfr_coupon_claims', columns: ['manufacturer', 'status', 'redeemed_at'])]
#[ORM\Index(name: 'idx_pos_mfr_coupon_serial', columns: ['company_prefix', 'offer_code', 'serial'])]
class PosManufacturerCoupon
{
    public const REDEEMED = 'redeemed';
    public const CLAIMED = 'claimed';
    public const VOIDED = 'voided';
    public const RETURNED_AFTER_CLAIM = 'returned_after_claim';
    public const STATUSES = [self::REDEEMED, self::CLAIMED, self::VOIDED, self::RETURNED_AFTER_CLAIM];
    public const SOURCE_SCAN = 'scan';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $orderId = 0;

    #[ORM\Column(nullable: true)]
    private ?int $orderItemId = null;

    #[ORM\Column(nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(nullable: true)]
    private ?int $caisseId = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 100)]
    private string $manufacturer = '';

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $companyPrefix = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $offerCode = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $serial = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $format = null;

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(length: 8, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(length: 24, options: ['default' => self::REDEEMED])]
    private string $status = self::REDEEMED;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $redeemedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $claimBatch = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $voidedCaisseId = null;

    #[ORM\Column(nullable: true)]
    private ?int $returnOrderId = null;

    public function __construct()
    {
        $this->redeemedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrderId(): int { return $this->orderId; }
    public function setOrderId(int $id): static { $this->orderId = $id; return $this; }
    public function getOrderItemId(): ?int { return $this->orderItemId; }
    public function setOrderItemId(?int $id): static { $this->orderItemId = $id; return $this; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function setCashRegisterId(?int $id): static { $this->cashRegisterId = $id; return $this; }
    public function getCaisseId(): ?int { return $this->caisseId; }
    public function setCaisseId(?int $id): static { $this->caisseId = $id; return $this; }
    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $id): static { $this->userId = $id; return $this; }
    public function getManufacturer(): string { return $this->manufacturer; }
    public function setManufacturer(string $name): static { $this->manufacturer = mb_substr(trim($name), 0, 100); return $this; }
    public function getCompanyPrefix(): ?string { return $this->companyPrefix; }
    public function setCompanyPrefix(?string $prefix): static { $this->companyPrefix = $prefix !== null && $prefix !== '' ? substr($prefix, 0, 12) : null; return $this; }
    public function getOfferCode(): ?string { return $this->offerCode; }
    public function setOfferCode(?string $code): static { $this->offerCode = $code !== null && $code !== '' ? substr($code, 0, 12) : null; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): static { $this->serial = $serial !== null && $serial !== '' ? substr($serial, 0, 32) : null; return $this; }
    public function getBarcode(): ?string { return $this->barcode; }
    public function setBarcode(?string $barcode): static { $this->barcode = $barcode !== null && $barcode !== '' ? substr($barcode, 0, 80) : null; return $this; }
    public function getFormat(): ?string { return $this->format; }
    public function setFormat(?string $format): static { $this->format = $format; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): static { $this->amount = $amount; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source === self::SOURCE_SCAN ? self::SOURCE_SCAN : self::SOURCE_MANUAL; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = in_array($status, self::STATUSES, true) ? $status : $this->status; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $at): static { $this->expiresAt = $at; return $this; }
    public function getRedeemedAt(): \DateTimeImmutable { return $this->redeemedAt; }
    public function setRedeemedAt(\DateTimeImmutable $at): static { $this->redeemedAt = $at; return $this; }
    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function getClaimBatch(): ?string { return $this->claimBatch; }
    public function markClaimed(string $batch): static { $this->status = self::CLAIMED; $this->claimedAt = new \DateTimeImmutable(); $this->claimBatch = $batch; return $this; }
    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }
    public function getVoidedCaisseId(): ?int { return $this->voidedCaisseId; }
    public function getReturnOrderId(): ?int { return $this->returnOrderId; }
    /** Vente retournée ou annulée : annulé si pas encore réclamé, sinon « retourné après réclamation » (à régulariser) */
    public function markReturned(?int $caisseId, ?int $returnOrderId): static
    {
        if ($this->status === self::VOIDED || $this->status === self::RETURNED_AFTER_CLAIM) {
            return $this;
        }
        $this->status = $this->status === self::CLAIMED ? self::RETURNED_AFTER_CLAIM : self::VOIDED;
        $this->voidedAt = new \DateTimeImmutable();
        $this->voidedCaisseId = $caisseId;
        $this->returnOrderId = $returnOrderId;

        return $this;
    }
    public function isActive(): bool { return $this->status === self::REDEEMED || $this->status === self::CLAIMED; }
}
