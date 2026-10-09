<?php

namespace App\Entity;

use App\Repository\ReviewSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages des avis clients d'un site (une seule ligne, EasyAdmin « Réglages des avis ») ; absents = valeurs par défaut :
 * avis activés, acheteurs vérifiés seulement, modération avant publication, 20 caractères au moins.
 */
#[ORM\Entity(repositoryClass: ReviewSettingRepository::class)]
#[ORM\Table(name: 'review_setting')]
class ReviewSetting
{
    public const MODERATION_MANUAL = 'manual';
    public const MODERATION_AUTO = 'auto';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** true : seul un client dont une commande payée contient le produit peut écrire ; false : tout client connecté */
    #[ORM\Column(name: 'verified_only', options: ['default' => true])]
    private bool $verifiedOnly = true;

    /** manual : publié après validation ; auto : publié aussitôt (l'administrateur peut le retirer ensuite) */
    #[ORM\Column(length: 10, options: ['default' => self::MODERATION_MANUAL])]
    private string $moderation = self::MODERATION_MANUAL;

    #[ORM\Column(name: 'min_length', options: ['default' => 20])]
    private int $minLength = 20;

    /** Politique des avis affichée sous la liste, par langue : {"fr": "…", "en": "…"} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $policy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return 'Réglages des avis';
    }

    public function getId(): ?int { return $this->id; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this->touch(); }

    public function isVerifiedOnly(): bool { return $this->verifiedOnly; }
    public function setVerifiedOnly(bool $flag): static { $this->verifiedOnly = $flag; return $this->touch(); }

    public function getModeration(): string { return $this->moderation; }
    public function setModeration(string $moderation): static { $this->moderation = $moderation; return $this->touch(); }
    public function isAutoPublish(): bool { return $this->moderation === self::MODERATION_AUTO; }

    public function getMinLength(): int { return $this->minLength; }
    public function setMinLength(int $length): static { $this->minLength = max(0, min(500, $length)); return $this->touch(); }

    public function getPolicy(): ?array { return $this->policy; }
    public function setPolicy(?array $policy): static { $this->policy = $policy; return $this->touch(); }

    /** Politique dans la langue demandée, sinon en français, sinon la première */
    public function policyFor(string $locale): ?string
    {
        if (!$this->policy) {
            return null;
        }
        $text = $this->policy[$locale] ?? $this->policy['fr'] ?? reset($this->policy);

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
