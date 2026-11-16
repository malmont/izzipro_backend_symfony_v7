<?php

namespace App\Services\Storefront;

/**
 * Un ARTICLE VENDABLE (correctif du 16/11/2026, relevé par le propriétaire sur un site dont les prix sont sur l'article) : la RÈGLE DU
 * PANIER. Une ligne de panier vend une variante, et une variante sans prix prend le prix de l'article (`CartQuoteCalculator`) : un article
 * est vendable s'il a une variante dont le prix, ou à défaut celui de l'article, est supérieur à zéro. Les deux formes comptent : prix sur
 * les variantes, prix sur l'article (variantes sans prix) ; un article SANS variante ne s'achète pas, même avec un prix. Une seule règle
 * pour la liste de contrôle d'ouverture, les contrôles du site et le cheminement : la condition SQL ci-dessous, table `product` alias `p`.
 */
final class SellableProduct
{
    public const CONDITION = 'EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND COALESCE(v.price, p.price) > 0)';
}
