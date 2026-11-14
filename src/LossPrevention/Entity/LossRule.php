<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossRuleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage d'une règle par le site (lot L1-a) : une ligne n'existe que si le gérant a changé quelque chose ; sinon les valeurs par défaut
 * du catalogue (`Services/LossRuleCatalog`) s'appliquent. `personEnabled` null = valeur par défaut du pays.
 */
#[ORM\Entity(repositoryClass: LossRuleRepository::class)]
#[ORM\Table(name: 'loss_rule')]
#[ORM\UniqueConstraint(name: 'uniq_loss_rule_code', columns: ['code'])]
class LossRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** @var array<string, int> */
    #[ORM\Column(type: 'json')]
    private array $params = [];

    #[ORM\Column(name: 'person_enabled', nullable: true)]
    private ?bool $personEnabled = null;

    /** @var array<string, int>|null */
    #[ORM\Column(name: 'person_params', type: 'json', nullable: true)]
    private ?array $personParams = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'updated_by', nullable: true)]
    private ?int $updatedBy = null;

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function isEnabled(): bool { return $this->enabled; }
    /** @return array<string, int> */
    public function getParams(): array { return $this->params; }
    public function getPersonEnabled(): ?bool { return $this->personEnabled; }
    /** @return array<string, int>|null */
    public function getPersonParams(): ?array { return $this->personParams; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): ?int { return $this->updatedBy; }
}
