<?php

namespace App\Services\OrderService;

use App\Services\TenantEntityManagerProvider;

/**
 * Catégories fiscales sans taxe (F18 partie 2a) : un produit « reduced » sur un site dont aucune ligne de la table `tax` ne vise
 * cette catégorie et dont toutes les taxes portent une catégorie ne serait pas taxé du tout. Alerte affichée dans EasyAdmin
 * (liste des taxes, fiche produit). Une taxe sans catégorie couvre toutes les catégories non exonérées : aucune alerte alors.
 */
class TaxCategoryAudit
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly OrderTaxAddressResolver $storeAddress)
    {
    }

    /** @return list<string> catégories (hors exempt) portées par au moins un produit vendable et qu'aucune taxe de la table ne frappe */
    public function orphanCategories(): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection(); // SQL direct : état réel de la table, sans le cache d'identité Doctrine
        // Seules les taxes du lieu de vente comptent (celles que le devis de caisse applique) ; sans adresse de magasin, toutes
        $address = TaxEngine::normalizeAddress($this->storeAddress->forStore());
        $rows = $conn->fetchAllAssociative('SELECT country, province, category FROM tax');
        $taxCategories = array_column(array_filter($rows, fn ($r) => $address === null || TaxEngine::matchesAddress($r['country'], $r['province'], $address)), 'category');
        if ($taxCategories === []) {
            return []; // site sans taxe du tout : rien à signaler ici (aucun produit n'est taxé, catégorie ou pas)
        }
        $used = array_map('strval', $conn->fetchFirstColumn("SELECT DISTINCT tax_category FROM product WHERE tax_category IS NOT NULL AND tax_category <> 'exempt' ORDER BY 1"));
        $orphans = [];
        foreach ($used as $category) {
            $covered = false;
            foreach ($taxCategories as $taxCategory) {
                if (TaxCategory::applies($taxCategory !== null ? (string) $taxCategory : null, $category)) { // null = taxe sans catégorie : couvre tout
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $orphans[] = $category;
            }
        }

        return $orphans;
    }

    /** Message d'alerte EasyAdmin, null si tout est couvert */
    public function warning(): ?string
    {
        $orphans = $this->orphanCategories();
        if ($orphans === []) {
            return null;
        }

        return sprintf('Attention : des produits portent la catégorie fiscale %s mais aucune taxe de ce site ne la frappe : ils sont vendus sans taxe. Ajoutez une ligne de taxe avec cette catégorie, ou une taxe sans catégorie.',
            implode(', ', array_map(fn ($c) => '« ' . (TaxCategory::LABELS[$c] ?? $c) . ' »', $orphans)));
    }
}
