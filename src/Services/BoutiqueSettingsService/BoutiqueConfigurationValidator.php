<?php

namespace App\Services\BoutiqueSettingsService;

use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Contrôle d'une configuration de la boutique réglable (PUT /api/boutique-settings, modèles de site de la boutique),
 * à l'écriture uniquement :
 *
 * 1. les compositions (navbar, footer, sections des onglets, modèles personnels) et les noms de section, par les
 *    règles des landing pages (ReglableCompositionValidator : schéma synchronisé, HTML des textes) ;
 * 2. les pages système (onglets portant « system ») : page connue du catalogue synchronisé (« systemPages »), une
 *    seule page par couple (system, variant), et chacun de ses blocs obligatoires présent dans l'une de ses sections.
 *    Un bloc obligatoire est un type de bloc du schéma (stripePayment, cartLines…) ou l'un des groupes PSEUDO_BLOCKS,
 *    que le catalogue nomme sans qu'ils soient des types : « modeGroup » (container portant « mode ») et
 *    « productList » (container répété sur « products »). Un nom qui n'est ni l'un ni l'autre n'est pas exigé ;
 * 3. la charte (« charter ») et le commerce (« commerce ») : type de chaque clé connue. Les clés inconnues, comme
 *    tout champ hors compositions, sont conservées sans contrôle (le frontend est libre d'en ajouter).
 *
 * Les données doivent être décodées en objets (json_decode sans tableau associatif). Rien n'est corrigé : la
 * configuration est acceptée ou refusée telle quelle.
 */
final class BoutiqueConfigurationValidator
{
    public const BUTTON_STYLES = ['solid', 'outline', 'pill'];
    public const VARIANT_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';
    public const CURRENCY_PATTERN = '/^[A-Z]{3}$/';
    public const FONT_MAX_LENGTH = 100;
    private const RADIUS_PATTERN = '/^\d{1,3}(\.\d+)?(px|rem|em|%)?$/';
    private const CHARTER_COLORS = ['primaryColor', 'accentColor', 'textColor', 'backgroundColor'];
    private const CHARTER_FONTS = ['headingFont', 'bodyFont'];
    private const COMMERCE_FLAGS = ['guestCheckout', 'subscriptionsEnabled'];
    /** Blocs obligatoires du catalogue qui désignent un groupe plutôt qu'un type : nom => reconnaît un bloc */
    private const PSEUDO_BLOCKS = [
        'modeGroup' => [self::class, 'isModeGroup'],
        'productList' => [self::class, 'isProductList'],
    ];

    public function __construct(
        private readonly ReglableCompositionValidator $compositions,
        private readonly LandingAiCatalogue $catalogue
    ) {
    }

    /**
     * @return list<array{path: string, message: string}> vide si tout est valide
     */
    public function validateConfiguration(mixed $configuration): array
    {
        if (!is_object($configuration)) {
            return [['path' => '', 'message' => 'objet attendu (navbar, footer, tabs, reglablePresets, charter, commerce)']];
        }
        $errors = $this->compositions->validateConfiguration($configuration);
        array_push($errors, ...$this->validateSystemPages($configuration));
        array_push($errors, ...$this->validateCharter($configuration->charter ?? null));
        array_push($errors, ...$this->validateCommerce($configuration->commerce ?? null));

        return $errors;
    }

    /** @return list<array{path: string, message: string}> */
    private function validateSystemPages(object $configuration): array
    {
        $pages = $this->catalogue->systemPages();
        $blockTypes = $pages !== [] ? $this->compositions->blockTypes() : [];
        $errors = [];
        $seen = [];
        foreach (is_array($configuration->tabs ?? null) ? $configuration->tabs : [] as $t => $tab) {
            if (!is_object($tab) || !isset($tab->system)) {
                continue;
            }
            if (!is_string($tab->system)) {
                $errors[] = ['path' => "tabs[$t].system", 'message' => 'texte attendu (clé d\'une page système)'];
                continue;
            }
            if ($pages !== [] && !isset($pages[$tab->system])) {
                $errors[] = ['path' => "tabs[$t].system", 'message' => sprintf('page système inconnue (connues : %s)', implode(', ', array_keys($pages)))];
                continue;
            }
            $variant = $tab->variant ?? null;
            if ($variant !== null && (!is_string($variant) || !preg_match(self::VARIANT_PATTERN, $variant))) {
                $errors[] = ['path' => "tabs[$t].variant", 'message' => 'texte de 1 à 40 caractères (lettres, chiffres, _ -) attendu'];
                $variant = null;
            }
            $key = $tab->system . '|' . ($variant ?? '');
            if (isset($seen[$key])) {
                $errors[] = ['path' => "tabs[$t]", 'message' => sprintf('page système « %s »%s déjà définie par tabs[%d]', $tab->system, $variant !== null ? " (variante « $variant »)" : '', $seen[$key])];
            } else {
                $seen[$key] = $t;
            }

            // Blocs obligatoires : types du schéma actif et groupes connus ; le reste n'est pas exigé
            $required = array_values(array_filter($pages[$tab->system]['required'] ?? [], fn ($name) => in_array($name, $blockTypes, true) || isset(self::PSEUDO_BLOCKS[$name])));
            if ($required === []) {
                continue;
            }
            $found = [];
            foreach (is_array($tab->sections ?? null) ? $tab->sections : [] as $section) {
                $blocks = is_object($section) && is_object($section->reglableConfig ?? null) && is_array($section->reglableConfig->blocks ?? null) ? $section->reglableConfig->blocks : [];
                foreach ($blocks as $block) {
                    if (!is_object($block)) {
                        continue;
                    }
                    if (is_string($block->type ?? null)) {
                        $found[$block->type] = true;
                    }
                    foreach (self::PSEUDO_BLOCKS as $name => $matches) {
                        if ($matches($block)) {
                            $found[$name] = true;
                        }
                    }
                }
            }
            $missing = array_values(array_diff($required, array_keys($found)));
            if ($missing) {
                $errors[] = ['path' => "tabs[$t].sections", 'message' => sprintf('page système « %s » : bloc(s) obligatoire(s) manquant(s) : %s', $tab->system, implode(', ', $missing))];
            }
        }

        return $errors;
    }

    /** Groupe d'un mode de vente : container portant « mode » (sale, rental, subscription) */
    private static function isModeGroup(object $block): bool
    {
        return ($block->type ?? null) === 'container' && is_string($block->mode ?? null);
    }

    /** Liste des produits du catalogue : container répété sur la source « products » */
    private static function isProductList(object $block): bool
    {
        return ($block->type ?? null) === 'container' && is_object($block->repeat ?? null) && ($block->repeat->source ?? null) === 'products';
    }

    /** @return list<array{path: string, message: string}> */
    private function validateCharter(mixed $charter): array
    {
        if ($charter === null) {
            return [];
        }
        if (!is_object($charter)) {
            return [['path' => 'charter', 'message' => 'objet attendu']];
        }
        $errors = [];
        foreach (self::CHARTER_COLORS as $key) {
            if (isset($charter->$key) && (!is_string($charter->$key) || !preg_match(LandingContentEditor::COLOR, $charter->$key))) {
                $errors[] = ['path' => "charter.$key", 'message' => 'couleur attendue (#rrggbb, rgb(…), rgba(…) ou transparent)'];
            }
        }
        foreach (self::CHARTER_FONTS as $key) {
            if (isset($charter->$key) && (!is_string($charter->$key) || trim($charter->$key) === '' || mb_strlen($charter->$key) > self::FONT_MAX_LENGTH || preg_match('/[<>]/', $charter->$key))) {
                $errors[] = ['path' => "charter.$key", 'message' => sprintf('nom de police de 1 à %d caractères attendu, sans « < » ni « > »', self::FONT_MAX_LENGTH)];
            }
        }
        if (isset($charter->radius)) {
            $radius = $charter->radius;
            $valid = (is_int($radius) || is_float($radius)) ? ($radius >= 0 && $radius <= 100) : (is_string($radius) && preg_match(self::RADIUS_PATTERN, $radius));
            if (!$valid) {
                $errors[] = ['path' => 'charter.radius', 'message' => 'nombre de 0 à 100 ou taille CSS (px, rem, em, %) attendu'];
            }
        }
        if (isset($charter->buttonStyle) && !in_array($charter->buttonStyle, self::BUTTON_STYLES, true)) {
            $errors[] = ['path' => 'charter.buttonStyle', 'message' => sprintf('valeur attendue : %s', implode(', ', self::BUTTON_STYLES))];
        }

        return $errors;
    }

    /** @return list<array{path: string, message: string}> */
    private function validateCommerce(mixed $commerce): array
    {
        if ($commerce === null) {
            return [];
        }
        if (!is_object($commerce)) {
            return [['path' => 'commerce', 'message' => 'objet attendu']];
        }
        $errors = [];
        foreach (self::COMMERCE_FLAGS as $key) {
            if (isset($commerce->$key) && !is_bool($commerce->$key)) {
                $errors[] = ['path' => "commerce.$key", 'message' => 'booléen attendu'];
            }
        }
        if (isset($commerce->currency) && (!is_string($commerce->currency) || !preg_match(self::CURRENCY_PATTERN, $commerce->currency))) {
            $errors[] = ['path' => 'commerce.currency', 'message' => 'code de devise ISO 4217 attendu (3 lettres majuscules, ex. CAD)'];
        }
        if (isset($commerce->taxProvider) && !in_array($commerce->taxProvider, ['table', 'stripe'], true)) {
            $errors[] = ['path' => 'commerce.taxProvider', 'message' => 'valeur attendue : table ou stripe'];
        }

        return $errors;
    }
}
