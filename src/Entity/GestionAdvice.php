<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Conseil du guide de la gestion (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md`) : détecté et chiffré par une règle (`AdviceRules`), rédigé par
 * l'IA ou par le texte de repli de la règle ; une ligne par signature (genre + sujet) et par période ; statut new | done | dismissed porté par
 * le site (auteur gardé). Écrit et lu en SQL par `GestionAdviceService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_advice')]
#[ORM\UniqueConstraint(name: 'uniq_gestion_advice_period_signature', columns: ['period_id', 'signature'])]
class GestionAdvice
{
    public const STATUSES = ['new', 'done', 'dismissed'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'period_id')]
    private int $periodId = 0;

    #[ORM\Column(length: 120)]
    private string $signature = '';

    #[ORM\Column(length: 12)]
    private string $moment = '';

    #[ORM\Column(length: 30)]
    private string $kind = '';

    #[ORM\Column(type: 'json')]
    private array $subject = [];

    #[ORM\Column(type: 'json')]
    private array $action = [];

    #[ORM\Column(name: 'effect_cents', type: 'bigint')]
    private string|int $effectCents = 0;

    #[ORM\Column(name: 'effect_label', length: 40)]
    private string $effectLabel = '';

    #[ORM\Column(length: 6)]
    private string $confidence = 'low';

    #[ORM\Column(type: 'json')]
    private array $sources = [];

    #[ORM\Column(type: 'json')]
    private array $figures = [];

    #[ORM\Column(type: 'json')]
    private array $params = [];

    #[ORM\Column(name: 'ai_title', length: 255, nullable: true)]
    private ?string $aiTitle = null;

    #[ORM\Column(name: 'ai_text', type: 'text', nullable: true)]
    private ?string $aiText = null;

    #[ORM\Column(name: 'ai_locale', length: 5, nullable: true)]
    private ?string $aiLocale = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $rank = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 10, options: ['default' => 'new'])]
    private string $status = 'new';

    #[ORM\Column(name: 'status_by', length: 255, nullable: true)]
    private ?string $statusBy = null;

    #[ORM\Column(name: 'status_note', type: 'text', nullable: true)]
    private ?string $statusNote = null;

    #[ORM\Column(name: 'done_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\Column(name: 'dismissed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dismissedAt = null;

    #[ORM\Column(name: 'detected_on', type: 'date_immutable')]
    private ?\DateTimeImmutable $detectedOn = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
}
