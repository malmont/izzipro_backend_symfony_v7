<?php

namespace App\Services\LandingPageSettingsService;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use App\Services\LandingConfigService\LandingConfigStore;

/**
 * Validation des compositions « réglables » (schemaVersion 2) des landing pages, à l'écriture uniquement.
 *
 * 1. JSON Schema 2020-12 du frontend (config/landingpage/landingpage-reglable.schema.json, voir le README voisin) ;
 * 2. règles que le schéma ne peut pas exprimer : identifiants uniques, parents existants de type container,
 *    pas de boucle, profondeur 8 au plus, x + w et y + h ≤ 100, parenthèses équilibrées des dégradés.
 *
 * Les données doivent être décodées en objets (json_decode sans tableau associatif) : un {} décodé en tableau PHP
 * deviendrait [] et serait refusé à tort. Rien n'est corrigé : la composition est acceptée ou refusée telle quelle.
 */
final class ReglableCompositionValidator
{
    public const MAX_DEPTH = 8;
    private const MAX_ERRORS = 50;

    private ?object $schema = null;
    /** Fichier du schéma chargé dans $schema */
    private ?string $loadedFrom = null;
    /** Schéma imposé (contrôle d'une version candidate), sinon celui de la version active */
    private ?string $schemaFile = null;

    public function __construct(private readonly LandingConfigStore $configStore)
    {
    }

    /** Validateur utilisant le schéma de $schemaFile (version candidate d'une synchronisation) */
    public function withSchemaFile(string $schemaFile): self
    {
        $validator = new self($this->configStore);
        $validator->schemaFile = $schemaFile;

        return $validator;
    }

    /**
     * Valide toutes les compositions d'une configuration de landing page.
     *
     * @return list<array{path: string, message: string}> vide si tout est valide
     */
    public function validateConfiguration(mixed $configuration): array
    {
        $errors = [];
        foreach ($this->compositions($configuration) as $path => $composition) {
            array_push($errors, ...$this->validateComposition($composition, $path));
        }

        return $errors;
    }

    /**
     * Emplacements des compositions : sections typeReglable des onglets, navbar, footer et modèles enregistrés.
     * Une composition absente (ou null) sur une section réglable est acceptée : le frontend prend ses valeurs par défaut.
     *
     * @return array<string, mixed> chemin => composition
     */
    public function compositions(mixed $configuration): array
    {
        $found = [];
        if (!is_object($configuration)) {
            return $found;
        }
        foreach (is_array($configuration->tabs ?? null) ? $configuration->tabs : [] as $t => $tab) {
            foreach (is_object($tab) && is_array($tab->sections ?? null) ? $tab->sections : [] as $s => $section) {
                if (is_object($section) && ($section->componentTypeKey ?? null) === 'typeReglable' && isset($section->reglableConfig)) {
                    $found["tabs[$t].sections[$s].reglableConfig"] = $section->reglableConfig;
                }
            }
        }
        foreach (['navbar', 'footer'] as $key) {
            if (is_object($configuration->$key ?? null) && isset($configuration->$key->reglableConfig)) {
                $found["$key.reglableConfig"] = $configuration->$key->reglableConfig;
            }
        }
        foreach (is_array($configuration->reglablePresets ?? null) ? $configuration->reglablePresets : [] as $p => $preset) {
            if (is_object($preset) && property_exists($preset, 'config')) {
                $found["reglablePresets[$p].config"] = $preset->config;
            }
        }

        return $found;
    }

    /** @return list<array{path: string, message: string}> */
    public function validateComposition(mixed $composition, string $prefix = ''): array
    {
        $errors = [];
        $validator = new Validator(null, self::MAX_ERRORS, false);
        $result = $validator->validate($composition, $this->schema());
        if (!$result->isValid()) {
            $this->collectSchemaErrors($result->error(), $prefix, $errors);
        }

        if (is_object($composition) && is_array($composition->blocks ?? null)) {
            array_push($errors, ...$this->validateBlockTree($composition->blocks, $this->join($prefix, 'blocks')));
        }
        if (is_object($composition) || is_array($composition)) {
            $this->validateGradients($composition, $prefix, $errors);
            $this->validateRichText($composition, $prefix, $errors);
        }

        return $this->unique($errors);
    }

    /**
     * HTML de toutes les chaînes de la composition (textes des blocs, traductions, légendes d'images, titres de liens,
     * séparateurs…) : liste blanche de RichTextPolicy, celle du frontend. Les valeurs sans « < » (URL, couleurs,
     * identifiants) passent sans effet.
     *
     * @param list<array{path: string, message: string}> $errors
     */
    private function validateRichText(object|array $node, string $path, array &$errors): void
    {
        foreach ($node as $key => $value) {
            $childPath = is_int($key) ? $path . "[$key]" : $this->join($path, (string) $key);
            if (is_string($value)) {
                foreach (RichTextPolicy::problems($value) as $problem) {
                    $errors[] = ['path' => $childPath, 'message' => $problem];
                }
            } elseif (is_object($value) || is_array($value)) {
                $this->validateRichText($value, $childPath, $errors);
            }
        }
    }

