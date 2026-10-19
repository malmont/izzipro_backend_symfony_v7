<?php

namespace App\Entity;

use App\Repository\PosAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'audit de la caisse (chantier C2, 19/10/2026) : une ligne par événement, séquence continue par site, chaînée
 * par empreinte (hash = SHA-256(prevHash + payload)). Inaltérable : entité en lecture seule pour Doctrine, écrite
 * uniquement par PosAuditService (DBAL), et un déclencheur PostgreSQL refuse toute modification, suppression ou vidage
 * de la table. `payload` est le contenu canonique haché ; les autres colonnes en sont extraites pour les filtres.
 */
#[ORM\Entity(repositoryClass: PosAuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'pos_audit_log')]
class PosAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $sequence;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(nullable: true)]
    private ?int $sessionId = null;

    #[ORM\Column(nullable: true)]
    private ?int $terminalId = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $prevHash = null;

    #[ORM\Column(length: 64)]
    private string $hash;

    public function getId(): ?int { return $this->id; }
    public function getSequence(): int { return $this->sequence; }
    public function getType(): string { return $this->type; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getUserId(): ?int { return $this->userId; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function getSessionId(): ?int { return $this->sessionId; }
    public function getTerminalId(): ?int { return $this->terminalId; }
    public function getPayload(): string { return $this->payload; }
    public function getPrevHash(): ?string { return $this->prevHash; }
    public function getHash(): string { return $this->hash; }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return json_decode($this->payload, true) ?? [];
    }

    public function __toString(): string
    {
        return sprintf('#%d %s', $this->sequence, $this->type);
    }
}
