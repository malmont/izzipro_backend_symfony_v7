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
#[ORM\Index(name: 'idx_notification_log_email', columns: ['recipient', 'store_day'], options: ['where' => "(channel = 'email')"])]
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

    /** Lot NOTIF-1 : `push` (téléphone, tout l'existant) ou `email` (courriel urgent ou de suivi, parti, retenu ou gardé) */
    #[ORM\Column(length: 8, options: ['default' => 'push'])]
    private string $channel = 'push';

    /** Adresse du destinataire d'un courriel (en minuscules) ; `user_id` vaut 0 quand elle n'est celle d'aucun compte */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $recipient = null;

    /** Sujet d'un courriel de suivi (`NotificationCatalog::TOPICS`) */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $topic = null;

    /** Quand un courriel retenu est sorti dans un résumé, ou a été gardé sans envoi */
    #[ORM\Column(name: 'released_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function getId(): ?int { return $this->id; }
}
