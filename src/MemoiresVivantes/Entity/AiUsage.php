<?php

namespace App\MemoiresVivantes\Entity;

use App\Entity\User;
use App\MemoiresVivantes\Repository\AiUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un appel IA abouti de Mémoires Vivantes (16/10/2026) : transcription Whisper ou amélioration d'une réponse, pour
 * un utilisateur connecté soumis aux quotas. Seuls les appels qui ont rendu un résultat sont enregistrés (un 422
 * noSpeech ou un 503 ne comptent pas). Le livre et le chapitre sont gardés en identifiants simples (pas de clé
 * étrangère : la suppression d'un livre ne doit pas effacer la consommation du jour).
 */
#[ORM\Entity(repositoryClass: AiUsageRepository::class)]
#[ORM\Table(name: 'mv_ai_usage')]
#[ORM\Index(columns: ['user_id', 'kind', 'created_at'], name: 'idx_mv_ai_usage_user_kind_date')]
class AiUsage
{
    public const KIND_TRANSCRIPTION = 'transcription';
    public const KIND_IMPROVEMENT = 'improvement';
    /** Rédaction d'un chapitre aboutie (livres mobile, enregistrée par GenerateChapterHandler) : cumul seulement, pas de quota */
    public const KIND_GENERATION = 'generation';
    /** Type de quota des reprises de rédaction (pas une ligne d'usage : les reprises sont comptées sur les lignes « generation » du chapitre) */
    public const KIND_REGENERATION = 'regeneration';
    /** Types soumis aux quotas journaliers */
    public const QUOTA_KINDS = [self::KIND_TRANSCRIPTION, self::KIND_IMPROVEMENT];
    public const KINDS = [self::KIND_TRANSCRIPTION, self::KIND_IMPROVEMENT, self::KIND_GENERATION];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $bookId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $chapterId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $kind, ?Uuid $bookId = null, ?Uuid $chapterId = null)
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Type d\'appel IA inconnu : « %s »', $kind));
        }
        $this->user = $user;
        $this->kind = $kind;
        $this->bookId = $bookId;
        $this->chapterId = $chapterId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getKind(): string { return $this->kind; }
    public function getBookId(): ?Uuid { return $this->bookId; }
    public function getChapterId(): ?Uuid { return $this->chapterId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
