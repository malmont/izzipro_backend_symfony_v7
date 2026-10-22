<?php

namespace App\Entity;

use App\Repository\PosOfflinePackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instantané d'un pack hors ligne émis (C5 partie 2, 25/10/2026) : prix, taxes et arrondi de la version, pour recalculer une
 * vente hors ligne aux conditions que la tablette connaissait (un changement de prix légitime pendant la coupure n'est pas
 * un écart).
 */
#[ORM\Entity(repositoryClass: PosOfflinePackRepository::class)]
#[ORM\Table(name: 'pos_offline_pack')]
#[ORM\UniqueConstraint(name: 'uniq_pos_offline_pack_version', columns: ['version'])]
class PosOfflinePack
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $version = '';

    #[ORM\Column(length: 64)]
    private string $etag = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    /** Prix et conditions de la version : {step, taxes: [{id, label, rate}], prices: {variantId: {unit, special, from, to}}} */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot = [];

    public function __construct(string $version, string $etag, \DateTimeImmutable $validUntil, array $snapshot)
    {
        $this->version = $version;
        $this->etag = $etag;
        $this->generatedAt = new \DateTimeImmutable();
        $this->validUntil = $validUntil;
        $this->snapshot = $snapshot;
    }

    public function getId(): ?int { return $this->id; }
    public function getVersion(): string { return $this->version; }
    public function getEtag(): string { return $this->etag; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function getValidUntil(): \DateTimeImmutable { return $this->validUntil; }
    public function getSnapshot(): array { return $this->snapshot; }
}
