<?php

namespace App\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Message d'une demande d'assistance (lot 1) : client, agent ou système ; note interne jamais envoyée ; état de l'envoi du courriel */
#[ORM\Entity]
#[ORM\Table(name: 'support_message')]
class SupportMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false)]
    private ?SupportTicket $ticket = null;

    #[ORM\Column(name: 'author_type', length: 10)]
    private string $authorType = 'agent';

    #[ORM\Column(name: 'author_user_id', nullable: true)]
    private ?int $authorUserId = null;

    #[ORM\Column(name: 'author_name', length: 160, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $internal = false;

    #[ORM\Column(name: 'email_status', length: 10, nullable: true)]
    private ?string $emailStatus = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int { return $this->id; }
    public function getTicket(): ?SupportTicket { return $this->ticket; }
    public function setTicket(SupportTicket $t): static { $this->ticket = $t; return $this; }
    public function getAuthorType(): string { return $this->authorType; }
    public function setAuthorType(string $t): static { $this->authorType = $t; return $this; }
    public function getAuthorUserId(): ?int { return $this->authorUserId; }
    public function setAuthorUserId(?int $id): static { $this->authorUserId = $id; return $this; }
    public function getAuthorName(): ?string { return $this->authorName; }
    public function setAuthorName(?string $n): static { $this->authorName = $n !== null ? mb_substr($n, 0, 160) : null; return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $b): static { $this->body = $b; return $this; }
    public function isInternal(): bool { return $this->internal; }
    public function setInternal(bool $i): static { $this->internal = $i; return $this; }
    public function getEmailStatus(): ?string { return $this->emailStatus; }
    public function setEmailStatus(?string $s): static { $this->emailStatus = $s; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }
}
