<?php

namespace App\Entity;

use App\Repository\PeriodResultRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Résultat d'une période de gestion figé à la première lecture après sa fermeture (lot P2, 10/11/2026,
 * `docs/periodes-gestion.md` § 3) : charge utile JSON telle que rendue par `GET /api/periodes/{id}/resultat`.
 * Supprimé à la réouverture de la période (`ReopenCollectionUseCase`) ; jamais lu en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity(repositoryClass: PeriodResultRepository::class)]
#[ORM\Table(name: 'period_result')]
class PeriodResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Collections::class)]
    #[ORM\JoinColumn(name: 'collection_id', nullable: false, onDelete: 'CASCADE')]
    private ?Collections $collection = null;

    #[ORM\Column(name: 'computed_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $computedAt = null;

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollection(): ?Collections
    {
        return $this->collection;
    }

    public function setCollection(Collections $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function getComputedAt(): ?\DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function setComputedAt(\DateTimeImmutable $computedAt): static
    {
        $this->computedAt = $computedAt;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }
}