    /** @return list<array{path: string, message: string}> */
    private function validateBlockTree(array $blocks, string $prefix): array
    {
        $errors = [];
        $byId = [];
        foreach ($blocks as $i => $block) {
            if (!is_object($block) || !is_string($block->id ?? null)) {
                continue; // forme déjà signalée par le schéma
            }
            if (isset($byId[$block->id])) {
                $errors[] = ['path' => "{$prefix}[$i].id", 'message' => sprintf('identifiant « %s » en double (déjà utilisé par blocks[%d])', $block->id, $byId[$block->id])];
                continue;
            }
            $byId[$block->id] = $i;
        }

        foreach ($blocks as $i => $block) {
            if (!is_object($block)) {
                continue;
            }
            foreach (['x' => 'w', 'y' => 'h'] as $pos => $size) {
                if (is_numeric($block->$pos ?? null) && is_numeric($block->$size ?? null) && $block->$pos + $block->$size > 100 + 1e-9) {
                    $errors[] = ['path' => "{$prefix}[$i].$pos", 'message' => "$pos + $size dépasse 100 % de la zone du parent"];
                }
            }

            if (!property_exists($block, 'parentId') || $block->parentId === null || !is_string($block->parentId)) {
                continue; // absent, racine, ou type refusé par le schéma
            }
            $parentIndex = $byId[$block->parentId] ?? null;
            if ($parentIndex === null) {
                $errors[] = ['path' => "{$prefix}[$i].parentId", 'message' => sprintf('parent « %s » introuvable dans la composition', $block->parentId)];
                continue;
            }
            if (($blocks[$parentIndex]->type ?? null) !== 'container') {
                $errors[] = ['path' => "{$prefix}[$i].parentId", 'message' => sprintf('le parent « %s » n\'est pas un bloc container', $block->parentId)];
                continue;
            }

            // Remontée des ancêtres : boucle ou profondeur excessive
            $seen = [$block->id ?? "#$i" => true];
            $depth = 0;
            $current = $block->parentId;
            while (is_string($current) && isset($byId[$current])) {
                if (isset($seen[$current])) {
                    $errors[] = ['path' => "{$prefix}[$i].parentId", 'message' => 'boucle de parents : le bloc est son propre ancêtre'];
                    continue 2;
                }
                $seen[$current] = true;
                if (++$depth > self::MAX_DEPTH) {
                    $errors[] = ['path' => "{$prefix}[$i].parentId", 'message' => sprintf('profondeur maximale dépassée : %d niveaux de parents au plus', self::MAX_DEPTH)];
                    continue 2;
                }
                $current = $blocks[$byId[$current]]->parentId ?? null;
            }
        }

        return $errors;
    }

    /** Dégradés CSS : parenthèses équilibrées (le reste est dans le schéma) */
    private function validateGradients(object|array $node, string $path, array &$errors): void
    {
        foreach ($node as $key => $value) {
            $childPath = is_int($key) ? "{$path}[$key]" : $this->join($path, (string) $key);
            if (is_object($value) || is_array($value)) {
                $this->validateGradients($value, $childPath, $errors);
            } elseif (is_string($value) && preg_match('/^(repeating-)?(linear|radial|conic)-gradient\(/i', $value) && !$this->balanced($value)) {
                $errors[] = ['path' => $childPath, 'message' => 'dégradé invalide : parenthèses non équilibrées'];
            }
        }
    }

    private function balanced(string $value): bool
    {
        $open = 0;
        foreach (str_split($value) as $char) {
            $open += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($open < 0) {
                return false;
            }
        }

        return $open === 0;
    }

