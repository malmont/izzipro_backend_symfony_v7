<?php

namespace App\Gestion\Entity;

use App\Gestion\Repository\GestionTileDismissalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vignette d'accueil écartée par un gérant (vignettes d'accueil, première version, 11/11/2026, `docs/vignettes-gestion.md`) : par
 * utilisateur et par vignette ; `until` null = jamais de retour (premiers pas), sinon retour à cette date ; `signature` = liste des
 * articles concernés au moment d'écarter (une alerte revient plus tôt si elle change). Écrite et lue en SQL par son dépôt, jamais en
 * cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity(repositoryClass: GestionTileDismissalRepository::class)]
#[ORM\Table(name: 'gestion_tile_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_gestion_tile_dismissal_user_tile', columns: ['user_id', 'tile_id'])]
class GestionTileDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId = 0;

    #[ORM\Column(name: 'tile_id', length: 40)]
    private string $tileId = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(name: 'dismissed_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dismissedAt = null;

    #[ORM\Column(name: 'until', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $until = null;

    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getTileId(): string { return $this->tileId; }
    public function getSignature(): ?string { return $this->signature; }
    public function getDismissedAt(): ?\DateTimeImmutable { return $this->dismissedAt; }
    public function getUntil(): ?\DateTimeImmutable { return $this->until; }
}
