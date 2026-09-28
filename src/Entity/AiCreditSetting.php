<?php

namespace App\Entity;

use App\Repository\AiCreditSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Crédits mensuels de l'assistant IA pour le tenant (une ligne par base tenant, sans interface :
 * modifiable en SQL, par exemple UPDATE ai_credit_setting SET monthly_credits = 200).
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
    private int $monthlyCredits = self::DEFAULT_MONTHLY_CREDITS;

    public function getId(): ?int { return $this->id; }

    public function getMonthlyCredits(): int { return $this->monthlyCredits; }
    public function setMonthlyCredits(int $monthlyCredits): static { $this->monthlyCredits = $monthlyCredits; return $this; }
}
