<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossJournalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal du module (lot L1-a, `docs/prevention-pertes.md` § 6) : qui a fait quoi sur les constats, les règles et les réglages. À part du
 * journal de caisse, qui ne parle que de la caisse. En ajout seul : un déclencheur de la base refuse modification, suppression et vidage.
 */
#[ORM\Entity(repositoryClass: LossJournalRepository::class)]
#[ORM\Table(name: 'loss_journal')]
#[ORM\Index(name: 'idx_loss_journal_finding', columns: ['finding_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_loss_journal_type', columns: ['type', 'id'])]
class LossJournalEntry
{
    public const FINDING_OPENED = 'FINDING_OPENED';
    public const FINDING_COMPLETED = 'FINDING_COMPLETED';
    public const FINDING_VIEWED = 'FINDING_VIEWED';
    public const DECISION_CHANGED = 'DECISION_CHANGED';
    public const RULE_CHANGED = 'RULE_CHANGED';
    public const SETTINGS_CHANGED = 'SETTINGS_CHANGED';
    public const STAFF_NOTICE_CONFIRMED = 'STAFF_NOTICE_CONFIRMED';
    public const STAFF_NOTICE_WITHDRAWN = 'STAFF_NOTICE_WITHDRAWN';
    public const ANALYSIS_STARTED = 'ANALYSIS_STARTED';
    /** Entrées du journal de caisse qu'une règle n'a pas pu lire pendant un passage (sautées) : anomalie à faire remonter */
    public const ENTRIES_SKIPPED = 'ENTRIES_SKIPPED';
    /** Courriel de résumé du jour envoyé (lot L1-b) : nombre de constats nouveaux, montant en jeu, nombre de destinataires ; aucun nom, aucune adresse */
    public const DIGEST_SENT = 'DIGEST_SENT';
    /**
     * Constat de reprise redevenu « nouveau » : une preuve postérieure à la première analyse l'a complété. Le courriel du jour suivant
     * l'annonce une fois, avec la mention « complété par une nouvelle preuve » (décision du propriétaire du 14/11/2026)
     */
    public const FINDING_RENEWED = 'FINDING_RENEWED';
    /** Lot L5-b : un constat décidé a grossi (avant, après) ; il redevient « nouveau », sa décision est gardée */
    public const FINDING_GREW = 'FINDING_GREW';
    /** Exercice lancé (lot EX-1) : son numéro (qui ne redescend jamais) et son scénario ; sans constat rattaché, donc jamais supprimé */
    public const EXERCISE_STARTED = 'EXERCISE_STARTED';
    /** Exercices effacés par le gérant (lot EX-1) : leur nombre ; sans constat rattaché */
    public const EXERCISES_DELETED = 'EXERCISES_DELETED';
    /** Courriel d'exercice envoyé à l'administrateur qui l'a demandé (lot EX-1) : aucune adresse */
    public const EXERCISE_EMAIL_SENT = 'EXERCISE_EMAIL_SENT';
    /** Pièces d'exercice créées (lot L2-a) : leur nombre ; sert au plafond du jour, sans constat rattaché, donc jamais supprimé */
    public const EXERCISE_PIECES_CREATED = 'EXERCISE_PIECES_CREATED';
    /** Pièces d'exercice effacées par le gérant (lot L2-a) : leur nombre, avec leurs puces et leurs faits */
    public const EXERCISE_PIECES_DELETED = 'EXERCISE_PIECES_DELETED';
    /** Lot L3-c : travail d'impression de puces créé ou annulé ; mot de passe d'accès des puces tiré (son RANG seulement, jamais lui) */
    public const PRINT_JOB_CREATED = 'PRINT_JOB_CREATED';
    public const PRINT_JOB_CANCELLED = 'PRINT_JOB_CANCELLED';
    public const TAG_SECRET_CREATED = 'TAG_SECRET_CREATED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'finding_id', nullable: true)]
    private ?int $findingId = null;

    #[ORM\Column(type: 'text')]
    private string $data = '{}';

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getUserId(): ?int { return $this->userId; }
    public function getFindingId(): ?int { return $this->findingId; }
    public function getData(): string { return $this->data; }
}
