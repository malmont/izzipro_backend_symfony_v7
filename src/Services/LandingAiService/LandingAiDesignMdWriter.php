<?php

namespace App\Services\LandingAiService;

use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\Services\TenantConnectionProvider;

/**
 * DESIGN.md d'un site (15/10/2026) : la charte du site en texte, au format répandu des fichiers DESIGN.md lus par les
 * agents de conception (couleurs par rôle, typographie, formes, mouvement). Écrit sans IA, depuis la charte
 * enregistrée de la boutique (quand elle existe) et ce que les compositions enregistrées montrent (palette par
 * emploi, polices, arrondis). Il se relit dans l'assistant (champ designMd) ou dans un autre outil. Lecture seule.
 */
final class LandingAiDesignMdWriter
{
    public function __construct(
        private readonly LandingAiSiteContext $site,
        private readonly LandingAiCharterExtractor $charters,
        private readonly BoutiqueSettingsService $boutique,
        private readonly ReglableCompositionValidator $validator,
        private readonly TenantConnectionProvider $tenantProvider
    ) {
    }

    /** @param string $app landingpage ou boutique */
    public function write(string $app): string
    {
        $charter = [];
        if ($app === 'boutique') {
            $raw = $this->boutique->getRawConfiguration($this->boutique->findOrCreateSettings());
            $configuration = $raw !== null ? json_decode($raw, false) : null;
            $compositions = is_object($configuration) ? array_values(array_filter($this->validator->compositions($configuration), 'is_object')) : [];
            $charter = is_object($configuration) && is_object($configuration->charter ?? null) ? (array) $this->charters->fromToolInput($configuration->charter) : [];
        } else {
            $compositions = $this->site->storedCompositions();
        }
        $charter = $this->charters->complete($charter ?: null, $compositions);
        $palette = $this->site->palette($compositions);
        $name = (string) ($this->tenantProvider->getTenantCode() ?? 'site');

        $lines = [
            sprintf('# DESIGN.md — %s (%s)', $name, $app === 'boutique' ? 'boutique' : 'landing page'),
            '',
            sprintf('Charte du site telle qu\'enregistrée le %s, écrite par l\'éditeur Izzipro. Les couleurs sont données par rôle ; les', date('d/m/Y')),
            'polices sont celles chargées par le site. Ce fichier se relit dans l\'assistant IA de l\'éditeur (charte en texte) ou dans un autre outil.',
            '',
            '## Couleurs',
            '',
            '| Rôle | Valeur |',
            '|---|---|',
        ];
        foreach ([['Fond', 'backgroundColor'], ['Texte', 'textColor'], ['Principale (boutons pleins, liens)', 'primaryColor'], ['Accent (détails, badges, traits)', 'accentColor']] as [$label, $key]) {
            $lines[] = sprintf('| %s | %s |', $label, isset($charter[$key]) ? '`' . $charter[$key] . '`' : '(non réglée)');
        }
        if ($palette['backgrounds'] !== [] || $palette['texts'] !== []) {
            $lines[] = '';
            $lines[] = 'Palette observée dans les compositions, de la plus à la moins employée :';
            $lines[] = '';
            if ($palette['backgrounds'] !== []) {
                $lines[] = '- fonds : ' . implode(', ', array_map(fn ($c) => "`$c`", array_keys($palette['backgrounds'])));
            }
            if ($palette['texts'] !== []) {
                $lines[] = '- textes : ' . implode(', ', array_map(fn ($c) => "`$c`", array_keys($palette['texts'])));
            }
        }
        $lines[] = '';
        $lines[] = '## Typographie';
        $lines[] = '';
        $lines[] = sprintf('- Titres : %s', $charter['headingFont'] ?? '(non réglée)');
        $lines[] = sprintf('- Texte : %s', $charter['bodyFont'] ?? '(non réglée)');
        if ($palette['fonts'] !== []) {
            $lines[] = '- Polices employées : ' . implode(', ', array_keys($palette['fonts']));
        }
        $lines[] = '';
        $lines[] = '## Formes';
        $lines[] = '';
        $lines[] = sprintf('- Arrondi des boutons : %s', isset($charter['radius']) ? $charter['radius'] . ' px' : '(non réglé)');
        $lines[] = sprintf('- Style des boutons : %s', match ($charter['buttonStyle'] ?? null) { 'pill' => 'pilule', 'outline' => 'contour', 'solid' => 'plein', default => '(non réglé)' });
        $lines[] = '';
        $lines[] = '## Mouvement';
        $lines[] = '';
        $lines[] = '- ' . $this->motion($compositions);
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param list<object> $compositions */
    private function motion(array $compositions): string
    {
        $effects = [];
        foreach ($compositions as $composition) {
            foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $block) {
                foreach (['animation', 'hover'] as $key) {
                    if (is_object($block) && is_string($block->$key ?? null)) {
                        $effects[$key . ' ' . $block->$key] = ($effects[$key . ' ' . $block->$key] ?? 0) + 1;
                    }
                }
            }
        }
        if ($effects === []) {
            return 'Aucune animation ni effet au survol : rendu sobre.';
        }
        arsort($effects);

        return 'Effets employés : ' . implode(', ', array_keys(array_slice($effects, 0, 6, true))) . '.';
    }
}
