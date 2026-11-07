<?php

namespace App\Services\Platform;

use App\Services\TenantEntityManagerProvider;

/**
 * Page « Contrôles » (07/11/2026) : la même logique que le cheminement, mais pour la vie courante du site : ce qui cloche dans les
 * données et qui gêne la vente ou le client. Chaque contrôle compte les cas, dit pourquoi c'est un problème et mène à l'écran où corriger.
 * Lecture seule, requêtes SQL simples, tolérantes (un contrôle illisible compte zéro).
 */
class SiteControlsService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{controls: list<array{key: string, title: string, count: int, severity: string, description: string, screen: ?string}>, problems: int, warnings: int} */
    public function overview(): array
    {
        $c = fn (string $sql) => $this->count($sql);
        $controls = [];
        $add = function (string $key, string $title, int $count, string $severity, string $description, ?string $screen) use (&$controls) {
            $controls[] = ['key' => $key, 'title' => $title, 'count' => $count, 'severity' => $count > 0 ? $severity : 'ok', 'description' => $description, 'screen' => $screen];
        };
        $add('products.no_variant', 'Produits en ligne sans variante ni prix', $c('SELECT COUNT(*) FROM product p WHERE p.is_web = TRUE AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND v.price > 0)'), 'problem', 'Un produit sans variante à prix supérieur à zéro ne peut pas être mis au panier.', 'products');
        $add('products.no_picture', 'Produits en ligne sans photo', $c('SELECT COUNT(*) FROM product p WHERE p.is_web = TRUE AND COALESCE(p.image, \'\') = \'\' AND NOT EXISTS (SELECT 1 FROM product_picture pp WHERE pp.product_id = p.id)'), 'warning', 'Un produit sans image se vend mal ; la page Photos du produit permet d\'en déposer ou d\'en générer.', 'products');
        $add('products.negative_stock', 'Stocks négatifs', $c('SELECT COUNT(*) FROM product_variant WHERE stock_quantity < 0'), 'problem', 'Un stock négatif vient d\'une vente ou d\'un retour mal enregistré : corrigez la quantité de la variante.', 'products');
        $add('products.no_category', 'Produits en ligne sans catégorie', $c('SELECT COUNT(*) FROM product p WHERE p.is_web = TRUE AND NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = p.id)'), 'warning', 'Le client navigue par catégorie : un produit sans catégorie n\'apparaît que par recherche ou lien direct.', 'products');
        $add('categories.empty', 'Catégories visibles sans produit', $c('SELECT COUNT(*) FROM categories c WHERE COALESCE(c.is_visible, TRUE) = TRUE AND NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.categories_id = c.id)'), 'warning', 'Une catégorie vide affiche une page sans rien : masquez-la ou rattachez-lui des produits.', 'categories');
        $add('carriers.zero_price', 'Transporteurs payants à zéro', $c("SELECT COUNT(*) FROM carrier WHERE COALESCE(kind, 'fixed') = 'fixed' AND COALESCE(price, 0) <= 0"), 'warning', 'Un transporteur à prix fixe à 0 $ livre gratuitement sans le dire : donnez-lui un prix, ou faites-en un retrait en magasin.', 'carriers');
        $add('rental.unconfigured', 'Produits louables sans règles ou sans tarif', $c('SELECT COUNT(*) FROM product p WHERE p.rental_enabled = TRUE AND (NOT EXISTS (SELECT 1 FROM booking_configuration bc WHERE bc.product_id = p.id AND bc.granularity IS NOT NULL) OR NOT EXISTS (SELECT 1 FROM product_categories pc JOIN rental_pack_categories rpc ON rpc.categories_id = pc.categories_id WHERE pc.product_id = p.id))'), 'problem', 'Sans règles de stock ou sans pack de location pour sa catégorie, aucun créneau n\'est réservable.', 'rental');
        $add('subscriptions.no_plan', 'Produits en abonnement sans formule active', $c('SELECT COUNT(*) FROM product p WHERE p.subscription_enabled = TRUE AND NOT EXISTS (SELECT 1 FROM subscription_plan sp WHERE sp.product_id = p.id AND sp.active = TRUE)'), 'problem', 'Un produit en abonnement sans formule active ne peut pas être souscrit.', 'subscription_plans');
        $add('reviews.pending', 'Avis clients en attente', $c("SELECT COUNT(*) FROM reviews_product WHERE status = 'pending'"), 'warning', 'Un avis attend votre lecture : publiez ou refusez (motif), jamais selon la note.', 'reviews');
        $add('orders.incomplete', 'Commandes incomplètes depuis plus de 24 h', $c("SELECT COUNT(*) FROM \"order\" WHERE status_id = 1 AND COALESCE(order_source_id, 1) <> 2 AND order_date < NOW() - INTERVAL '24 hours'"), 'warning', 'Paiement jamais finalisé : à annuler si elles s\'accumulent, ou à contacter.', 'orders');
        $add('orders.to_ship', 'Commandes payées non expédiées depuis plus de 3 jours', $c("SELECT COUNT(*) FROM \"order\" WHERE status_id IN (3, 4) AND shipping_adress_id IS NOT NULL AND shipped_at IS NULL AND COALESCE(order_source_id, 1) <> 2 AND order_date < NOW() - INTERVAL '3 days'"), 'problem', 'Le client attend : expédiez ou prévenez-le.', 'orders');
        $add('orders.in_transit', 'Colis en transit depuis plus de 10 jours', $c("SELECT COUNT(*) FROM \"order\" WHERE status_id = 5 AND COALESCE(shipped_at, status_updated_at, order_date) < NOW() - INTERVAL '10 days'"), 'warning', 'Vérifiez le suivi, puis « Marquer livrée » ou contactez le transporteur.', 'orders');
        $add('payments.authorized', 'Paiements autorisés non capturés', $c("SELECT COUNT(DISTINCT p.order_payment_id) FROM payments p JOIN \"order\" o ON o.id = p.order_payment_id WHERE p.amount > 0 AND p.stripe_status = 'requires_capture' AND o.status_id <> 7"), 'problem', 'L\'autorisation expire au bout de 7 jours : capturez depuis la fiche de la commande.', 'orders');
        $add('users.no_email', 'Comptes clients sans courriel valide', $c("SELECT COUNT(*) FROM \"user\" WHERE COALESCE(email, '') = '' OR email NOT LIKE '%@%'"), 'warning', 'Ces clients ne reçoivent ni confirmation ni reçu.', 'users');
        $problems = count(array_filter($controls, fn ($x) => $x['severity'] === 'problem'));
        $warnings = count(array_filter($controls, fn ($x) => $x['severity'] === 'warning'));

        return ['controls' => $controls, 'problems' => $problems, 'warnings' => $warnings];
    }

    private function count(string $sql): int
    {
        try {
            return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne($sql);
        } catch (\Throwable) {
            return 0;
        }
    }
}
