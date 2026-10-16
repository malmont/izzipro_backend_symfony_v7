<?php

namespace App\MemoiresVivantes\Entity;

use App\MemoiresVivantes\Repository\PricingSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tarifs de Mémoires Vivantes pour le site (16/10/2026) : grille du livre imprimé par devise (premier exemplaire
 * d'un livre, exemplaires suivants du même livre, livraison comprise) et identifiant du produit « rédaction d'un
 * chapitre » des stores (son prix est fixé dans les stores, pas ici). Une ligne par base tenant ; sans ligne, la
 * grille par défaut. Réglable par GET/PUT /api/memoires/admin/pricing.
 */
#[ORM\Entity(repositoryClass: PricingSettingRepository::class)]
#[ORM\Table(name: 'mv_pricing_setting')]
class PricingSetting
{
    public const DEFAULT_CHAPTER_PRODUCT = 'lm_chapter_writing';
    /** Devises acceptées : celles des pays de livraison, toutes gérées par Stripe */
    public const ALLOWED_CURRENCIES = ['eur', 'cad', 'usd', 'chf', 'gbp'];
    public const DEFAULT_GRID = [
        'eur' => ['firstCopy' => 200.0, 'nextCopies' => 100.0],
        'cad' => ['firstCopy' => 300.0, 'nextCopies' => 150.0],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, array{firstCopy: float, nextCopies: float}> devise (minuscules) → montants en unités */
    #[ORM\Column(type: Types::JSON)]
    private array $printGrid = self::DEFAULT_GRID;

    #[ORM\Column(length: 100, options: ['default' => self::DEFAULT_CHAPTER_PRODUCT])]
    private string $chapterProduct = self::DEFAULT_CHAPTER_PRODUCT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    /** @return array<string, array{firstCopy: float, nextCopies: float}> */
    public function getPrintGrid(): array
    {
        $grid = [];
        foreach ($this->printGrid as $currency => $prices) {
            $grid[strtolower((string) $currency)] = ['firstCopy' => round((float) ($prices['firstCopy'] ?? 0), 2), 'nextCopies' => round((float) ($prices['nextCopies'] ?? 0), 2)];
        }

        return $grid;
    }

    /** @param array<string, array{firstCopy: float, nextCopies: float}> $grid */
    public function setPrintGrid(array $grid): static
    {
        $this->printGrid = $grid;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getChapterProduct(): string { return $this->chapterProduct; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Première devise de la grille */
    public function defaultCurrency(): string
    {
        return (string) (array_key_first($this->getPrintGrid()) ?? 'eur');
    }
}
