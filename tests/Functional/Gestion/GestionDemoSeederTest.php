<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Product;
use App\Services\GestionService\GestionDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\GestionUseCase\SeedGestionDemoUseCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Jeu de données de gestion du site de démonstration (`app:gestion:seed-demo`, `docs/gestion-mise-a-niveau.md` § 8) : joué
 * ici sur le site de test (le use case, lui, refuse tout site autre que demo) ; rejouable sans doublon ; produits existants
 * rattachés avec un coût d'achat et un coefficient, collection de printemps figée.
 */
class GestionDemoSeederTest extends KernelTestCase
{
    public function testSeedIsIdempotentAndAttachesExistingProducts(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        // trois produits de boutique (le modèle de la base de test n'en a aucun), comme les produits existants d'un site
        $suffix = bin2hex(random_bytes(3));
        foreach ([['Chandail démo', 4999], ['Tuque démo', 2499], ['Sac démo', 8900]] as $i => [$name, $price]) {
            $em->getConnection()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, :p, false, 12, NOW(), :s, :t)",
                ['n' => $name . ' ' . $suffix, 'p' => $price, 's' => 'demo-' . $i . '-' . $suffix, 't' => 'standard']);
        }
        $freeBefore = count($em->getRepository(Product::class)->findBy(['commande' => null]));
        // semis d'avant P1 : une collection manuelle « Démo gestion — <nom> » avec une note, fusionnée dans la période au semis
        $em->getConnection()->executeStatement("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind) VALUES (nextval('collections_id_seq'), 1, '2026-09-01', '2026-11-30', false, :n, false, 'manual')", ['n' => 'Démo gestion — ' . GestionDemoSeeder::COLLECTION_OPEN]);
        $legacyId = (int) $em->getConnection()->fetchOne('SELECT id FROM collections WHERE nom_collection = :n', ['n' => 'Démo gestion — ' . GestionDemoSeeder::COLLECTION_OPEN]);

        $seeder = $container->get(GestionDemoSeeder::class);
        $report = $seeder->seed();
        $this->assertNotEmpty($report);
        $this->assertContains('période « ' . GestionDemoSeeder::COLLECTION_CLOSED . ' » figée avec ses 2 commande(s) (tableaux de bord recalculés)', $report);

        $this->assertContains('ancienne collection « Démo gestion — ' . GestionDemoSeeder::COLLECTION_OPEN . ' » fusionnée dans la période « ' . GestionDemoSeeder::COLLECTION_OPEN . ' »', $report);
        $em->clear();
        $this->assertNull($em->find(Collections::class, $legacyId), 'ancienne collection manuelle supprimée');
        $closed = $em->getRepository(Collections::class)->findOneBy(['nomCollection' => GestionDemoSeeder::COLLECTION_CLOSED]);
        $open = $em->getRepository(Collections::class)->findOneBy(['nomCollection' => GestionDemoSeeder::COLLECTION_OPEN]);
        $calendar = $container->get(\App\Services\GestionService\PeriodCalendar::class);
        $this->assertSame(['period', 'period', true, false], [$closed->getKind(), $open->getKind(), $calendar->isClosed($closed), $calendar->isClosed($open)], 'périodes du calendrier : état par la date (lot P1)');
        $this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM collection_statistiques WHERE collection_id = ?', [$closed->getId()]), 'période fermée : statistiques figées une fois');
        $this->assertSame([1200000, 2500000], [$closed->getBudgetCollection(), $open->getBudgetCollection()], 'budgets en cents');
        $commandes = $em->getRepository(Commande::class)->findBy(['name' => ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes']]);
        $this->assertCount(3, $commandes);
        foreach ($commandes as $commande) {
            $this->assertNotNull($commande->getFraisDePort(), $commande->getName() . ' : frais de port');
            $this->assertNotNull($commande->getFournisseur());
        }
        // depuis le lot L0, un article acheté = au moins une ligne d'achat (le raccourci commande_id n'est qu'un reflet)
        $attached = $em->getRepository(Product::class)->createQueryBuilder('p')->where('EXISTS (SELECT 1 FROM App\\Entity\\CommandeLine cl WHERE cl.product = p)')->getQuery()->getResult();
        $this->assertGreaterThanOrEqual(min($freeBefore, 14), count($attached), 'les produits existants sont répartis sur les commandes');
        $seederCommandes = ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes', 'Collection hiver — manteaux', 'Accessoires d\'hiver', 'Chaussures et sacs', 'Maillots et serviettes', 'Location nautique — matériel'];
        foreach ($attached as $product) {
            if (!in_array($product->getCommande()?->getName(), $seederCommandes, true)) {
                continue; // base partagée : depuis le lot L0, un article d'un autre test peut avoir un coût inconnu (NULL, jamais le prix de vente)
            }
            $this->assertNotNull($product->getCommandeCostCents(), 'coût d\'achat posé au rattachement');
            $this->assertNotNull($product->getCoefficientMultiplier(), 'coefficient posé pour la valeur de stock');
        }
        $this->assertGreaterThanOrEqual(6, count($em->getRepository(\App\Entity\NoteDeFrais::class)->findBy(['Collection' => [$closed, $open]])));
        $this->assertTrue($em->getRepository(Commande::class)->findOneBy(['name' => 'Commande textile printemps'])->getIsClosed(), 'les commandes d\'une collection figée sont figées avec elle');
        $this->assertGreaterThanOrEqual(8, count($em->getRepository(Commande::class)->findBy(['name' => ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes', 'Collection hiver — manteaux', 'Accessoires d\'hiver', 'Chaussures et sacs', 'Maillots et serviettes', 'Location nautique — matériel']])), 'huit commandes sur quatre collections');
        $this->assertGreaterThanOrEqual(GestionDemoSeeder::MIN_PRODUCTS, count($attached), 'produits de démonstration créés et rattachés');
        // Ventes de démonstration sur les périodes passées (demande de l'application, 10/11/2026) et produits sans coût pour la reprise
        $sales = $em->getRepository(\App\Entity\Order::class)->createQueryBuilder('o')->where('o.reference LIKE :p')->setParameter('p', GestionDemoSeeder::SALE_REFERENCE_PREFIX . '%')->getQuery()->getResult();
        $this->assertGreaterThanOrEqual(18, count($sales), 'ventes de démonstration sur Hiver, Printemps et Été');
        foreach ($sales as $sale) {
            $this->assertGreaterThan(0, count($sale->getOrderItems()), $sale->getReference() . ' : lignes');
        }
        foreach (GestionDemoSeeder::UNCOSTED_PRODUCTS as [$name]) {
            $uncosted = $em->getRepository(Product::class)->findOneBy(['name' => $name]);
            $this->assertNotNull($uncosted, $name);
            $this->assertSame([null, null, true], [$uncosted->getCommande(), $uncosted->getPurchasePrice(), $uncosted->isDemo()], $name . ' : sans coût ni commande, contenu d\'exemple');
        }
        $this->assertSame(12, $em->getRepository(Product::class)->findOneBy(['name' => 'Manteau de laine doublé'])->getQuantity(), 'la variante créée pour la vente garde le stock du produit');
        $this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM period_result WHERE collection_id = ?', [$closed->getId()]), 'résultat de période figé dès le semis');
        // Achats avant ventes (10/11/2026) : aucun article vendu par le semis avant toute commande qui le contient ; lignes datées de leur commande
        $soldBeforeBought = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id JOIN product_variant v ON v.id = oi.product_variant_id'
            . ' WHERE o.reference LIKE ? AND EXISTS (SELECT 1 FROM commande_line l WHERE l.product_id = v.product_id)'
            . ' AND NOT EXISTS (SELECT 1 FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE l.product_id = v.product_id AND CAST(c.date AS date) <= CAST(o.order_date AS date))',
            [GestionDemoSeeder::SALE_REFERENCE_PREFIX . '%']
        );
        $this->assertSame(0, $soldBeforeBought, 'chaque article vendu par le semis a été acheté avant');
        $this->assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE c.name = 'Commande textile printemps' AND l.attached_at > c.date"), 'lignes du semis datées de leur commande');
        $frozenAt = $em->getConnection()->fetchOne('SELECT computed_at FROM period_result WHERE collection_id = ?', [$closed->getId()]);

        // Rejouable : rien n'est recréé ; les périodes fermées sont refigées (statistiques recalculées) à chaque passage
        $em->getConnection()->executeStatement('UPDATE commande SET is_closed = false WHERE name = ?', ['Chaussures et sacs']);
        $em->clear();
        $refreeze = [];
        foreach ([GestionDemoSeeder::COLLECTION_WINTER, GestionDemoSeeder::COLLECTION_CLOSED, GestionDemoSeeder::COLLECTION_SUMMER, GestionDemoSeeder::COLLECTION_OPEN] as $name) {
            if ($calendar->isClosed($em->getRepository(Collections::class)->findOneBy(['nomCollection' => $name]))) {
                $refreeze[] = "période « $name » figée avec ses 2 commande(s) (tableaux de bord recalculés)"; // toutes les périodes passées (par la date du jour)
            }
        }
        $this->assertSame($refreeze, $seeder->seed(), 'second passage : seul le refigeage des périodes fermées');
        $em->clear();
        $this->assertTrue($em->getRepository(Commande::class)->findOneBy(['name' => 'Chaussures et sacs'])->getIsClosed(), 'commande rouverte à la main : refigée');
        $this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM collection_statistiques WHERE collection_id = ?', [$closed->getId()]), 'refigeage : une seule ligne figée conservée');
        $this->assertSame($refreeze, $seeder->seed(), 'troisième passage : idem');
        $this->assertSame($frozenAt, $em->getConnection()->fetchOne('SELECT computed_at FROM period_result WHERE collection_id = ?', [$closed->getId()]), 'résultat de période figé : jamais recalculé au rejeu');
        $em->clear();
        $this->assertCount(3, $em->getRepository(Commande::class)->findBy(['name' => ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes']]));

        // Le use case refuse tout site autre que demo
        $this->expectException(\InvalidArgumentException::class);
        $container->get(SeedGestionDemoUseCase::class)->execute(MV_TEST_TENANT_CODE);
    }
}
