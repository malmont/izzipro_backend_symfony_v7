<?php

namespace App\Entity;

use App\Repository\NotificationLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des notifications sur téléphone (lot N1, 11/11/2026, `docs/notifications-gestion.md`) : une ligne par notification et par
 * utilisateur, avec le jour du magasin (limite d'une par jour), le statut (`sent`, `not_configured`, `failed`, `no_device`) et le sujet
 * (variantes, document) qui évite de répéter la même alerte. Écrit et lu en SQL par son dépôt, jamais en cache applicatif.
 */
#[ORM\Entity(repositoryClass: NotificationLogRepository::class)]
#[ORM\Table(name: 'notification_log')]
#[ORM\Index(name: 'idx_notification_log_user_day', columns: ['user_id', 'store_day'])]
class NotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId = 0;

    #[ORM\Column(length: 30)]
    private string $kind = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(type: 'json')]
    private array $action = [];

    #[ORM\Column(type: 'json')]
    private array $subject = [];

    #[ORM\Column(length: 20)]
    private string $status = '';

    #[ORM\Column]
    private int $devices = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'store_day', type: 'date_immutable')]
    private ?\DateTimeImmutable $storeDay = null;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $sentAt = null;

    public function getId(): ?int { return $this->id; }
}
