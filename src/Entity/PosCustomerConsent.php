<?php

namespace App\Entity;

use App\Repository\PosCustomerConsentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Consentement d'un client de caisse (F8, Loi 25 / RGPD) : finalité, accordé ou retiré, date, caissier, version du texte.
 * Historique conservé ; l'état effectif est la dernière ligne par finalité.
 */
#[ORM\Entity(repositoryClass: PosCustomerConsentRepository::class)]
#[ORM\Table(name: 'pos_customer_consent')]
#[ORM\Index(name: 'idx_pos_customer_consent_customer', columns: ['customer_id', 'purpose'])]
class PosCustomerConsent
{
    public const LOYALTY = 'loyalty';
    public const RECEIPTS = 'receipts';
    public const MARKETING = 'marketing';
    public const ACCOUNT_LINK = 'account_link';
    public const PURPOSES = [self::LOYALTY, self::RECEIPTS, self::MARKETING, self::ACCOUNT_LINK];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PosCustomer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private PosCustomer $customer;

    #[ORM\Column(length: 20)]
    private string $purpose;

    #[ORM\Column]
    private bool $granted;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $at;

    #[ORM\Column(nullable: true)]
    private ?int $byUserId = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $textVersion = null;

    #[ORM\Column(length: 12, options: ['default' => 'pos'])]
    private string $source = 'pos';

    public function __construct(PosCustomer $customer, string $purpose, bool $granted, ?int $byUserId, ?string $textVersion)
    {
        $this->customer = $customer;
        $this->purpose = $purpose;
        $this->granted = $granted;
        $this->byUserId = $byUserId;
        $this->textVersion = $textVersion;
        $this->at = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): PosCustomer { return $this->customer; }
    public function getPurpose(): string { return $this->purpose; }
    public function isGranted(): bool { return $this->granted; }
    public function getAt(): \DateTimeImmutable { return $this->at; }
    public function getByUserId(): ?int { return $this->byUserId; }
    public function getTextVersion(): ?string { return $this->textVersion; }
    public function getSource(): string { return $this->source; }
}
