<?php

namespace App\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'assistance du site (lot 1, 12/11/2026, `docs/assistance.md`) : rattachée à un client, à une commande ou une vente de caisse ;
 * état new | open | waiting_customer | closed, priorité, canal, agent, délai de réponse (`due_at`, heures ouvrées du site), anonymisée 3 ans
 * après sa fermeture (Loi 25, RGPD).
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_ticket')]
#[ORM\HasLifecycleCallbacks]
class SupportTicket
{
    public const STATUSES = ['new', 'open', 'waiting_customer', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const CHANNELS = ['agent', 'phone', 'counter', 'email', 'web', 'app', 'pos'];
    /** transitions permises : état → états suivants */
    public const TRANSITIONS = ['new' => ['open', 'closed'], 'open' => ['waiting_customer', 'closed'], 'waiting_customer' => ['open', 'closed'], 'closed' => ['open']];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference = '';

    #[ORM\Column(length: 200)]
    private string $subject = '';

    #[ORM\Column(length: 20)]
    private string $status = 'new';

    #[ORM\Column(length: 10)]
    private string $priority = 'normal';

    #[ORM\Column(length: 10)]
    private string $channel = 'agent';

    #[ORM\Column(name: 'customer_user_id', nullable: true)]
    private ?int $customerUserId = null;

    #[ORM\Column(name: 'customer_name', length: 160, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(name: 'customer_email', length: 180, nullable: true)]
    private ?string $customerEmail = null;

    #[ORM\Column(name: 'customer_locale', length: 5)]
    private string $customerLocale = 'fr';

    #[ORM\Column(name: 'order_id', nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column(name: 'cash_register_id', nullable: true)]
    private ?int $cashRegisterId = null;

    #[ORM\Column(name: 'assigned_user_id', nullable: true)]
    private ?int $assignedUserId = null;

    #[ORM\Column(name: 'due_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(name: 'first_response_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstResponseAt = null;

    #[ORM\Column(name: 'last_message_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(name: 'last_customer_message_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCustomerMessageAt = null;

    #[ORM\Column(name: 'closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'anonymized_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $anonymizedAt = null;

    #[ORM\Column(name: 'created_by', length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function setReference(string $r): static { $this->reference = $r; return $this; }
    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $s): static { $this->subject = mb_substr($s, 0, 200); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $p): static { $this->priority = $p; return $this; }
    public function getChannel(): string { return $this->channel; }
    public function setChannel(string $c): static { $this->channel = $c; return $this; }
    public function getCustomerUserId(): ?int { return $this->customerUserId; }
    public function setCustomerUserId(?int $id): static { $this->customerUserId = $id; return $this; }
    public function getCustomerName(): ?string { return $this->customerName; }
    public function setCustomerName(?string $n): static { $this->customerName = $n !== null ? mb_substr($n, 0, 160) : null; return $this; }
    public function getCustomerEmail(): ?string { return $this->customerEmail; }
    public function setCustomerEmail(?string $e): static { $this->customerEmail = $e !== null ? mb_substr($e, 0, 180) : null; return $this; }
    public function getCustomerLocale(): string { return $this->customerLocale; }
    public function setCustomerLocale(string $l): static { $this->customerLocale = $l; return $this; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function setOrderId(?int $id): static { $this->orderId = $id; return $this; }
    public function getCashRegisterId(): ?int { return $this->cashRegisterId; }
    public function setCashRegisterId(?int $id): static { $this->cashRegisterId = $id; return $this; }
    public function getAssignedUserId(): ?int { return $this->assignedUserId; }
    public function setAssignedUserId(?int $id): static { $this->assignedUserId = $id; return $this; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $d): static { $this->dueAt = $d; return $this; }
    public function getFirstResponseAt(): ?\DateTimeImmutable { return $this->firstResponseAt; }
    public function setFirstResponseAt(?\DateTimeImmutable $d): static { $this->firstResponseAt = $d; return $this; }
    public function getLastMessageAt(): ?\DateTimeImmutable { return $this->lastMessageAt; }
    public function setLastMessageAt(?\DateTimeImmutable $d): static { $this->lastMessageAt = $d; return $this; }
    public function getLastCustomerMessageAt(): ?\DateTimeImmutable { return $this->lastCustomerMessageAt; }
    public function setLastCustomerMessageAt(?\DateTimeImmutable $d): static { $this->lastCustomerMessageAt = $d; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $d): static { $this->closedAt = $d; return $this; }
    public function getAnonymizedAt(): ?\DateTimeImmutable { return $this->anonymizedAt; }
    public function setAnonymizedAt(?\DateTimeImmutable $d): static { $this->anonymizedAt = $d; return $this; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $u): static { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
