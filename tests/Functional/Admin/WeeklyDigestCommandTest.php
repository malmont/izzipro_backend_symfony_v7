<?php

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Services\Notification\NotificationWeeklyService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Résumé de la semaine lancé à la main (07/11/2026, repris au lot NOTIF-2) : le point se construit depuis le cheminement, les commandes
 * à traiter, les contrôles et les crédits ; il part à chaque administrateur du site, et non plus à l'adresse de contact de la fiche.
 */
class WeeklyDigestCommandTest extends WebTestCase
{
    public function testDigestIsBuiltAndSentToTheAdministrators(): void
    {
        static::createClient();
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $db = $em->getConnection();
        $email = 'admin-hebdo-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $admin = (new User())->setEmail($email)->setUsername($email)->setFirstname('Hélène')->setLastname('Hebdo')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $em->persist($admin);
        $em->flush();
        $contact = (string) $db->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1');

        $digest = $container->get(NotificationWeeklyService::class)->digest();
        foreach (['siteName', 'blocking', 'blockingTodo', 'ordersTodo', 'orders', 'problems', 'creditsRemaining', 'hasSomething'] as $k) {
            $this->assertArrayHasKey($k, $digest);
        }

        try {
            $command = (new Application(static::$kernel))->find('app:site:weekly-digest');
            // Sans --force, hors du lundi matin du magasin, rien ne part ; un essai à blanc n'envoie ni n'écrit rien
            $dry = new CommandTester($command);
            $dry->execute(['--tenant' => MV_TEST_TENANT_CODE, '--always' => true, '--dry-run' => true]);
            $this->assertStringContainsString('essai : rien n\'est parti', $dry->getDisplay());
            $this->assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'weekly_digest' AND recipient = ?", [$email]));

            $tester = new CommandTester($command);
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--always' => true, '--force' => true]);
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $mine = array_values(array_filter($this->getMailerMessages(), fn ($m) => $m->getTo()[0]->getAddress() === $email));
            $this->assertCount(1, $mine, $tester->getDisplay());
            $this->assertStringContainsString('point de la semaine', (string) $mine[0]->getSubject());
            $this->assertStringContainsString('Ouvrir mon back-office', (string) $mine[0]->getHtmlBody());
            if ($contact !== '' && $db->fetchOne('SELECT 1 FROM "user" WHERE LOWER(email) = LOWER(?)', [$contact]) === false) {
                $this->assertSame([], array_filter($this->getMailerMessages(), fn ($m) => $m->getTo()[0]->getAddress() === $contact), 'l\'adresse de contact sans compte ne reçoit plus le point');
            }
            $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'weekly_digest' AND status = 'sent' AND recipient = ?", [$email]));

            // Relancé dans la même semaine : cet administrateur n'en reçoit pas un second
            $again = new CommandTester($command);
            $again->execute(['--tenant' => MV_TEST_TENANT_CODE, '--always' => true, '--force' => true]);
            $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'weekly_digest' AND recipient = ?", [$email]));
        } finally {
            $db->executeStatement("DELETE FROM notification_log WHERE channel = 'email' AND kind = 'weekly_digest'");
        }
    }
}
