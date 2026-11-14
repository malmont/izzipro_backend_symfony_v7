<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages et état d'analyse du module Prévention des pertes (lot L1-a, `docs/prevention-pertes.md`) : une seule ligne par site
 * (index unique `uniq_loss_setting_single`). Lue et écrite en SQL par son dépôt.
 */
#[ORM\Entity(repositoryClass: LossSettingRepository::class)]
#[ORM\Table(name: 'loss_setting')]
class LossSetting
{
    public const DEFAULT_FINDINGS_MONTHS = 24;
    public const DEFAULT_CONFIRMED_MONTHS = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Le gérant confirme avoir informé ses salariés (exigé avant toute analyse en France et au Québec) */
    #[ORM\Column(name: 'staff_notice_confirmed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $staffNoticeConfirmedAt = null;

    #[ORM\Column(name: 'staff_notice_confirmed_by', nullable: true)]
    private ?int $staffNoticeConfirmedBy = null;

    /** Règles par personne : null = valeur par défaut du pays et de la région (LossCountryPolicy) */
    #[ORM\Column(name: 'person_patterns_enabled', nullable: true)]
    private ?bool $personPatternsEnabled = null;

    #[ORM\Column(name: 'retention_findings_months', type: 'smallint', options: ['default' => self::DEFAULT_FINDINGS_MONTHS])]
    private int $retentionFindingsMonths = self::DEFAULT_FINDINGS_MONTHS;

    #[ORM\Column(name: 'retention_confirmed_months', type: 'smallint', options: ['default' => self::DEFAULT_CONFIRMED_MONTHS])]
    private int $retentionConfirmedMonths = self::DEFAULT_CONFIRMED_MONTHS;

    /** Dernière séquence du journal de caisse lue par l'analyse ; null = analyse jamais lancée */
    #[ORM\Column(name: 'cursor_sequence', nullable: true)]
    private ?int $cursorSequence = null;

    /** Séquence la plus haute du journal à la première analyse : un constat né d'une entrée antérieure est une « reprise » */
    #[ORM\Column(name: 'backfill_until_sequence', nullable: true)]
    private ?int $backfillUntilSequence = null;

    /**
     * Plancher de l'analyse : aucune entrée du journal de caisse de séquence inférieure ou égale n'est jamais lue, ni par le curseur ni par une
     * règle qui relit une session. Posé à la confirmation de la notice aux salariés là où elle est exigée (choix A du 14/11/2026 : rien de ce
     * qui précède la notice n'est analysé) ; null ailleurs.
     */
    #[ORM\Column(name: 'analysis_floor_sequence', nullable: true)]
    private ?int $analysisFloorSequence = null;

    #[ORM\Column(name: 'last_analysis_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAnalysisAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'updated_by', nullable: true)]
    private ?int $updatedBy = null;

    public function getId(): ?int { return $this->id; }
    public function getStaffNoticeConfirmedAt(): ?\DateTimeImmutable { return $this->staffNoticeConfirmedAt; }
    public function getStaffNoticeConfirmedBy(): ?int { return $this->staffNoticeConfirmedBy; }
    public function getPersonPatternsEnabled(): ?bool { return $this->personPatternsEnabled; }
    public function getRetentionFindingsMonths(): int { return $this->retentionFindingsMonths; }
    public function getRetentionConfirmedMonths(): int { return $this->retentionConfirmedMonths; }
    public function getCursorSequence(): ?int { return $this->cursorSequence; }
    public function getBackfillUntilSequence(): ?int { return $this->backfillUntilSequence; }
    public function getAnalysisFloorSequence(): ?int { return $this->analysisFloorSequence; }
    public function getLastAnalysisAt(): ?\DateTimeImmutable { return $this->lastAnalysisAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): ?int { return $this->updatedBy; }
}
