<?php

namespace App\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Pièce jointe d'un message (lot 1) : image ou PDF vérifiés par leur contenu, stockage privé (jamais d'URL publique) */
#[ORM\Entity]
#[ORM\Table(name: 'support_attachment')]
class SupportAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupportMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: false)]
    private ?SupportMessage $message = null;

    #[ORM\Column(name: 'stored_name', length: 80)]
    private string $storedName = '';

    #[ORM\Column(name: 'original_name', length: 200)]
    private string $originalName = '';

    #[ORM\Column(length: 60)]
    private string $mime = '';

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int { return $this->id; }
    public function getMessage(): ?SupportMessage { return $this->message; }
    public function setMessage(SupportMessage $m): static { $this->message = $m; return $this; }
    public function getStoredName(): string { return $this->storedName; }
    public function setStoredName(string $n): static { $this->storedName = $n; return $this; }
    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $n): static { $this->originalName = mb_substr($n, 0, 200); return $this; }
    public function getMime(): string { return $this->mime; }
    public function setMime(string $m): static { $this->mime = $m; return $this; }
    public function getSize(): int { return $this->size; }
    public function setSize(int $s): static { $this->size = $s; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }
}
