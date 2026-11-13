<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Fournisseur;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Données des tests de prix de la gestion (lot IA-ACT-5) : un fournisseur, des articles achetés chez lui à une date, leurs ventes et
 * leurs retours. La classe hôte fournit `em()`, `create()` (`CacheTestEntityFactory`) et renseigne `$fixtureUserId` (compte des ventes).
 */
trait PricingFixtures
{
    protected int $fixtureUserId = 0;
    /** @var list<int> ventes créées par `sale()`, retirées par `removePricingFixtures()` */
    protected array $fixtureOrderIds = [];
    /** @var list<int> commandes d'achat créées par `article()`, retirées de même */
    protected array $fixtureCommandeIds = [];
    /** @var list<int> articles créés par `article()` et fournisseurs créés par `supplier()` : retirés quand plus rien ne les référence */
    protected array $fixtureProductIds = [];
    protected array $fixtureSupplierIds = [];

    /**
     * À appeler dans `tearDown()` : les ventes de ces tests sont datées dans la saison de l'an dernier, que d'autres tests de la base
     * partagée lisent (résultat d'une période passée, comparaison « an dernier » des statistiques). Elles sont retirées avec leurs lignes,
     * ainsi que les achats d'historique (commandes et lignes d'achat) ; les fiches des articles restent.
     */
    protected function removePricingFixtures(): void
    {
        $conn = $this->em()->getConnection();
        $ids = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        if ($this->fixtureOrderIds !== []) {
            $conn->executeStatement('DELETE FROM order_items WHERE order_associated_id IN (?)', [$this->fixtureOrderIds], [$ids]);
            $conn->executeStatement('DELETE FROM "order" WHERE id IN (?)', [$this->fixtureOrderIds], [$ids]);
        }
        if ($this->fixtureCommandeIds !== []) {
            $conn->executeStatement('DELETE FROM commande_line WHERE commande_id IN (?)', [$this->fixtureCommandeIds], [$ids]);
            $conn->executeStatement('DELETE FROM commande WHERE id IN (?)', [$this->fixtureCommandeIds], [$ids]);
        }
        // Articles et fournisseurs de ces tests : retirés aussi, pour ne pas laisser d'articles « libres » ni de fournisseurs au nom proche
        // de ceux d'autres tests de la base partagée. Celui qu'une autre donnée référence encore (une commande créée par un fil) reste.
        foreach ($this->fixtureProductIds as $id) {
            try {
                $conn->executeStatement('DELETE FROM product_categories WHERE product_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM product_variant WHERE product_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM product WHERE id = ?', [$id]);
            } catch (\Doctrine\DBAL\Exception) {
                // encore référencé : il reste
            }
        }
        foreach ($this->fixtureSupplierIds as $id) {
            try {
                $conn->executeStatement('DELETE FROM fournisseur WHERE id = ?', [$id]);
            } catch (\Doctrine\DBAL\Exception) {
                // encore référencé par une commande : il reste
            }
        }
        $this->fixtureOrderIds = $this->fixtureCommandeIds = $this->fixtureProductIds = $this->fixtureSupplierIds = [];
    }

    protected function supplier(string $name): int
    {
        return $this->fixtureSupplierIds[] = (int) $this->create(Fournisseur::class, ['name' => $name], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, 1) ?? $this->create(TypeFournisseur::class, ['name' => 'Autre'])))->getId();
    }

    /**
     * Un article acheté chez un fournisseur : fiche, variante, commande datée (figée, comme une commande d'une saison passée) et sa ligne d'achat.
     *
     * @param list<int> $categories
     *
     * @return array{0: int, 1: int} article, variante
     */
    protected function article(int $supplier, string $name, int $cost, int $price, string $boughtOn, int $quantity = 10, array $categories = []): array
    {
        $conn = $this->em()->getConnection();
        $slug = 'sem-' . bin2hex(random_bytes(5));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), ?, ?, false, 0, NOW(), ?, 'standard')", [$name, $price, $slug]);
        $id = $this->fixtureProductIds[] = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = 0, price = NULL WHERE id = ?', [$variant]);
        $commande = (int) $conn->fetchOne("INSERT INTO commande (id, date, name, kind, budget, fournisseur_id, is_closed) VALUES (nextval('commande_id_seq'), ?, 'Semblables', 'standard', 0, ?, true) RETURNING id", [$boughtOn . ' 09:00:00', $supplier]);
        $this->fixtureCommandeIds[] = $commande;
        $conn->executeStatement('INSERT INTO commande_line (commande_id, product_id, quantity_ordered, unit_cost_cents, attached_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())', [$commande, $id, $quantity, $cost]);
        foreach ($categories as $category) {
            $conn->executeStatement('INSERT INTO product_categories (product_id, categories_id) VALUES (?, ?)', [$id, $category]);
        }

        return [$id, $variant];
    }

    /** Une vente (type 1) ou un retour (type 2) à midi, heure du magasin : `$unit` payé par unité, `$catalog` affiché */
    protected function sale(int $variant, int $quantity, int $unit, int $catalog, string $day, int $type = 1): void
    {
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = (new \DateTimeImmutable("$day 12:00", $tz))->setTimezone(new \DateTimeZone('UTC'));
        $order = $this->create(Order::class, ['totalAmount' => (float) ($unit * $quantity)], function (Order $o, EntityManagerInterface $em) use ($at, $type): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at))->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, $type))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->fixtureUserId));
        });
        $this->fixtureOrderIds[] = (int) $order->getId();
        $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unit, 'totalPrice' => (float) ($unit * $quantity), 'catalogUnitPrice' => $catalog],
            fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variant)));
    }
}
