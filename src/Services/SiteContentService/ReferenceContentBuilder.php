<?php

namespace App\Services\SiteContentService;

use Doctrine\DBAL\Connection;

/**
 * Écrit le contenu d'exemple défini dans config/site-content/reference.php dans la base du site courant (le site de
 * référence, gmasuite), fiches marquées is_demo. Une ressource déjà remplie sur ce site est laissée telle quelle
 * (« ignorée ») : la commande se relance sans doublon. Couleurs et tailles sont reprises par leur code.
 */
final class ReferenceContentBuilder
{
    private const LOCALES = ['fr', 'en'];
    /** Clés d'une fiche qui ne sont pas des colonnes */
    private const META = ['fr', 'en', 'presentations', 'categories', 'variants', 'pictures', 'combinations', 'promotion'];

    private Connection $db;
    private SqlRowWriter $writer;
    /** @var array<string, array<string, int>> ressource => clé de la définition => identifiant */
    private array $keys = [];

    /**
     * @return array{created: array<string, int>, skipped: list<string>}
     */
    public function build(Connection $db, array $definition): array
    {
        $this->db = $db;
        $this->writer = new SqlRowWriter($db);
        $this->keys = [];
        $created = [];
        $skipped = [];

        $db->transactional(function () use ($definition, &$created, &$skipped): void {
            foreach (['presentations', 'presentation-groups', 'baniere-statiques', 'bannieres', 'videos', 'service-offers', 'marques', 'explore-cards', 'category', 'products', 'homeslider', 'features'] as $resource) {
                if ((int) $this->db->fetchOne(sprintf('SELECT COUNT(*) FROM %s', SiteContentSpec::RESOURCES[$resource]['table'])) > 0) {
                    $skipped[] = $resource;
                    continue;
                }
                match ($resource) {
                    'presentation-groups' => $this->groups($definition['presentation-groups'] ?? []),
                    'marques' => $this->marques($definition['marque-categories'] ?? [], $definition['marques'] ?? []),
                    'category' => $this->each($resource, $definition['categories'] ?? [], fn () => ['is_rental_category' => false, 'sync_web' => false, 'is_visible' => true]),
                    'products' => $this->products($definition),
                    'features' => $this->each($resource, $definition['features'] ?? [], fn (array $item, int $i) => ['position' => $i]),
                    default => $this->each($resource, $definition[$resource] ?? []),
                };
                $created[$resource] = count($this->keys[$resource] ?? []);
            }
        });

        return ['created' => array_filter($created), 'skipped' => $skipped];
    }

    /** Fiches simples (avec traductions) ; $extra : colonnes ajoutées (fiche, rang) */
    private function each(string $resource, array $items, ?callable $extra = null): void
    {
        $i = 0;
        foreach ($items as $key => $item) {
            $this->keys[$resource][$key] = $this->translated($resource, $item, $extra ? $extra($item, $i) : []);
            $i++;
        }
    }

    /** Fiche et ses traductions : français et colonnes communes sur la fiche (puis $extra, valeurs par défaut) ; traduction = colonnes communes qu'elle a + langue */
    private function translated(string $resource, array $item, array $extra = []): int
    {
        $spec = SiteContentSpec::RESOURCES[$resource];
        $common = array_diff_key($item, array_flip(self::META));
        $id = $this->writer->insert($spec['table'], ['is_demo' => true] + ($item['fr'] ?? []) + $common + $extra); // $extra : valeurs par défaut
        if ($spec['translation'] !== null) {
            [$table, $column, $language] = $spec['translation'];
            foreach (self::LOCALES as $locale) {
                if (isset($item[$locale])) {
                    $this->writer->insert($table, [$column => $id, $language => $locale] + $item[$locale] + $common);
                }
            }
        }

        return $id;
    }

    private function groups(array $groups): void
    {
        $presentations = $this->keys['presentations'] ?? [];
        foreach ($groups as $key => $group) {
            $ids = array_values(array_filter(array_map(fn ($k) => $presentations[$k] ?? null, $group['presentations'] ?? [])));
            $id = $this->translated('presentation-groups', $group, ['presentation_order' => json_encode($ids)]);
            foreach ($ids as $presentation) {
                $this->writer->link('presentation_group_presentation', ['presentation_group_id' => $id, 'presentation_id' => $presentation]);
            }
            $this->keys['presentation-groups'][$key] = $id;
        }
    }

    private function marques(array $categories, array $marques): void
    {
        $categoryIds = [];
        foreach ($categories as $key => $category) {
            $name = $category['fr']['nom'];
            $id = $this->db->fetchOne('SELECT id FROM categorie_marque WHERE nom = ? ORDER BY id LIMIT 1', [$name]);
            if ($id === false) {
                $id = $this->writer->insert('categorie_marque', ['nom' => $name]);
                foreach (self::LOCALES as $locale) {
                    $this->writer->insert('categorie_marque_translation', ['categorie_marque_id' => $id, 'language' => $locale, 'nom' => $category[$locale]['nom']]);
                }
            }
            $categoryIds[$key] = (int) $id;
        }
        foreach ($marques as $key => $marque) {
            $id = $this->translated('marques', $marque);
            foreach ($marque['categories'] ?? [] as $category) {
                $this->writer->link('marque_categorie_marque', ['marque_id' => $id, 'categorie_marque_id' => $categoryIds[$category]]);
            }
            $this->keys['marques'][$key] = $id;
        }
    }

