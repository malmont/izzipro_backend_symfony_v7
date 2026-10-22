<?php

namespace App\Entity;

use App\Repository\PosIdempotencyKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Clé d'idempotence d'une écriture de caisse (chantier C5, 22/10/2026) : en-tête Idempotency-Key (UUID du POS). Une
 * ligne par clé et par site : utilisateur, terminal, route, empreinte du corps, état (in_progress → committed → done),
 * objet créé et réponse gardée 7 jours. Entité en lecture seule pour Doctrine : écrite par PosIdempotencyService (DBAL),
 * le passage à « committed » se faisant dans la transaction SQL de l'écriture.
 */
#[ORM\Entity(repositoryClass: PosIdempotencyKeyRepository::class, readOnly: true)]
#[ORM\Table(name: 'pos_idempotency_key')]
#[ORM\UniqueConstraint(name: 'uniq_pos_idempotency_key', columns: ['idem_key'])]
#[ORM\Index(name: 'idx_pos_idempotency_created', columns: ['created_at'])]
class PosIdempotencyKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'idem_key', length: 36)]
    private string $key;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(nullable: true)]
    private ?int $terminalId = null;

    #[ORM\Column(length: 80)]
    private string $route;

    #[ORM\Column(length: 64)]
    private string $bodyHash;

    #[ORM\Column(length: 12)]
    private string $status;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    #[ORM\Column(nullable: true)]
    private ?int $responseStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getKey(): string { return $this->key; }
    public function getStatus(): string { return $this->status; }
    public function getSubjectType(): ?string { return $this->subjectType; }
    public function getSubjectId(): ?int { return $this->subjectId; }
}
