<?php

namespace App\Entity;

use App\Repository\AiCreditSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Crédits mensuels de l'assistant IA pour le tenant (une seule ligne par base tenant ; sans ligne, 100 crédits).
 * Modifiable dans EasyAdmin (Landing Page › Assistant IA : crédits) ou en SQL.
 */
#[ORM\Entity(repositoryClass: AiCreditSettingRepository::class)]
#[ORM\Table(name: 'ai_credit_setting')]
class AiCreditSetting
{
    public const DEFAULT_MONTHLY_CREDITS = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le nombre de crédits ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'Au plus 100 000 crédits par mois.')]
    private int $monthlyCredits = self::DEFAULT_MONTHLY_CREDITS;

    public function getId(): ?int { return $this->id; }

    public function getMonthlyCredits(): int { return $this->monthlyCredits; }
    public function setMonthlyCredits(int $monthlyCredits): static { $this->monthlyCredits = $monthlyCredits; return $this; }
}
