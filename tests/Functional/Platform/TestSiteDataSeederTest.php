<?php

namespace App\Tests\Functional\Platform;

use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\Essai\TestSiteDataSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Lot ESSAI-DONNÉES (17/11/2026) : un site d'essai se remplit de données FICTIVES par les chemins normaux du serveur (clients
 * `@example.invalid`, commandes créées, expédiées, une annulée, dates reculées sur 60 jours) ; rejouable sans doublon ; aucun courriel ;
 * la commande refuse tout site qui n'est ni demo ni un site d'une agence de test. Les lignes de l'essai sont retirées à la fin.
 */
class TestSiteDataSeederTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        $db = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection();
        $users = "SELECT id FROM \"user\" WHERE email LIKE 'essai-client-%@example.invalid'";
        $orders = "SELECT id FROM \"order\" WHERE user_id_id IN ($users)";
        foreach (["DELETE FROM booking WHERE order_item_id IN (SELECT id FROM order_items WHERE order_associated_id IN ($orders))", "DELETE FROM payments WHERE order_payment_id IN ($orders)", "DELETE FROM order_tax WHERE order_tax_id IN ($orders)",
            "DELETE FROM shipping_order WHERE odershipping_id IN ($orders)", "DELETE FROM order_items WHERE order_associated_id IN ($orders)", "DELETE FROM \"order\" WHERE id IN ($orders)",
            "DELETE FROM user_app_membership WHERE user_id IN ($users)", "UPDATE \"user\" SET primary_address_id = NULL WHERE id IN ($users)", "DELETE FROM adress WHERE user_adress_id IN ($users)",
            "DELETE FROM \"user\" WHERE id IN ($users)"] as $sql) {
            try {
                $db->executeStatement($sql);
            } catch (\Throwable) {
                // une table absente du modèle des tests : rien à retirer
            }
        }
        parent::tearDown();
    }

    public function testATestSiteIsFilledThroughTheNormalPathsAndAReplayAddsNothing(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $provider = $c->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $c->get(BoutiqueDemoSeeder::class)->seed('client-essai-donnees@example.invalid', false, false); // un catalogue vendable, comme sur demo
        $db = $provider->getEntityManager()->getConnection();
        $seeder = $c->get(TestSiteDataSeeder::class);

        $dry = $seeder->seed(true);
        $this->assertStringContainsString('20 à créer', implode(' | ', $dry));
        $this->assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email LIKE 'essai-client-%'"), 'le comptage n\'écrit rien');

        $mails = count($this->getMailerMessages());
        $report = implode(' | ', $seeder->seed());
        $this->assertSame(20, (int) $db->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email LIKE 'essai-client-%@example.invalid' AND roles::text LIKE '%ROLE_USER_INTERNET%'"), $report);
        $orders = "FROM \"order\" o JOIN \"user\" u ON u.id = o.user_id_id WHERE u.email LIKE 'essai-client-%@example.invalid'";
        $count = (int) $db->fetchOne("SELECT COUNT(*) $orders");
        $this->assertGreaterThanOrEqual(10, $count, $report);
        $this->assertGreaterThanOrEqual(1, (int) $db->fetchOne("SELECT COUNT(*) $orders AND o.shipped_at IS NOT NULL"), 'des commandes expédiées : ' . $report);
        $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) $orders AND o.canceled_at IS NOT NULL"), 'une commande annulée : ' . $report);
        $this->assertGreaterThan(30, (int) $db->fetchOne("SELECT EXTRACT(DAY FROM NOW() - MIN(o.order_date)) $orders"), 'dates réparties sur les 60 derniers jours');
        $this->assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) $orders AND o.order_date > NOW() + INTERVAL '1 day'"));
        $this->assertGreaterThan(0, (int) $db->fetchOne("SELECT COUNT(*) FROM payments p JOIN \"order\" o ON o.id = p.order_payment_id JOIN \"user\" u ON u.id = o.user_id_id WHERE u.email LIKE 'essai-client-%' AND p.payment_date < NOW() - INTERVAL '1 day'"), 'paiements reculés avec leur commande');
        $this->assertSame([], array_values(array_filter(array_slice($this->getMailerMessages(), $mails), static fn ($m) => str_contains(implode(',', array_map(static fn ($a) => $a->getAddress(), $m->getTo())), 'essai-client-'))), 'aucun courriel aux clients fictifs');

        // rejouable : rien de plus (commandes et locations comprises)
        $total = (int) $db->fetchOne("SELECT COUNT(*) $orders");
        $seeder->seed();
        $this->assertSame($total, (int) $db->fetchOne("SELECT COUNT(*) $orders"), 'aucune commande ajoutée au rejeu');
        $this->assertSame(20, (int) $db->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email LIKE 'essai-client-%@example.invalid'"));
    }

    public function testTheCommandRefusesASiteThatIsNotATestSite(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:essai:remplir'));
        $this->assertSame(1, $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]), 'le site de test n\'est ni demo ni le site d\'une agence de test');
        $this->assertStringContainsString('refusé', $tester->getDisplay());
        $this->assertSame(1, $tester->execute(['--tenant' => '']));
    }
}