    private function products(array $definition): void
    {
        $colors = $this->referentials('color', $definition['colors'] ?? [], fn ($code, $c) => ['name' => $c['fr'], 'code' => $code, 'code_hexa' => $c['code_hexa'] ?? null]);
        $sizes = $this->referentials('size', $definition['sizes'] ?? [], fn ($code, $s) => ['name' => $s['fr'], 'code' => $code]);
        $values = $this->options($definition['options'] ?? []);
        $categories = $this->keys['category'] ?? [];
        $now = new \DateTimeImmutable();

        foreach ($definition['products'] ?? [] as $key => $product) {
            $variants = $product['variants'] ?? [[null, null, 0, null]];
            $columns = [
                'mode' => 'retail', 'product_type' => 'standard', 'sale_enabled' => true, 'rental_enabled' => false,
                'subscription_enabled' => false, 'customizable' => false, 'is_web' => true, 'is_pos' => false,
                'is_pre_order' => false, 'isbestseller' => false, 'isnewarrival' => false, 'isfeatured' => false,
                'isspecialoffer' => false, 'rating_count' => 0, 'created_at' => $now->format('Y-m-d H:i:s'),
                'quantity' => array_sum(array_column($variants, 2)),
            ];
            if (!empty($product['promotion'])) {
                // Promotion datée : du premier jour du mois de la construction à un an plus tard
                $from = $now->modify('first day of this month')->setTime(0, 0);
                $columns += ['special_price_from' => $from->format('Y-m-d H:i:s'), 'special_price_to' => $from->modify('+1 year -1 minute')->format('Y-m-d H:i:s')];
            }
            $id = $this->translated('products', $product, $columns);
            $this->keys['products'][$key] = $id;

            foreach ($product['pictures'] ?? [] as $picture) {
                $this->writer->insert('product_picture', ['product_id' => $id, 'image_url' => $picture]);
            }
            foreach ($product['categories'] ?? [] as $category) {
                $this->writer->link('product_categories', ['product_id' => $id, 'categories_id' => $categories[$category]]);
            }
            $variantIds = [];
            foreach ($variants as [$color, $size, $stock, $price]) {
                $variantIds[] = $this->writer->insert('product_variant', [
                    'product_id' => $id, 'color_id' => $color === null ? null : $colors[$color], 'size_id' => $size === null ? null : $sizes[$size],
                    'stock_quantity' => $stock, 'price' => $price,
                ]);
            }
            foreach ($product['combinations'] ?? [] as [$combinationValues, $image, $stock]) {
                $combination = $this->writer->insert('product_customization_image', ['product_variant_id' => $variantIds[0], 'image_path' => $image, 'number_of_pieces' => $stock]);
                foreach ($combinationValues as $value) {
                    $this->writer->link('product_customization_image_product_option_value', ['product_customization_image_id' => $combination, 'product_option_value_id' => $values[$value]]);
                }
            }
        }
    }

    /** Options de personnalisation et leurs valeurs ; retourne code de valeur => identifiant */
    private function options(array $options): array
    {
        $values = [];
        foreach ($options as $code => $option) {
            $id = $this->writer->insert('product_option', ['name' => $option['fr'], 'code' => $code, 'is_demo' => true]);
            foreach (self::LOCALES as $locale) {
                $this->writer->insert('product_option_translation', ['product_option_id' => $id, 'language' => $locale, 'name' => $option[$locale]]);
            }
            foreach ($option['values'] as $valueCode => [$fr, $en, $delta, $preview]) {
                $values[$valueCode] = $this->writer->insert('product_option_value', ['product_option_id' => $id, 'value' => $fr, 'code' => $valueCode, 'image_preview' => $preview, 'price_delta' => (string) $delta]);
                foreach (['fr' => $fr, 'en' => $en] as $locale => $label) {
                    $this->writer->insert('product_option_value_translation', ['product_option_value_id' => $values[$valueCode], 'language' => $locale, 'value' => $label]);
                }
            }
            $this->keys['customization-options'][$code] = $id;
        }

        return $values;
    }

    /** Couleurs ou tailles : reprises par leur code, créées sinon (avec traductions) ; retourne code => identifiant */
    private function referentials(string $table, array $items, callable $row): array
    {
        $ids = [];
        foreach ($items as $code => $item) {
            $id = $this->db->fetchOne(sprintf('SELECT id FROM %s WHERE code = ? ORDER BY id LIMIT 1', $table), [$code]);
            if ($id === false) {
                $id = $this->writer->insert($table, $row($code, $item));
                foreach (self::LOCALES as $locale) {
                    $this->writer->insert($table . '_translation', [$table . '_id' => $id, 'language' => $locale, 'name' => $item[$locale]]);
                }
            }
            $ids[$code] = (int) $id;
        }

        return $ids;
    }
}
