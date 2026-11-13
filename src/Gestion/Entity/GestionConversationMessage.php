<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Message d'un fil de conversation de la gestion (lot IA-FACT-1, 13/11/2026) : ce que le gérant a envoyé ou décidé, et ce que le serveur
 * a constaté, dans l'ordre. `role` : `user` ou `assistant` (le serveur ; aucune IA dans ce lot). `kind` : `text`, `document` (pièce
 * jointe), `summary` (résumé de la lecture), `answers`, `confirmed`, `undone`, `abandoned`. `content` : les données du message.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_conversation_message')]
class GestionConversationMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GestionConversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    private ?GestionConversation $conversation = null;

    #[ORM\Column(length: 12)]
    private string $role = self::ROLE_USER;

    #[ORM\Column(length: 20)]
    private string $kind = 'text';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $content = [];

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @param array<string, mixed> $content */
    public static function of(GestionConversation $conversation, string $role, string $kind, array $content, ?string $by = null): self
    {
        $message = new self();
        $message->conversation = $conversation;
        $message->role = $role;
        $message->kind = $kind;
        $message->content = $content;
        $message->createdBy = $by;
        $message->createdAt = new \DateTimeImmutable();

        return $message;
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): ?GestionConversation { return $this->conversation; }
    public function getRole(): string { return $this->role; }
    public function getKind(): string { return $this->kind; }
    /** @return array<string, mixed> */
    public function getContent(): array { return $this->content; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
