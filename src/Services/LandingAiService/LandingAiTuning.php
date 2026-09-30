<?php

namespace App\Services\LandingAiService;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages de coût et de qualité de l'assistant, par nature de demande. Vides par défaut : l'API applique alors
 * l'effort par défaut du modèle et le cache de 5 minutes. À ne changer qu'après un passage de
 * app:landingpage-ai:eval (voir config/landingpage/README.md).
 */
final class LandingAiTuning
{
    public const KINDS = ['edit', 'create', 'page', 'images'];
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];
    public const CACHE_TTLS = ['5m', '1h'];

    /** @var array<string, ?string> */
    private array $efforts;

    public function __construct(
        #[Autowire('%env(default::LANDING_AI_EFFORT_EDIT)%')] ?string $effortEdit = null,
        #[Autowire('%env(default::LANDING_AI_EFFORT_CREATE)%')] ?string $effortCreate = null,
        #[Autowire('%env(default::LANDING_AI_EFFORT_PAGE)%')] ?string $effortPage = null,
        #[Autowire('%env(default::LANDING_AI_EFFORT_IMAGES)%')] ?string $effortImages = null,
        #[Autowire('%env(default::LANDING_AI_CACHE_TTL_PAGE)%')] private readonly ?string $cacheTtlPage = null
    ) {
        $this->efforts = ['edit' => $effortEdit, 'create' => $effortCreate, 'page' => $effortPage, 'images' => $effortImages];
    }

    /** Nature d'une demande : page, ou images dès qu'une retouche ou une création en porte */
    public static function kind(string $mode, bool $withImages): string
    {
        return $mode === 'page' ? 'page' : ($withImages ? 'images' : $mode);
    }

    /** Effort à envoyer (output_config.effort), ou null pour celui par défaut du modèle ; valeur inconnue ignorée */
    public function effort(string $kind): ?string
    {
        $effort = strtolower(trim((string) ($this->efforts[$kind] ?? '')));

        return in_array($effort, self::EFFORTS, true) ? $effort : null;
    }

    /**
     * Durée du cache du contexte fixe : seulement pour les demandes du modèle page (page, images), dont le contexte
     * est gros et les demandes espacées ; null = 5 minutes (défaut de l'API).
     */
    public function cacheTtl(string $kind): ?string
    {
        $ttl = strtolower(trim((string) $this->cacheTtlPage));

        return in_array($kind, ['page', 'images'], true) && $ttl === '1h' ? '1h' : null;
    }

    /** @return array<string, mixed> réglages effectifs, pour les rapports */
    public function describe(): array
    {
        $efforts = [];
        foreach (self::KINDS as $kind) {
            $efforts[$kind] = $this->effort($kind) ?? 'défaut du modèle';
        }

        return ['effort' => $efforts, 'cacheTtlPage' => $this->cacheTtl('page') ?? '5m'];
    }
}
