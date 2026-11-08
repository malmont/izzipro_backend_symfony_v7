<?php

namespace App\MemoiresVivantes\Entity;

use App\MemoiresVivantes\Repository\ChapterInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Invitation par e-mail à répondre aux questions d'un chapitre (08/11/2026). Le backend envoie le lien signé, puis en
 * renvoie un nouveau à l'expiration du précédent tant que la personne n'a pas terminé, RENEWALS_MAX fois au plus.
 * Sans contributeur : lien du chapitre ; avec : lien personnel du contributeur. Le lien n'est pas stocké : il se
 * recalcule (Services/ShareLinkSigner) à partir du chapitre, du contributeur et de la date d'expiration, qui est aussi
 * celle de la signature. Hors cache applicatif (Cache/CacheTags, NO_CACHE_READ).
 */
#[ORM\Entity(repositoryClass: ChapterInvitationRepository::class)]
#[ORM\Table(name: 'mv_chapter_invitation')]
#[ORM\Index(name: 'idx_mv_chapter_invitation_due', columns: ['status', 'expires_at'])]
class ChapterInvitation
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    /** Statuts qui acceptent encore un envoi (renvoi, arrêt, fin) */
    public const OPEN_STATUSES = [self::STATUS_ACTIVE, self::STATUS_EXHAUSTED];
    public const RENEWALS_MAX = 3;
    /** Durée d'un lien : 7 jours, comme le défaut de GET /chapters/{id}/share-link */
    public const LINK_DURATION = 604800;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Chapter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Chapter $chapter = null;

    #[ORM\ManyToOne(targetEntity: Contributor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Contributor $contributor = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSentAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSentAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'smallint')]
    private int $renewalsUsed = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $unsubscribeToken;

    /** Origine (https://hôte) du backend vue à la création : le lien « ne plus recevoir » des renouvellements, envoyés hors requête, la reprend */
    #[ORM\Column(length: 255)]
    private string $backendOrigin = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->firstSentAt = $now;
        $this->lastSentAt = $now;
        $this->expiresAt = $now;
        $this->unsubscribeToken = bin2hex(random_bytes(32));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getChapter(): ?Chapter
    {
        return $this->chapter;
    }

    public function setChapter(Chapter $chapter): self
    {
        $this->chapter = $chapter;

        return $this;
    }

    public function getContributor(): ?Contributor
    {
        return $this->contributor;
    }

    public function setContributor(?Contributor $contributor): self
    {
        $this->contributor = $contributor;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function getFirstSentAt(): \DateTimeImmutable
    {
        return $this->firstSentAt;
    }

    public function setFirstSentAt(\DateTimeImmutable $at): self
    {
        $this->firstSentAt = $at;

        return $this;
    }

    public function getLastSentAt(): \DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function setLastSentAt(\DateTimeImmutable $at): self
    {
        $this->lastSentAt = $at;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $at): self
    {
        $this->expiresAt = $at;

        return $this;
    }

    public function getRenewalsUsed(): int
    {
        return $this->renewalsUsed;
    }

    public function setRenewalsUsed(int $count): self
    {
        $this->renewalsUsed = $count;

        return $this;
    }

    public function renewalsLeft(): int
    {
        return max(0, self::RENEWALS_MAX - $this->renewalsUsed);
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $at): self
    {
        $this->completedAt = $at;

        return $this;
    }

    public function getUnsubscribeToken(): string
    {
        return $this->unsubscribeToken;
    }

    public function getBackendOrigin(): string
    {
        return $this->backendOrigin;
    }

    public function setBackendOrigin(string $origin): self
    {
        $this->backendOrigin = rtrim($origin, '/');

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
