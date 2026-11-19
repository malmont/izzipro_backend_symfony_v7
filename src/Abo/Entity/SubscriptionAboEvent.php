<?php

namespace App\Abo\Entity;

use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des droits d'un abonnement (lot ABO-1, 19/11/2026) : né, utilisé, utilisation annulée, offert, reporté, perdu, pause, reprise,
 * résiliation. `by_user` et `note` ne sont jamais rendus à l'abonné ni au modèle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_abo_event')]
class SubscriptionAboEvent
{
    public const TYPES = ['born', 'used', 'use_undone', 'gift', 'carried', 'expired', 'pause', 'resume', 'cancel', 'trial_without_rights'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', nullable: false, onDelete: 'CASCADE')]
    private ?Subscription $subscription = null;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(name: 'benefit_id', nullable: true)]
    private ?int $benefitId = null;

    #[ORM\Column]
    private int $quantity = 0;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'by_user', length: 180, nullable: true)]
    private ?string $byUser = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $at;

    public function __construct(Subscription $subscription, string $type, ?int $benefitId = null, int $quantity = 0, ?string $note = null, ?string $by = null)
    {
        $this->subscription = $subscription;
        $this->type = $type;
        $this->benefitId = $benefitId;
        $this->quantity = $quantity;
        $n = trim(strip_tags((string) $note));
        $this->note = $n !== '' ? mb_substr($n, 0, 300) : null;
        $this->byUser = $by !== null ? mb_substr($by, 0, 180) : null;
        $this->at = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function getType(): string { return $this->type; }
    public function getBenefitId(): ?int { return $this->benefitId; }
    public function getQuantity(): int { return $this->quantity; }
    public function getNote(): ?string { return $this->note; }
    public function getByUser(): ?string { return $this->byUser; }
    public function getAt(): \DateTimeImmutable { return $this->at; }
}
