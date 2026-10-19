<?php

namespace App\Services\LandingAiService;

use App\Entity\AiCreditSetting;
use App\Repository\AiCreditSettingRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Consignes permanentes de l'assistant IA (19/10/2026) : un texte de l'administrateur valable pour tout le site
 * (« garder nos couleurs, pas d'animations, ton chaleureux, tutoiement »), redonné au modèle à chaque demande, dans
 * tous les modes, comme une donnée encadrée. Il s'ajoute aux règles de l'assistant sans les remplacer ; la demande du
 * moment prime en cas de contradiction. Rangé sur la ligne des réglages de l'assistant (ai_credit_setting).
 */
final class LandingAiInstructionsService
{
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** Consignes du site courant, ou null */
    public function current(): ?string
    {
        return $this->repository()->findSetting()?->getAssistantInstructions();
    }

    /** @return array{instructions: ?string, updatedAt: ?string, maxLength: int} */
    public function describe(): array
    {
        $setting = $this->repository()->findSetting();

        return [
            'instructions' => $setting?->getAssistantInstructions(),
            'updatedAt' => $setting?->getInstructionsUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'maxLength' => self::MAX_LENGTH,
        ];
    }

    /**
     * Texte brut de MAX_LENGTH caractères au plus, sans balise (« < » suivi d'une lettre, de « / » ou de « ! ») ;
     * null ou vide = aucune consigne.
     *
     * @return list<array{path: string, message: string}>
     */
    public static function validate(mixed $instructions): array
    {
        if ($instructions === null) {
            return [];
        }
        if (!is_string($instructions)) {
            return [['path' => 'instructions', 'message' => 'texte ou null attendu']];
        }
        if (mb_strlen(trim($instructions)) > self::MAX_LENGTH) {
            return [['path' => 'instructions', 'message' => sprintf('%d caractères au plus', self::MAX_LENGTH)]];
        }
        if (preg_match('#<\s*[a-zA-Z/!?]#', $instructions)) {
            return [['path' => 'instructions', 'message' => 'texte brut attendu, sans balise HTML']];
        }

        return [];
    }

    /**
     * Enregistre les consignes (déjà validées) ; crée la ligne des réglages si besoin, avec les crédits par défaut.
     *
     * @return array{0: ?string, 1: ?string} consignes avant, après
     */
    public function save(?string $instructions): array
    {
        $instructions = $instructions !== null && trim($instructions) !== '' ? trim($instructions) : null;
        $em = $this->emProvider->getEntityManager();
        $setting = $this->repository()->findSetting();
        if ($setting === null) {
            $setting = new AiCreditSetting();
            $em->persist($setting);
        }
        $before = $setting->getAssistantInstructions();
        $setting->setAssistantInstructions($instructions);
        $em->flush();

        return [$before, $instructions];
    }

    private function repository(): AiCreditSettingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiCreditSetting::class);
    }
}
