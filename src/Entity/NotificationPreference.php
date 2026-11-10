<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Type de notification coupé ou rétabli par un utilisateur (lot N1, 11/11/2026, `docs/notifications-gestion.md`) ; sans ligne, le type
 * est actif. Écrit et lu en SQL par `NotificationLogRepository`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_user_kind', columns: ['user_id', 'kind'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId = 0;

    #[ORM\Column(length: 30)]
    private string $kind = '';

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
}
