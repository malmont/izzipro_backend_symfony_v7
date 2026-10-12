<?php

namespace App\Services\SiteContentService;

use App\Entity\CheckoutSession;
use App\Services\TenantConnectionProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Fiches d'exemple encore présentes sur le site courant (is_demo) : liste et suppression.
 *
 * Suppression : refusée en bloc (409, rien n'est supprimé) si un produit d'exemple sert ailleurs (commande, paiement
 * en cours, réservation, formule d'abonnement, avis, véhicule, produit lié, livraison). Une fiche d'exemple utilisée
 * par une fiche du client (présentation dans un groupe du client, catégorie d'un produit du client, option d'une
 * variante du client) est gardée et devient définitive (isDemo: false). Les fichiers d'images ne sont jamais effacés
 * (images communes « exemple-* », voir SiteContentCopier).
 */
final class SiteDemoContentService
{
    /** Tables qui empêchent la suppression d'un produit : table => [colonne du produit, libellé] */
    private const PRODUCT_USES = [
        'booking' => ['product_id', 'réservation'],
        'booking_configuration' => ['product_id', 'configuration de réservation'],
        'product_shipping' => ['product_id', 'livraison'],
        'related_product' => ['product_id', 'produit lié'],
        'vehicle' => ['product_id', 'véhicule'],
        'vehicle_product' => ['id', 'véhicule'],
        'subscription_plan' => ['product_id', 'formule d\'abonnement'],
        'reviews_product' => ['product_reviews_id', 'avis'],
    ];

    public function __construct(private readonly TenantConnectionProvider $connections)
    {
    }

    /** @return list<array{resource: string, id: int, label: ?string, fields: list<string>}> */
    public function list(string $locale): array
    {
        $db = $this->connections->getConnection();
        $items = [];
        foreach (SiteContentSpec::RESOURCES as $resource => $spec) {
            foreach ($this->demoRows($db, $resource, $locale) as $row) {
                $items[] = ['resource' => $resource, 'id' => (int) $row['id'], 'label' => $row['label'], 'fields' => $spec['fields']];
            }
        }

        return $items;
    }

    /**
     * @return array{deleted: array<string, int>, kept: list<array{resource: string, id: int, label: ?string, reason: string}>}
     * @throws SiteContentException 409 (liste des produits utilisés)
     */
    public function delete(string $locale): array
    {
        $db = $this->connections->getConnection();
        $ids = [];
        $labels = [];
        foreach (array_keys(SiteContentSpec::RESOURCES) as $resource) {
            foreach ($this->demoRows($db, $resource, $locale) as $row) {
                $ids[$resource][] = (int) $row['id'];
                $labels[$resource][(int) $row['id']] = $row['label'];
            }
        }
        $used = $this->usedProducts($db, $ids['products'] ?? []);
        if ($used !== []) {
            $details = array_map(fn ($id) => ['resource' => 'products', 'id' => $id, 'label' => $labels['products'][$id] ?? null, 'usedBy' => $used[$id]], array_keys($used));
            throw new SiteContentException(409, 'Des produits d\'exemple servent déjà (commande, paiement en cours, réservation, formule, avis…) : rien n\'a été supprimé. Modifiez-les pour les garder, ou retirez ces usages.', $details);
        }

        $kept = [];
        $deleted = [];
        $db->transactional(function (Connection $db) use (&$ids, $labels, &$kept, &$deleted): void {
            $keep = function (string $resource, array $keepIds, string $reason) use ($db, &$ids, $labels, &$kept): void {
                if ($keepIds === []) {
                    return;
                }
                $db->executeStatement(sprintf('UPDATE %s SET is_demo = false WHERE id IN (?)', SiteContentSpec::RESOURCES[$resource]['table']), [$keepIds], [ArrayParameterType::INTEGER]);
                $ids[$resource] = array_values(array_diff($ids[$resource], $keepIds));
                foreach ($keepIds as $id) {
                    $kept[] = ['resource' => $resource, 'id' => $id, 'label' => $labels[$resource][$id] ?? null, 'reason' => $reason];
                }
            };
            // Fiches d'exemple utilisées par des fiches du client : gardées
            $keep('presentations', $this->ints($db, 'SELECT DISTINCT l.presentation_id FROM presentation_group_presentation l JOIN presentation_group g ON g.id = l.presentation_group_id WHERE NOT g.is_demo AND l.presentation_id IN (?)', $ids['presentations'] ?? []), 'dans un groupe de présentations du site');
            $keep('category', $this->ints($db, 'SELECT DISTINCT l.categories_id FROM product_categories l JOIN product p ON p.id = l.product_id WHERE NOT p.is_demo AND l.categories_id IN (?)', $ids['category'] ?? []), 'catégorie de produits du site');
            $keep('customization-options', $this->ints($db, 'SELECT DISTINCT v.product_option_id FROM product_option_value v WHERE v.product_option_id IN (?) AND ('
                . 'EXISTS (SELECT 1 FROM product_variant_product_option_value l JOIN product_variant pv ON pv.id = l.product_variant_id JOIN product p ON p.id = pv.product_id WHERE l.product_option_value_id = v.id AND NOT p.is_demo)'
                . ' OR EXISTS (SELECT 1 FROM product_customization_image_product_option_value l JOIN product_customization_image c ON c.id = l.product_customization_image_id JOIN product_variant pv ON pv.id = c.product_variant_id JOIN product p ON p.id = pv.product_id WHERE l.product_option_value_id = v.id AND NOT p.is_demo))', $ids['customization-options'] ?? []), 'option d\'un produit du site');

            if ($products = $ids['products'] ?? []) {
                $variants = $this->ints($db, 'SELECT id FROM product_variant WHERE product_id IN (?)', $products);
                if ($variants !== []) {
                    $this->deleteIn($db, 'product_customization_image', 'product_variant_id', $variants);
                    $this->deleteIn($db, 'inventory_movements', 'product_variant_id', $variants);
                    $this->deleteIn($db, 'product_variant', 'id', $variants);
                }
                $this->deleteIn($db, 'product_picture', 'product_id', $products);
            }
            if ($options = $ids['customization-options'] ?? []) {
                $values = $this->ints($db, 'SELECT id FROM product_option_value WHERE product_option_id IN (?)', $options);
                $this->deleteIn($db, 'product_option_value_translation', 'product_option_value_id', $values);
                $this->deleteIn($db, 'product_option_value', 'id', $values);
            }
            // Groupes avant leurs présentations ; tables de liaison supprimées en cascade
            foreach (['presentation-groups', 'presentations', 'baniere-statiques', 'bannieres', 'videos', 'service-offers', 'marques', 'explore-cards', 'products', 'category', 'customization-options', 'homeslider', 'features'] as $resource) {
                $resourceIds = $ids[$resource] ?? [];
                if ($resourceIds === []) {
                    continue;
                }
                $spec = SiteContentSpec::RESOURCES[$resource];
                if ($spec['translation'] !== null) {
                    $this->deleteIn($db, $spec['translation'][0], $spec['translation'][1], $resourceIds);
                }
                $deleted[$resource] = $this->deleteIn($db, $spec['table'], 'id', $resourceIds);
            }
        });

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /** @return array<int, list<string>> produit => usages */
    private function usedProducts(Connection $db, array $products): array
    {
        if ($products === []) {
            return [];
        }
        $used = [];
        foreach ($this->ints($db, 'SELECT DISTINCT v.product_id FROM order_items i JOIN product_variant v ON v.id = i.product_variant_id WHERE v.product_id IN (?)', $products) as $id) {
            $used[$id][] = 'commande';
        }
        foreach (self::PRODUCT_USES as $table => [$column, $label]) {
            foreach ($this->ints($db, sprintf('SELECT DISTINCT %s FROM %s WHERE %s IN (?)', $column, $table, $column), $products) as $id) {
                $used[$id][] = $label;
            }
        }
        $variants = $db->fetchAllKeyValue('SELECT id, product_id FROM product_variant WHERE product_id IN (?)', [$products], [ArrayParameterType::INTEGER]);
        if ($variants !== []) {
            // Limite de mot : \y pour PostgreSQL, \b pour PHP
            $pattern = '"productVariantId"\s*:\s*"?(' . implode('|', array_keys($variants)) . ')';
            $carts = $db->fetchFirstColumn('SELECT cart::text FROM checkout_session WHERE status IN (?, ?) AND cart::text ~ ?', [CheckoutSession::STATUS_OPEN, CheckoutSession::STATUS_PROCESSING, $pattern . '\y']);
            foreach ($carts as $cart) {
                preg_match_all('/' . $pattern . '\b/', (string) $cart, $matches);
                foreach (array_unique($matches[1]) as $variant) {
                    $used[(int) $variants[(int) $variant]][] = 'paiement en cours';
                }
            }
        }

        return array_map(fn ($uses) => array_values(array_unique($uses)), $used);
    }

    /** @return list<array{id: int, label: ?string}> fiches d'exemple d'une ressource, libellé dans la langue demandée */
    private function demoRows(Connection $db, string $resource, string $locale): array
    {
        $spec = SiteContentSpec::RESOURCES[$resource];
        if ($spec['translation'] === null) {
            return $db->fetchAllAssociative(sprintf('SELECT id, %s AS label FROM %s WHERE is_demo ORDER BY id', $spec['label'], $spec['table']));
        }
        [$table, $column, $language, $label] = $spec['translation'];

        return $db->fetchAllAssociative(sprintf(
            'SELECT DISTINCT ON (t.id) t.id, COALESCE(NULLIF(tr.%s, \'\'), t.%s) AS label FROM %s t LEFT JOIN %s tr ON tr.%s = t.id AND tr.%s = ? WHERE t.is_demo ORDER BY t.id, tr.id',
            $label, $spec['label'], $spec['table'], $table, $column, $language
        ), [$locale]);
    }

    private function ints(Connection $db, string $sql, array $ids): array
    {
        return $ids === [] ? [] : array_map('intval', $db->fetchFirstColumn($sql, [$ids], [ArrayParameterType::INTEGER]));
    }

    private function deleteIn(Connection $db, string $table, string $column, array $ids): int
    {
        return $ids === [] ? 0 : (int) $db->executeStatement(sprintf('DELETE FROM %s WHERE %s IN (?)', $table, $column), [$ids], [ArrayParameterType::INTEGER]);
    }
}