    /** Erreurs du schéma ramenées aux feuilles, au format de chemin du frontend */
    private function collectSchemaErrors(ValidationError $error, string $prefix, array &$errors): void
    {
        $keyword = $error->keyword();
        if ($error->subErrors() && !in_array($keyword, ['anyOf', 'oneOf', 'not', 'contains'], true)) {
            foreach ($error->subErrors() as $sub) {
                $this->collectSchemaErrors($sub, $prefix, $errors);
            }

            return;
        }

        $path = $this->join($prefix, $this->formatPath($error->data()->fullPath()));

        // Propriété réservée à certains types de blocs : { if: type hors liste, then: { not: { required: [propriété] } } }
        $forbidden = $keyword === 'not' ? ($error->schema()->info()->data()->not->required ?? null) : null;
        if (is_array($forbidden) && count($forbidden) === 1) {
            $type = $error->data()->value()->type ?? null;
            $errors[] = [
                'path' => $this->join($path, (string) $forbidden[0]),
                'message' => is_string($type) ? sprintf('propriété non autorisée sur un bloc « %s »', $type) : 'propriété non autorisée pour ce type de bloc',
            ];

            return;
        }

        if ($keyword === 'additionalProperties' && ($error->schema()->info()->data()->additionalProperties ?? null) === false) {
            // opis compte comme « en trop » toutes les clés d'un objet qui a une autre erreur : on recalcule
            // les clés réellement absentes de « properties » (les autres erreurs sont signalées à part)
            $declared = get_object_vars($error->schema()->info()->data()->properties ?? new \stdClass());
            foreach (array_keys(get_object_vars((object) $error->data()->value())) as $property) {
                if (!array_key_exists($property, $declared)) {
                    $errors[] = ['path' => $this->join($path, (string) $property), 'message' => 'propriété inconnue'];
                }
            }

            return;
        }

        $errors[] = ['path' => $path, 'message' => $this->message($error)];
    }

    private function message(ValidationError $error): string
    {
        $args = $error->args();
        if ($error->data()->value() === null) {
            return 'valeur null refusée (omettre la clé pour la valeur par défaut)';
        }

        return match ($error->keyword()) {
            'type' => sprintf('type attendu : %s', $this->list($args['expected'] ?? '?')),
            'enum' => 'valeur non autorisée',
            'const' => sprintf('valeur attendue : %s', json_encode($args['const'] ?? null, JSON_UNESCAPED_UNICODE)),
            'minimum' => sprintf('valeur minimale : %s', $args['min'] ?? '?'),
            'maximum' => sprintf('valeur maximale : %s', $args['max'] ?? '?'),
            'exclusiveMinimum' => sprintf('doit être supérieur à %s', $args['min'] ?? '?'),
            'exclusiveMaximum' => sprintf('doit être inférieur à %s', $args['max'] ?? '?'),
            'multipleOf' => 'doit être un nombre entier ou un multiple autorisé',
            'minLength' => sprintf('%s caractère(s) au moins', $args['min'] ?? '?'),
            'maxLength' => sprintf('%s caractères au plus', $args['max'] ?? '?'),
            'pattern' => 'format non autorisé',
            'format' => sprintf('format %s attendu', $args['format'] ?? ''),
            'required' => sprintf('propriété obligatoire manquante : %s', $this->list($args['missing'] ?? '?')),
            'maxItems' => sprintf('%s éléments au plus', $args['max'] ?? '?'),
            'minItems' => sprintf('%s élément(s) au moins', $args['min'] ?? '?'),
            'maxProperties' => sprintf('%s entrées au plus', $args['max'] ?? '?'),
            'propertyNames' => 'nom de clé non autorisé',
            'anyOf', 'oneOf' => 'valeur non autorisée',
            'not', 'false' => 'valeur interdite',
            default => (new ErrorFormatter())->formatErrorMessage($error),
        };
    }

    private function formatPath(array $segments): string
    {
        $path = '';
        foreach ($segments as $segment) {
            $path = is_int($segment) ? "{$path}[$segment]" : $this->join($path, (string) $segment);
        }

        return $path;
    }

    private function join(string $prefix, string $suffix): string
    {
        if ($suffix === '') {
            return $prefix;
        }
        if ($prefix === '' || str_starts_with($suffix, '[')) {
            return $prefix . $suffix;
        }

        return "$prefix.$suffix";
    }

    private function list(mixed $value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /** @return list<array{path: string, message: string}> */
    private function unique(array $errors): array
    {
        $seen = [];
        foreach ($errors as $error) {
            $seen[$error['path'] . "\0" . $error['message']] = $error;
        }

        return array_values($seen);
    }

    private function schema(): object
    {
        $path = $this->schemaFile ?? $this->configStore->path(LandingConfigStore::SCHEMA);
        if ($this->schema === null || $this->loadedFrom !== $path) {
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('Schéma des compositions réglables introuvable : %s (voir config/landingpage/README.md)', $path));
            }
            $this->schema = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            $this->translatePatterns($this->schema);
            $this->loadedFrom = $path;
        }

        return $this->schema;
    }

    /**
     * Les « pattern » JSON Schema sont des expressions ECMA-262 (JavaScript) ; opis les passe telles quelles à PCRE,
     * qui ne connaît pas \uXXXX. Traduction en mémoire (le fichier reste la copie exacte du frontend) : \uXXXX → \x{XXXX}.
     */
    private function translatePatterns(object|array $node): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'pattern' && is_string($value)) {
                $translated = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u([0-9a-fA-F]{4})/', '$1\\x{$2}', $value);
                is_object($node) ? $node->$key = $translated : null;
            } elseif (is_object($value) || is_array($value)) {
                $this->translatePatterns($value);
            }
        }
    }
}
