<?php

namespace App\Entity;

use App\Repository\CashRegisterReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapport Z (19/10/2026) : créé à la fermeture d'une session de caisse, numéroté sans trou PAR POSTE, figé. Aucun
 * accesseur d'écriture : tout est posé par le constructeur, et ni route ni écran ne le modifie ou le supprime.
 * `data` est l'instantané complet (rapport X au moment de la fermeture, comptage, magasin) ; les chiffres clés sont
 * aussi en colonnes pour les listes. Schéma : migrations/Version20261019130000.php.
 */
#[ORM\Entity(repositoryClass: CashRegisterReportRepository::class)]
#[ORM\Table(name: 'cash_register_report')]
#[ORM\UniqueConstraint(name: 'uniq_cash_register_report_number', columns: ['cash_register_id', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_cash_register_report_session', columns: ['caisse_id'])]
class CashRegisterReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CashRegister::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CashRegister $cashRegister;

    #[ORM\ManyToOne(targetEntity: Caisse::class)]
    #[ORM\JoinColumn(name: 'caisse_id', nullable: false)]
    private Caisse $session;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $closedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy;

    #[ORM\Column(nullable: true)]
    private ?int $countedCash;

    #[ORM\Column]
    private int $expectedCash;

    #[ORM\Column(nullable: true)]
    private ?int $discrepancy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $discrepancyReason;

    #[ORM\Column(nullable: true)]
    private ?int $keptFund;

    #[ORM\Column(nullable: true)]
    private ?int $bankDeposit;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(CashRegister $cashRegister, Caisse $session, int $number, \DateTimeImmutable $closedAt, ?User $closedBy,
        ?int $countedCash, int $expectedCash, ?int $discrepancy, ?string $discrepancyReason, ?int $keptFund, ?int $bankDeposit, array $data)
    {
        $this->cashRegister = $cashRegister;
        $this->session = $session;
        $this->number = $number;
        $this->closedAt = $closedAt;
        $this->closedBy = $closedBy;
        $this->countedCash = $countedCash;
        $this->expectedCash = $expectedCash;
        $this->discrepancy = $discrepancy;
        $this->discrepancyReason = $discrepancyReason;
        $this->keptFund = $keptFund;
        $this->bankDeposit = $bankDeposit;
        $this->data = $data;
    }

    public function getId(): ?int { return $this->id; }
    public function getCashRegister(): CashRegister { return $this->cashRegister; }
    public function getSession(): Caisse { return $this->session; }
    public function getNumber(): int { return $this->number; }
    public function getClosedAt(): \DateTimeImmutable { return $this->closedAt; }
    public function getClosedBy(): ?User { return $this->closedBy; }
    public function getCountedCash(): ?int { return $this->countedCash; }
    public function getExpectedCash(): int { return $this->expectedCash; }
    public function getDiscrepancy(): ?int { return $this->discrepancy; }
    public function getDiscrepancyReason(): ?string { return $this->discrepancyReason; }
    public function getKeptFund(): ?int { return $this->keptFund; }
    public function getBankDeposit(): ?int { return $this->bankDeposit; }
    /** @return array<string, mixed> */
    public function getData(): array { return $this->data; }

    public function __toString(): string
    {
        return sprintf('Z n° %d — %s', $this->number, $this->cashRegister->getName());
    }
}
