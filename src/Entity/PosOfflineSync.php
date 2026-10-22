<?php

namespace App\Entity;

use App\Repository\PosOfflineSyncRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Lot de synchronisation hors ligne d'un terminal (C5 partie 2, 25/10/2026) : bilan et dernier hash du chaînage */
#[ORM\Entity(repositoryClass: PosOfflineSyncRepository::class)]
#[ORM\Table(name: 'pos_offline_sync')]
#[ORM\Index(name: 'idx_pos_offline_sync_terminal', columns: ['terminal_id', 'created_at'])]
class PosOfflineSync
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $terminalId = 0;

    #[ORM\Column]
    private int $syncedById = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $count = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $accepted = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $duplicates = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $rejected = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $driftTotal = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $chainBroken = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastHash = null;

    #[ORM\Column(nullable: true)]
    private ?int $clockOffsetSeconds = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $packVersion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $terminalId, int $syncedById)
    {
        $this->terminalId = $terminalId;
        $this->syncedById = $syncedById;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTerminalId(): int { return $this->terminalId; }
    public function getSyncedById(): int { return $this->syncedById; }
    public function getCount(): int { return $this->count; }
    public function getAccepted(): int { return $this->accepted; }
    public function getDuplicates(): int { return $this->duplicates; }
    public function getRejected(): int { return $this->rejected; }
    public function getDriftTotal(): int { return $this->driftTotal; }
    public function isChainBroken(): bool { return $this->chainBroken; }
    public function getLastHash(): ?string { return $this->lastHash; }
    public function getClockOffsetSeconds(): ?int { return $this->clockOffsetSeconds; }
    public function getPackVersion(): ?string { return $this->packVersion; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function finish(int $count, int $accepted, int $duplicates, int $rejected, int $driftTotal, bool $chainBroken, ?string $lastHash, ?int $clockOffset, ?string $packVersion): static
    {
        $this->count = $count; $this->accepted = $accepted; $this->duplicates = $duplicates; $this->rejected = $rejected; $this->driftTotal = $driftTotal;
        $this->chainBroken = $chainBroken; $this->lastHash = $lastHash !== null ? substr($lastHash, 0, 64) : null; $this->clockOffsetSeconds = $clockOffset; $this->packVersion = $packVersion !== null ? mb_substr($packVersion, 0, 64) : null;

        return $this;
    }
}
