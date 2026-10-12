<?php

namespace App\Services\SiteContentService;

use App\Services\TenantConnectionProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copie du contenu d'exemple du site de référence (base gmasuite, SITE_CONTENT_REFERENCE_DB) dans le site courant,
 * fiche par fiche, avec de nouveaux identifiants : traductions, galerie, variantes, options et combinaisons de
 * personnalisation, présentations des groupes, catégories des marques. Les fiches copiées sont marquées is_demo.
 *
 * - mode « empty » : seulement les ressources vides sur ce site (jamais d'écrasement) ; un second appel ne crée rien ;
 *   un groupe de présentations est copié avec ses présentations, même si le site en a déjà ;
 * - mode « add » : à côté de l'existant (slugs des produits rendus uniques, atouts placés après ceux du site).
 *
 * Images : les fiches de référence pointent vers des fichiers communs « exemple-* » (assets/uploads/<dossier>/), des
 * visuels sans texte ni donnée de client, partagés en lecture par tous les sites : ils ne sont pas recopiés et ne sont
 * jamais effacés. Une image remplacée par le client est un nouveau fichier, propre à son site.
 * Un produit copié n'est lié qu'aux catégories copiées dans le même appel ; couleurs, tailles et unité de vente sont
 * reprises par leur code (ou leur nom) si le site les a déjà, créées sinon.
 */
final class SiteContentCopier
{
    public const DEFAULT_REFERENCE_DB = 'gmasuite';

    private Connection $source;
    private Connection $target;
    /** @var array<string, array<int, int>> ressource => [identifiant de référence => nouvel identifiant] */
    private array $ids = [];
    private SqlRowWriter $writer;
    /** @var array<string, array<int, int>> référentiel (color, size, sale_unit, categorie_marque) => correspondances */
    private array $referentials = [];

    public function __construct(
        private readonly TenantConnectionProvider $connections,
        #[Autowire('%env(default::SITE_CONTENT_REFERENCE_DB)%')]
        private readonly ?string $referenceDb = null
    ) {
    }

    public function referenceDb(): string
    {
        return $this->referenceDb ?: self::DEFAULT_REFERENCE_DB;
    }

    /**
     * @param list<string> $scopes portées (SiteContentSpec::SCOPES)
     * @return array{created: array<string, int>, ids: array<string, array<int, int>>}
     * @throws SiteContentException 409 sur le site de référence lui-même
     */
    public function seed(array $scopes, string $mode): array
    {
        $this->target = $this->connections->getConnection();
        if (($this->target->getParams()['dbname'] ?? null) === $this->referenceDb()) {
            throw new SiteContentException(409, 'Ce site est le site de référence : son contenu d\'exemple se modifie directement.');
        }
        $params = $this->target->getParams();
        unset($params['url']);
        $params['dbname'] = $this->referenceDb();
        $this->source = DriverManager::getConnection($params, $this->target->getConfiguration());
        $this->writer = new SqlRowWriter($this->target);
        $this->ids = $this->referentials = [];

        try {
            $resources = array_values(array_filter(SiteContentSpec::resourcesFor($scopes), fn ($r) => $r !== 'customization-options'));
            // État d'avant la copie : une ressource remplie pendant cet appel (présentations d'un groupe) reste « vide »
            $empty = [];
            foreach ($resources as $resource) {
                $empty[$resource] = (int) $this->target->fetchOne(sprintf('SELECT COUNT(*) FROM %s', SiteContentSpec::RESOURCES[$resource]['table'])) === 0;
            }
            $this->target->transactional(function () use ($resources, $empty, $mode): void {
                foreach ($resources as $resource) {
                    if ($mode === 'empty' && !$empty[$resource]) {
                        continue;
                    }
                    match ($resource) {
                        'presentation-groups' => $this->copyGroups(),
                        'marques' => $this->copyMarques(),
                        'products' => $this->copyProducts(),
                        'features' => $this->copySimple($resource, ['position' => (int) $this->target->fetchOne('SELECT COALESCE(MAX(position) + 1, 0) FROM feature')]),
                        default => $this->copySimple($resource),
                    };
                }
            });
        } finally {
            $this->source->close();
        }
        $ids = array_filter($this->ids);

        return ['created' => array_map('count', $ids), 'ids' => $ids];
    }

    /** Fiches d'une ressource avec leurs traductions ; $offset : ajouté à la colonne position (atouts) */
    private function copySimple(string $resource, array $offset = [], ?array $onlyIds = null): void
    {
        $table = SiteContentSpec::RESOURCES[$resource]['table'];
        $rows = $onlyIds === null ? $this->rows($table) : $this->rowsIn($table, 'id', $onlyIds);
        $map = [];
        foreach ($rows as $row) {
            foreach ($offset as $column => $add) {
                $row[$column] = (int) $row[$column] + $add;
            }
            $map[(int) $row['id']] = $this->insert($table, ['is_demo' => true] + $row);
        }
        $this->ids[$resource] = ($this->ids[$resource] ?? []) + $map;
        $this->copyTranslations($resource, $map);
    }

    /** Groupes de présentations, avec leurs présentations (copiées si elles ne l'ont pas été) et leur ordre */
    private function copyGroups(): void
    {
        $groups = $this->rows('presentation_group');
        $links = $groups === [] ? [] : $this->source->fetchAllAssociative('SELECT * FROM presentation_group_presentation WHERE presentation_group_id IN (?)', [array_column($groups, 'id')], [ArrayParameterType::INTEGER]);
        $missing = array_values(array_diff(array_unique(array_map('intval', array_column($links, 'presentation_id'))), array_keys($this->ids['presentations'] ?? [])));
        if ($missing !== []) {
            $this->copySimple('presentations', [], $missing);
        }
        $presentations = $this->ids['presentations'] ?? [];
        $map = [];
        foreach ($groups as $row) {
            $order = $row['presentation_order'] !== null ? json_decode((string) $row['presentation_order'], true) : null;
            if (is_array($order)) {
                $row['presentation_order'] = json_encode(array_values(array_filter(array_map(fn ($id) => $presentations[(int) $id] ?? null, $order))));
            }
            $map[(int) $row['id']] = $this->insert('presentation_group', ['is_demo' => true] + $row);
        }
        $this->ids['presentation-groups'] = $map;
        $this->copyTranslations('presentation-groups', $map);
        foreach ($links as $link) {
            if (isset($map[(int) $link['presentation_group_id']], $presentations[(int) $link['presentation_id']])) {
                $this->link('presentation_group_presentation', ['presentation_group_id' => $map[(int) $link['presentation_group_id']], 'presentation_id' => $presentations[(int) $link['presentation_id']]]);
            }
        }
    }

    /** Marques et leurs catégories de marques (reprises par leur nom) */
    private function copyMarques(): void
    {
        $this->copySimple('marques');
        $map = $this->ids['marques'];
        if ($map === []) {
            return;
        }
        foreach ($this->rowsIn('marque_categorie_marque', 'marque_id', array_keys($map), false) as $link) {
            $category = $this->referential('categorie_marque', (int) $link['categorie_marque_id'], 'nom', ['categorie_marque_translation', 'categorie_marque_id']);
            $this->link('marque_categorie_marque', ['marque_id' => $map[(int) $link['marque_id']], 'categorie_marque_id' => $category]);
        }
    }

    /** Produits : traductions, galerie, catégories copiées, variantes, options utilisées, combinaisons */
    private function copyProducts(): void
    {
        $map = [];
        foreach ($this->rows('product') as $row) {
            $row['sale_unit_id'] = $row['sale_unit_id'] === null ? null : $this->referential('sale_unit', (int) $row['sale_unit_id'], 'name');
            $row['style_id'] = $row['commande_id'] = $row['product_type_id'] = $row['rating_average'] = null;
            $row['rating_count'] = 0;
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['slug'] = $this->uniqueSlug('product', (string) $row['slug']);
            $map[(int) $row['id']] = $this->insert('product', ['is_demo' => true] + $row);
        }
        $this->ids['products'] = $map;
        if ($map === []) {
            return;
        }
        $this->copyTranslations('products', $map);
        foreach ($this->rowsIn('product_picture', 'product_id', array_keys($map)) as $row) {
            $row['product_id'] = $map[(int) $row['product_id']];
            $this->insert('product_picture', $row);
        }
        $categories = $this->ids['category'] ?? [];
        foreach ($this->rowsIn('product_categories', 'product_id', array_keys($map), false) as $link) {
            if (isset($categories[(int) $link['categories_id']])) {
                $this->link('product_categories', ['product_id' => $map[(int) $link['product_id']], 'categories_id' => $categories[(int) $link['categories_id']]]);
            }
        }

        $variants = [];
        foreach ($this->rowsIn('product_variant', 'product_id', array_keys($map)) as $row) {
            $row['product_id'] = $map[(int) $row['product_id']];
            $row['color_id'] = $row['color_id'] === null ? null : $this->referential('color', (int) $row['color_id'], 'code', ['color_translation', 'color_id']);
            $row['size_id'] = $row['size_id'] === null ? null : $this->referential('size', (int) $row['size_id'], 'code', ['size_translation', 'size_id']);
            $variants[(int) $row['id']] = $this->insert('product_variant', $row);
        }
        if ($variants === []) {
            return;
        }
        $variantValues = $this->rowsIn('product_variant_product_option_value', 'product_variant_id', array_keys($variants), false);
        $combinations = $this->rowsIn('product_customization_image', 'product_variant_id', array_keys($variants));
        $combinationValues = $combinations === [] ? [] : $this->rowsIn('product_customization_image_product_option_value', 'product_customization_image_id', array_column($combinations, 'id'), false);

        // Options de personnalisation utilisées par ces variantes et combinaisons, avec toutes leurs valeurs
        $usedValues = array_unique(array_map('intval', array_merge(array_column($variantValues, 'product_option_value_id'), array_column($combinationValues, 'product_option_value_id'))));
        $values = [];
        if ($usedValues !== []) {
            $optionIds = array_unique(array_map('intval', array_column($this->rowsIn('product_option_value', 'id', $usedValues), 'product_option_id')));
            $this->copySimple('customization-options', [], $optionIds);
            $options = $this->ids['customization-options'];
            foreach ($this->rowsIn('product_option_value', 'product_option_id', $optionIds) as $row) {
                $row['product_option_id'] = $options[(int) $row['product_option_id']];
                $values[(int) $row['id']] = $this->insert('product_option_value', $row);
            }
            foreach ($this->rowsIn('product_option_value_translation', 'product_option_value_id', array_keys($values)) as $row) {
                $row['product_option_value_id'] = $values[(int) $row['product_option_value_id']];
                $this->insert('product_option_value_translation', $row);
            }
        }
        foreach ($variantValues as $link) {
            $this->link('product_variant_product_option_value', ['product_variant_id' => $variants[(int) $link['product_variant_id']], 'product_option_value_id' => $values[(int) $link['product_option_value_id']]]);
        }
        $combinationMap = [];
        foreach ($combinations as $row) {
            $row['product_variant_id'] = $variants[(int) $row['product_variant_id']];
            $combinationMap[(int) $row['id']] = $this->insert('product_customization_image', $row);
        }
        foreach ($combinationValues as $link) {
            $this->link('product_customization_image_product_option_value', ['product_customization_image_id' => $combinationMap[(int) $link['product_customization_image_id']], 'product_option_value_id' => $values[(int) $link['product_option_value_id']]]);
        }
    }

    private function copyTranslations(string $resource, array $map): void
    {
        $translation = SiteContentSpec::RESOURCES[$resource]['translation'];
        if ($translation === null || $map === []) {
            return;
        }
        [$table, $column] = $translation;
        foreach ($this->rowsIn($table, $column, array_keys($map)) as $row) {
            $row[$column] = $map[(int) $row[$column]];
            if ($resource === 'products' && $row['slug'] !== null) {
                $row['slug'] = $this->uniqueSlug('product_translation', (string) $row['slug']);
            }
            $this->insert($table, $row);
        }
    }

    /**
     * Fiche d'un référentiel du site correspondant à celle de la référence (même valeur de $match), créée sinon
     * (avec ses traductions [table, colonne]).
     */
    private function referential(string $table, int $sourceId, string $match, ?array $translation = null): ?int
    {
        if (isset($this->referentials[$table][$sourceId])) {
            return $this->referentials[$table][$sourceId];
        }
        $row = $this->source->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$sourceId]);
        if ($row === false) {
            return null;
        }
        $id = $row[$match] === null ? false : $this->target->fetchOne(sprintf('SELECT id FROM %s WHERE %s = ? ORDER BY id LIMIT 1', $table, $match), [$row[$match]]);
        if ($id === false) {
            $id = $this->insert($table, $row);
            if ($translation !== null) {
                [$translationTable, $column] = $translation;
                foreach ($this->rowsIn($translationTable, $column, [$sourceId]) as $translated) {
                    $this->insert($translationTable, [$column => $id] + $translated);
                }
            }
        }

        return $this->referentials[$table][$sourceId] = (int) $id;
    }

    private function uniqueSlug(string $table, string $slug): string
    {
        $candidate = $slug;
        for ($i = 2; (int) $this->target->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE slug = ?', $table), [$candidate]) > 0; $i++) {
            $candidate = $slug . '-' . $i;
        }

        return $candidate;
    }

    private function rows(string $table): array
    {
        return $this->source->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY id', $table));
    }

    /** Lignes de la référence dont $column est dans $ids ; $ordered : table avec identifiant (sinon table de liaison) */
    private function rowsIn(string $table, string $column, array $ids, bool $ordered = true): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->source->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE %s IN (?)%s', $table, $column, $ordered ? ' ORDER BY id' : ''), [array_values($ids)], [ArrayParameterType::INTEGER]);
    }

    private function insert(string $table, array $row): int
    {
        return $this->writer->insert($table, $row);
    }

    private function link(string $table, array $row): void
    {
        $this->writer->link($table, $row);
    }
}
