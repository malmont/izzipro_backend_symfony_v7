<?php

namespace App\Entity;

use App\Repository\LandingSiteModelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modèle de site enregistré par l'administrateur depuis l'éditeur des landing pages : une configuration complète
 * (même forme que les réglages publiés), conservée à part. N'a aucun effet sur les réglages publiés du site.
 * La configuration est gardée en JSON brut : un {} reste {} et l'ordre des clés est conservé.
 */
#[ORM\Entity(repositoryClass: LandingSiteModelRepository::class)]
#[ORM\Table(name: 'landing_site_model')]
#[ORM\Index(columns: ['updated_at'], name: 'idx_landing_site_model_updated_at')]
class LandingSiteModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $configuration = '{}';

    #[ORM\Column(name: 'tabs_count')]
    private int $tabsCount = 0;

    #[ORM\Column(name: 'sections_count')]
    private int $sectionsCount = 0;

    #[ORM\Column(name: 'created_by', length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    /** JSON brut de la configuration */
    public function getConfiguration(): string { return $this->configuration; }
    public function setConfiguration(string $configuration): static { $this->configuration = $configuration; return $this; }
    public function getTabsCount(): int { return $this->tabsCount; }
    public function setTabsCount(int $tabsCount): static { $this->tabsCount = $tabsCount; return $this; }
    public function getSectionsCount(): int { return $this->sectionsCount; }
    public function setSectionsCount(int $sectionsCount): static { $this->sectionsCount = $sectionsCount; return $this; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $createdBy): static { $this->createdBy = $createdBy; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
