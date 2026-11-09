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

        $seeder = $container->get(GestionDemoSeeder::class);
        $report = $seeder->seed();
        $this->assertNotEmpty($report);
        $this->assertContains('collection « ' . GestionDemoSeeder::COLLECTION_CLOSED . ' » figée avec ses commandes (tableaux de bord conservés)', $report);

        $em->clear();
        $closed = $em->getRepository(Collections::class)->findOneBy(['nomCollection' => GestionDemoSeeder::COLLECTION_CLOSED]);
        $open = $em->getRepository(Collections::class)->findOneBy(['nomCollection' => GestionDemoSeeder::COLLECTION_OPEN]);
        $this->assertTrue($closed->getIsClosed());
        $this->assertFalse($open->getIsClosed());
        $this->assertSame([1200000, 2500000], [$closed->getBudgetCollection(), $open->getBudgetCollection()], 'budgets en cents');
        $commandes = $em->getRepository(Commande::class)->findBy(['name' => ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes']]);
        $this->assertCount(3, $commandes);
        foreach ($commandes as $commande) {
            $this->assertNotNull($commande->getFraisDePort(), $commande->getName() . ' : frais de port');
            $this->assertNotNull($commande->getFournisseur());
        }
        $attached = $em->getRepository(Product::class)->createQueryBuilder('p')->where('p.commande IS NOT NULL')->getQuery()->getResult();
        $this->assertGreaterThanOrEqual(min($freeBefore, 14), count($attached), 'les produits existants sont répartis sur les commandes');
        foreach ($attached as $product) {
            $this->assertNotNull($product->getCommandeCostCents(), 'coût d\'achat posé au rattachement');
            $this->assertNotNull($product->getCoefficientMultiplier(), 'coefficient posé pour la valeur de stock');
        }
        $this->assertGreaterThanOrEqual(6, count($em->getRepository(\App\Entity\NoteDeFrais::class)->findBy(['Collection' => [$closed, $open]])));
        $this->assertTrue($em->getRepository(Commande::class)->findOneBy(['name' => 'Commande textile printemps'])->getIsClosed(), 'les commandes d\'une collection figée sont figées avec elle');
        $this->assertGreaterThanOrEqual(8, count($em->getRepository(Commande::class)->findBy(['name' => ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes', 'Collection hiver — manteaux', 'Accessoires d\'hiver', 'Chaussures et sacs', 'Maillots et serviettes', 'Location nautique — matériel']])), 'huit commandes sur quatre collections');
        $this->assertGreaterThanOrEqual(GestionDemoSeeder::MIN_PRODUCTS, count($attached), 'produits de démonstration créés et rattachés');

        // Rejouable : rien n'est recréé
        $this->assertSame([], $seeder->seed(), 'second passage : rien à ajouter');
        $em->clear();
        $this->assertCount(3, $em->getRepository(Commande::class)->findBy(['name' => ['Commande textile printemps', 'Réassort automne', 'Accessoires des fêtes']]));

        // Le use case refuse tout site autre que demo
        $this->expectException(\InvalidArgumentException::class);
        $container->get(SeedGestionDemoUseCase::class)->execute(MV_TEST_TENANT_CODE);
    }
}
