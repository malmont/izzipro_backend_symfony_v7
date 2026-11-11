<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Commentaire du simulateur par le guide (lot G1b-2, 11/11/2026, `docs/guide-ia-gestion.md` § 9) : réponse gardée par période, empreinte du
 * corps et jour du magasin (cache sans nouveau crédit) ; compte aussi pour le plafond de 10 par site et par jour. Écrit et lu en SQL par
 * `GestionSimulationCommentService`, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_simulation_comment')]
#[ORM\UniqueConstraint(name: 'uniq_gestion_simulation_comment', columns: ['period_id', 'body_hash', 'store_day'])]
#[ORM\Index(name: 'idx_gestion_simulation_comment_day', columns: ['store_day'])]
class GestionSimulationComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'period_id')]
    private int $periodId = 0;

    #[ORM\Column(name: 'body_hash', length: 64)]
    private string $bodyHash = '';

    #[ORM\Column(name: 'store_day', type: 'date_immutable')]
    private ?\DateTimeImmutable $storeDay = null;

    #[ORM\Column(type: 'json')]
    private array $response = [];

    #[ORM\Column(name: 'ai_status', length: 20)]
    private string $aiStatus = 'rules_only';

    #[ORM\Column(name: 'ai_usage_id', nullable: true)]
    private ?int $aiUsageId = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int { return $this->id; }
}
