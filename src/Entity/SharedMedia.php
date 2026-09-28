<?php

namespace App\Entity;

use App\Repository\SharedMediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SharedMediaRepository::class)]
#[ORM\Table(name: 'shared_media')]
#[ORM\Index(columns: ['access_key'], name: 'idx_shared_media_access_key')]
#[ORM\Index(columns: ['visibility'], name: 'idx_shared_media_visibility')]
#[ORM\Index(columns: ['media_type'], name: 'idx_shared_media_type')]
#[ORM\Index(columns: ['created_at'], name: 'idx_shared_media_created_at')]
class SharedMedia
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_OTHER = 'autre';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 20)]
    private string $mediaType = self::TYPE_DOCUMENT;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(length: 10)]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $accessKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $downloadCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->downloadCount = 0;
        $this->visibility = self::VISIBILITY_PUBLIC;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function setMediaType(string $mediaType): static
    {
        $this->mediaType = $mediaType;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true)
            ? $visibility
            : self::VISIBILITY_PUBLIC;

        if ($this->visibility === self::VISIBILITY_PRIVATE && empty($this->accessKey)) {
            $this->regenerateAccessKey();
        }

        return $this;
    }

    public function getAccessKey(): ?string
    {
        return $this->accessKey;
    }

    public function setAccessKey(?string $accessKey): static
    {
        $this->accessKey = $accessKey;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function setDownloadCount(int $downloadCount): static
    {
        $this->downloadCount = $downloadCount;
        return $this;
    }

    public function incrementDownloadCount(): static
    {
        $this->downloadCount++;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    // --- Helpers de commodité et sécurité ---

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isImage(): bool
    {
        return $this->mediaType === self::TYPE_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->mediaType === self::TYPE_VIDEO;
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf'
            || str_ends_with(strtolower((string) $this->filename), '.pdf')
            || str_ends_with(strtolower((string) $this->originalFilename), '.pdf');
    }

    /**
     * Génère une clé d'accès cryptographique sécurisée de 64 caractères hexadécimaux.
     */
    public function regenerateAccessKey(): string
    {
        $this->accessKey = bin2hex(random_bytes(32));
        return $this->accessKey;
    }

    /**
     * Retourne la taille du fichier formatée en Ko, Mo ou Go.
     */
    public function getFormattedFileSize(): string
    {
        $bytes = $this->fileSize;
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / pow(1024, $power), 1) . ' ' . $units[$power];
    }

    /**
     * Retourne le chemin relatif public standard.
     */
    public function getPublicSubpath(): string
    {
        return 'assets/uploads/shared/' . ltrim((string) $this->filename, '/');
    }
}
