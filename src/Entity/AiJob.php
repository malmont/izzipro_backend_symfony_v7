<?php

namespace App\Entity;

use App\Repository\AiJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tâche de fond de l'assistant IA (mode page, requêtes avec images) : traitée par le worker Messenger.
 * La requête (images comprises) n'est gardée que jusqu'au traitement ; le résultat, 1 heure après la fin.
 * Stockée dans la base du tenant : un autre tenant ne peut pas la lire.
 */
#[ORM\Entity(repositoryClass: AiJobRepository::class)]
#[ORM\Table(name: 'ai_job')]
#[ORM\Index(columns: ['status'], name: 'idx_ai_job_status')]
class AiJob
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column]
    private int $usageId;

    #[ORM\Column(name: 'user_identifier', length: 180, nullable: true)]
    private ?string $user = null;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Corps JSON de la requête (images comprises), effacé dès le traitement */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $input = null;

    /** Réponse JSON (même contenu que la réponse synchrone), texte brut pour garder les {} */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $result = null;

    /** Erreur JSON { status, error, message, errors? } */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(string $id, int $usageId, ?string $user, string $input)
    {
        $this->id = $id;
        $this->usageId = $usageId;
        $this->user = $user;
        $this->input = $input;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getUsageId(): int { return $this->usageId; }
    public function getUser(): ?string { return $this->user; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getInput(): ?string { return $this->input; }
    public function getResult(): ?string { return $this->result; }
    public function getError(): ?string { return $this->error; }

    public function start(): void
    {
        $this->status = self::STATUS_RUNNING;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function succeed(string $result): void
    {
        $this->finish(self::STATUS_DONE);
        $this->result = $result;
    }

    public function fail(string $error): void
    {
        $this->finish(self::STATUS_FAILED);
        $this->error = $error;
    }

    private function finish(string $status): void
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();
        $this->input = null;
    }
}
