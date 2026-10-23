<?php

namespace App\Entity;

use App\Repository\PosApprovalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Validation d'un responsable (chantier C3, 20/10/2026) : jeton court (2 minutes), à usage unique, lié à l'action, au
 * contexte, au terminal et au caissier qui l'a demandé. Seule l'empreinte SHA-256 du jeton est gardée. La consommation
 * se fait dans la transaction SQL de l'action protégée (PosApprovalService::requireFor).
 */
#[ORM\Entity(repositoryClass: PosApprovalRepository::class)]
#[ORM\Table(name: 'pos_approval')]
#[ORM\UniqueConstraint(name: 'uniq_pos_approval_token', columns: ['token_hash'])]
class PosApproval
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 30)]
    private string $action;

    #[ORM\Column]
    private int $managerId;

    #[ORM\Column]
    private int $requestedById;

    #[ORM\Column]
    private int $terminalId;

    #[ORM\Column(type: Types::TEXT)]
    private string $context;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Jeton groupé (P2-a, 26/10/2026) : toutes les actions couvertes [{action, context}] ; null = la seule action ci-dessus */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $actions = null;

    public function __construct(string $tokenHash, string $action, int $managerId, int $requestedById, int $terminalId, array $context, \DateTimeImmutable $expiresAt)
    {
        $this->tokenHash = $tokenHash;
        $this->action = $action;
        $this->managerId = $managerId;
        $this->requestedById = $requestedById;
        $this->terminalId = $terminalId;
        $this->context = json_encode($context, JSON_THROW_ON_ERROR);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAction(): string { return $this->action; }
    public function getManagerId(): int { return $this->managerId; }
    public function getRequestedById(): int { return $this->requestedById; }
    public function getTerminalId(): int { return $this->terminalId; }
    /** @return array<string, int> */
    public function getContext(): array { return json_decode($this->context, true) ?? []; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    /** @return list<array{action: string, context: array<string, int|string>}> actions couvertes (une seule sans jeton groupé) */
    public function getActions(): array { return $this->actions ?? [['action' => $this->action, 'context' => $this->getContext()]]; }
    public function setActions(?array $actions): static { $this->actions = $actions; return $this; }
}
