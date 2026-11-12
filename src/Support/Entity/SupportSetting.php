<?php

namespace App\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages de l'assistance du site (lot 1) : délai de réponse en heures ouvrées et jours ouvrés (les heures d'ouverture viendront sans changer
 * la forme) ; envoi des courriels aux clients : `log` (journal seulement, défaut tant que les applications ne gèrent pas l'assistance) ou `send`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_setting')]
class SupportSetting
{
    public const DEFAULT_HOURS = 48;
    public const DEFAULT_DAYS = [1, 2, 3, 4, 5];
    public const OUTBOUND = ['log', 'send'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'response_hours', type: 'smallint')]
    private int $responseHours = self::DEFAULT_HOURS;

    /** @var list<int> 1 lundi … 7 dimanche */
    #[ORM\Column(name: 'business_days', type: 'json')]
    private array $businessDays = self::DEFAULT_DAYS;

    #[ORM\Column(name: 'outbound_mode', length: 10)]
    private string $outboundMode = 'log';

    #[ORM\Column(name: 'saved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $savedAt = null;

    #[ORM\Column(name: 'updated_by', length: 180, nullable: true)]
    private ?string $updatedBy = null;

    public function getId(): ?int { return $this->id; }
    public function getResponseHours(): int { return $this->responseHours; }
    public function setResponseHours(int $h): static { $this->responseHours = $h; return $this; }
    /** @return list<int> */
    public function getBusinessDays(): array { return array_values(array_map('intval', $this->businessDays)); }
    public function setBusinessDays(array $d): static { $this->businessDays = array_values($d); return $this; }
    public function getOutboundMode(): string { return $this->outboundMode; }
    public function setOutboundMode(string $m): static { $this->outboundMode = $m; return $this; }
    public function getSavedAt(): ?\DateTimeImmutable { return $this->savedAt; }
    public function setSavedAt(?\DateTimeImmutable $d): static { $this->savedAt = $d; return $this; }
    public function getUpdatedBy(): ?string { return $this->updatedBy; }
    public function setUpdatedBy(?string $u): static { $this->updatedBy = $u; return $this; }
}
