<?php

namespace App\Entity;

use App\Repository\AiCreditSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réglages de l'assistant IA pour le tenant (une seule ligne par base tenant) : crédits mensuels (sans ligne, 100) et,
 * depuis le 19/10/2026, consignes permanentes de l'administrateur, redonnées au modèle à chaque demande
 * (LandingAiInstructionsService). Modifiable dans EasyAdmin (Landing Page › Assistant IA : crédits), par
 * PUT /api/landingpage-ai/instructions pour les consignes, ou en SQL pour les crédits.
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

    /** Consignes permanentes (texte brut, LandingAiInstructionsService::MAX_LENGTH caractères au plus), ou null */
    #[ORM\Column(name: 'assistant_instructions', type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'Au plus {{ limit }} caractères.')]
    #[Assert\Regex(pattern: '#<\s*[a-zA-Z/!?]#', match: false, message: 'Texte brut attendu, sans balise HTML.')]
    private ?string $assistantInstructions = null;

    #[ORM\Column(name: 'instructions_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $instructionsUpdatedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getAssistantInstructions(): ?string { return $this->assistantInstructions; }
    public function setAssistantInstructions(?string $instructions): static
    {
        $this->assistantInstructions = $instructions;
        $this->instructionsUpdatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getInstructionsUpdatedAt(): ?\DateTimeImmutable { return $this->instructionsUpdatedAt; }

    public function getMonthlyCredits(): int { return $this->monthlyCredits; }
    public function setMonthlyCredits(int $monthlyCredits): static { $this->monthlyCredits = $monthlyCredits; return $this; }
}
