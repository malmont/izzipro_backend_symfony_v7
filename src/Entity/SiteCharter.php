<?php

namespace App\Entity;

use App\Repository\SiteCharterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Charte et brief du site (08/11/2026, lot « Mon site pas à pas ») : une ligne par site. Le brief (activité, public, lieu, objectifs,
 * pages voulues, boutique), la charte (couleurs, polices, boutons), le style (ton, mouvement, densité), la charte en texte (DESIGN.md) et
 * un document PDF (maquette ou charte) déposé par l'administrateur. L'assistant IA du backend et celui de l'éditeur Next les relisent à
 * chaque composition (`ComposeLandingSectionUseCase`) sans qu'on les redonne. `pages_saved_at` : dernière sauvegarde des pages par
 * l'éditeur ou par le questionnaire ; `composed_at` : dernière composition appliquée par le questionnaire.
 */
#[ORM\Entity(repositoryClass: SiteCharterRepository::class)]
#[ORM\Table(name: 'site_charter')]
class SiteCharter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $brief = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $charter = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $style = null;

    #[ORM\Column(name: 'design_md', type: Types::TEXT, nullable: true)]
    private ?string $designMd = null;

    #[ORM\Column(name: 'document_name', length: 255, nullable: true)]
    private ?string $documentName = null;

    #[ORM\Column(name: 'document_path', length: 255, nullable: true)]
    private ?string $documentPath = null;

    #[ORM\Column(name: 'pages_saved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pagesSavedAt = null;

    #[ORM\Column(name: 'composed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $composedAt = null;

    #[ORM\Column(name: 'last_job_id', length: 36, nullable: true)]
    private ?string $lastJobId = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'updated_by', length: 255, nullable: true)]
    private ?string $updatedBy = null;

    public function getId(): ?int { return $this->id; }
    public function getBrief(): ?array { return $this->brief; }
    public function setBrief(?array $brief): static { $this->brief = $brief; return $this; }
    public function getCharter(): ?array { return $this->charter; }
    public function setCharter(?array $charter): static { $this->charter = $charter; return $this; }
    public function getStyle(): ?array { return $this->style; }
    public function setStyle(?array $style): static { $this->style = $style; return $this; }
    public function getDesignMd(): ?string { return $this->designMd; }
    public function setDesignMd(?string $designMd): static { $this->designMd = $designMd; return $this; }
    public function getDocumentName(): ?string { return $this->documentName; }
    public function setDocumentName(?string $name): static { $this->documentName = $name; return $this; }
    public function getDocumentPath(): ?string { return $this->documentPath; }
    public function setDocumentPath(?string $path): static { $this->documentPath = $path; return $this; }
    public function getPagesSavedAt(): ?\DateTimeImmutable { return $this->pagesSavedAt; }
    public function setPagesSavedAt(?\DateTimeImmutable $at): static { $this->pagesSavedAt = $at; return $this; }
    public function getComposedAt(): ?\DateTimeImmutable { return $this->composedAt; }
    public function setComposedAt(?\DateTimeImmutable $at): static { $this->composedAt = $at; return $this; }
    public function getLastJobId(): ?string { return $this->lastJobId; }
    public function setLastJobId(?string $id): static { $this->lastJobId = $id; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): ?string { return $this->updatedBy; }

    public function touch(?string $by): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $by;

        return $this;
    }
}
