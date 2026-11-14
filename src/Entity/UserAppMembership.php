<?php

namespace App\Entity;

use App\Repository\UserAppMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appartenance d'un compte client à une famille d'applications (lot APPS-MEMBRES M1, 14/11/2026, `docs/apps-membres.md`) : une
 * ligne par compte et par famille (`App\Security\AppFamilies`). Écrite seulement par `AppMembershipService` (une insertion, jamais
 * de mise à jour), lue par ce dépôt ; la ligne part avec le compte (clé étrangère en cascade) et à l'effacement d'un compte.
 */
#[ORM\Entity(repositoryClass: UserAppMembershipRepository::class)]
#[ORM\Table(name: 'user_app_membership')]
#[ORM\UniqueConstraint(name: 'uniq_user_app_membership', columns: ['user_id', 'app'])]
#[ORM\Index(name: 'idx_user_app_membership_app', columns: ['app'])]
class UserAppMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId = 0;

    /** Famille (`AppFamilies::ALL`) */
    #[ORM\Column(length: 20)]
    private string $app = '';

    /** D'où vient l'appartenance (`AppFamilies::SOURCES`) */
    #[ORM\Column(length: 20)]
    private string $source = '';

    /** Date d'entrée dans la famille (celle de la commande pour un invité, de la plus ancienne trace pour une reprise) */
    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    /** Version des conditions acceptées ; null : aucune acceptation enregistrée (on n'invente pas un consentement) */
    #[ORM\Column(name: 'terms_version', length: 40, nullable: true)]
    private ?string $termsVersion = null;

    #[ORM\Column(name: 'terms_accepted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getApp(): string
    {
        return $this->app;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getTermsVersion(): ?string
    {
        return $this->termsVersion;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
