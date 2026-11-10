<?php

namespace App\Entity;

use App\Repository\PushDeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appareil inscrit aux notifications sur téléphone (lot N1, 11/11/2026, `docs/notifications-gestion.md`) : jeton du fournisseur d'envoi
 * (Firebase), rattaché au site où il a été inscrit (base du site) et à son utilisateur ; `platform` = application (« admin » pour la
 * gestion ; « mobile », « pos » plus tard). Désactivé à la déconnexion ou quand le fournisseur refuse le jeton. Écrit et lu en SQL par
 * son dépôt, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity(repositoryClass: PushDeviceRepository::class)]
#[ORM\Table(name: 'push_device')]
#[ORM\UniqueConstraint(name: 'uniq_push_device_token', columns: ['token'])]
#[ORM\Index(name: 'idx_push_device_user', columns: ['user_id'])]
class PushDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId = 0;

    #[ORM\Column(length: 20)]
    private string $platform = 'admin';

    #[ORM\Column(length: 512)]
    private string $token = '';

    #[ORM\Column(name: 'device_name', length: 120, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(name: 'app_version', length: 40, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'disabled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column(name: 'disabled_reason', length: 40, nullable: true)]
    private ?string $disabledReason = null;

    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getPlatform(): string { return $this->platform; }
    public function getToken(): string { return $this->token; }
}
