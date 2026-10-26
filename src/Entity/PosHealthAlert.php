<?php

namespace App\Entity;

use App\Repository\PosHealthAlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * État d'une alerte de surveillance de la caisse (M1), une ligne par contrôle : empreinte de la situation, dernier envoi (pas de nouvel
 * envoi de la même situation avant 6 heures), résolution. Aucune donnée de carte.
 */
#[ORM\Entity(repositoryClass: PosHealthAlertRepository::class)]
#[ORM\Table(name: 'pos_health_alert')]
#[ORM\UniqueConstraint(name: 'uniq_pos_health_alert_code', columns: ['code'])]
class PosHealthAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 10)]
    private string $level;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(string $code, string $level, string $fingerprint)
    {
        $this->code = $code;
        $this->level = $level;
        $this->fingerprint = $fingerprint;
        $this->firstSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLevel(): string { return $this->level; }
    public function setLevel(string $level): static { $this->level = $level; return $this; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function setFingerprint(string $fingerprint): static { $this->fingerprint = $fingerprint; return $this; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function setFirstSeenAt(\DateTimeImmutable $at): static { $this->firstSeenAt = $at; return $this; }
    public function getLastSentAt(): ?\DateTimeImmutable { return $this->lastSentAt; }
    public function setLastSentAt(?\DateTimeImmutable $at): static { $this->lastSentAt = $at; return $this; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $at): static { $this->resolvedAt = $at; return $this; }
}
