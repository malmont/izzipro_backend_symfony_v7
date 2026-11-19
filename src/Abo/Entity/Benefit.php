<?php

namespace App\Abo\Entity;

use App\Abo\Repository\BenefitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prestation du site (lot ABO-0, 18/11/2026, `docs/plan-abo-universel.md`) : une définition que les formules d'abonnement livrent (« Yoga du
 * mardi », « Ménage 3 h », « Carte de séances »). Type universel : `session` (séance planifiée), `service` (service à rendre), `quota`
 * (carte ou forfait d'usages) ; `module` (accès à un module de la plateforme) réservé jusqu'à ABO-5. Noms et descriptions par langue.
 */
#[ORM\Entity(repositoryClass: BenefitRepository::class)]
#[ORM\Table(name: 'benefit')]
class Benefit
{
    public const KIND_SESSION = 'session';
    public const KIND_SERVICE = 'service';
    public const KIND_QUOTA = 'quota';
    public const KIND_MODULE = 'module';
    public const KINDS = [self::KIND_SESSION, self::KIND_SERVICE, self::KIND_QUOTA, self::KIND_MODULE];
    /** Types permis aujourd'hui (`module` : ABO-5) */
    public const OPEN_KINDS = [self::KIND_SESSION, self::KIND_SERVICE, self::KIND_QUOTA];
    public const NAME_LENGTH = 120;
    public const DESCRIPTION_LENGTH = 600;
    public const LOCATION_LENGTH = 160;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_SESSION;

    /** @var array<string, string> langue => nom */
    #[ORM\Column(type: Types::JSON)]
    private array $names = [];

    /** @var array<string, string>|null langue => description */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $descriptions = null;

    #[ORM\Column(name: 'duration_minutes', nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $location = null;

    /** Compte du site qui rend la prestation par défaut (intervenant, ABO-3) ; jamais rendu au public ni au modèle */
    #[ORM\Column(name: 'default_staff_user_id', nullable: true)]
    private ?int $defaultStaffUserId = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->getName(); }
    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind; return $this->touch(); }
    /** @return array<string, string> */
    public function getNames(): array { return $this->names; }
    /** @param array<string, string> $names */
    public function setNames(array $names): static { $this->names = self::texts($names, self::NAME_LENGTH) ?? []; return $this->touch(); }
    public function getName(string $locale = 'fr'): string { return (string) ($this->names[$locale] ?? $this->names['fr'] ?? (reset($this->names) ?: '')); }
    public function getDescriptions(): ?array { return $this->descriptions; }
    public function setDescriptions(?array $descriptions): static { $this->descriptions = self::texts($descriptions, self::DESCRIPTION_LENGTH); return $this->touch(); }
    public function getDescription(string $locale = 'fr'): ?string { return $this->descriptions[$locale] ?? $this->descriptions['fr'] ?? null; }
    public function getDurationMinutes(): ?int { return $this->durationMinutes; }
    public function setDurationMinutes(?int $minutes): static { $this->durationMinutes = $minutes !== null && $minutes > 0 ? min($minutes, 1440) : null; return $this->touch(); }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): static { $l = trim(strip_tags((string) $location)); $this->location = $l !== '' ? mb_substr($l, 0, self::LOCATION_LENGTH) : null; return $this->touch(); }
    public function getDefaultStaffUserId(): ?int { return $this->defaultStaffUserId; }
    public function setDefaultStaffUserId(?int $id): static { $this->defaultStaffUserId = $id !== null && $id > 0 ? $id : null; return $this->touch(); }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this->touch(); }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Textes par langue en clair, bornés ; vide => null */
    private static function texts(?array $texts, int $max): ?array
    {
        $out = [];
        foreach ($texts ?? [] as $locale => $text) {
            $t = trim(strip_tags((string) $text));
            if (is_string($locale) && preg_match('/^[a-z]{2}$/', $locale) && $t !== '') {
                $out[$locale] = mb_substr($t, 0, $max);
            }
        }

        return $out !== [] ? $out : null;
    }
}
