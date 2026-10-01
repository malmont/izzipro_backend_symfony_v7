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
    /** Mesuré le 01/10/2026 : sans exemple, mêmes vérifications réussies (11 cas, dont un ajout de bloc), coût −41 % */
    public const DEFAULT_EDIT_EXAMPLES = 0;
    public const DEFAULT_EDIT_EXCLUDE_ORIGIN = true;

    /** @var array<string, ?string> */
    private array $efforts;

    public function __construct(
        #[Autowire('%env(default::LANDING_AI_EFFORT_EDIT)%')] ?string $effortEdit = null,
        #[Autowire('%env(default::LANDING_AI_EFFORT_CREATE)%')] ?string $effortCreate = null,
        #[Autowire('%env(default::LANDING_AI_EFFORT_PAGE)%')] ?string $effortPage = null,
        #[Autowire('%env(default::LANDING_AI_EFFORT_IMAGES)%')] ?string $effortImages = null,
        #[Autowire('%env(default::LANDING_AI_CACHE_TTL_PAGE)%')] private readonly ?string $cacheTtlPage = null,
        #[Autowire('%env(default::LANDING_AI_EDIT_EXAMPLES)%')] private readonly ?string $editExamples = null
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

    /**
     * Exemples de la famille envoyés en retouche : 0 par défaut (la composition envoyée suffit ; le modèle d'origine de
     * la section, presque identique, était envoyé en double). LANDING_AI_EDIT_EXAMPLES = 1 ou 2 pour en remettre,
     * toujours sans le modèle d'origine.
     *
     * @return array{count: int, excludeOrigin: bool}
     */
    public function editExamples(): array
    {
        $value = trim((string) $this->editExamples);

        return in_array($value, ['0', '1', '2'], true)
            ? ['count' => (int) $value, 'excludeOrigin' => true]
            : ['count' => self::DEFAULT_EDIT_EXAMPLES, 'excludeOrigin' => self::DEFAULT_EDIT_EXCLUDE_ORIGIN];
    }

    /** @return array<string, mixed> réglages effectifs, pour les rapports */
    public function describe(): array
    {
        $efforts = [];
        foreach (self::KINDS as $kind) {
            $efforts[$kind] = $this->effort($kind) ?? 'défaut du modèle';
        }

        $examples = $this->editExamples();

        return ['effort' => $efforts, 'cacheTtlPage' => $this->cacheTtl('page') ?? '5m',
            'exemplesRetouche' => $examples['count'] . ($examples['excludeOrigin'] ? ' (sans le modèle d\'origine)' : ' (dont le modèle d\'origine)')];
    }
}
