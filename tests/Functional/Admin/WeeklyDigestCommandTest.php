<?php

namespace App\Tests\Functional\Admin;

use App\Command\SiteWeeklyDigestCommand;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Point hebdomadaire (07/11/2026) : construit depuis le cheminement, les commandes à traiter, les contrôles et les crédits ; envoyé au courriel de la fiche, rien sans courriel. */
class WeeklyDigestCommandTest extends WebTestCase
{
    public function testDigestIsBuiltAndSent(): void
    {
        static::createClient();
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $db = $provider->getEntityManager()->getConnection();
        $before = $db->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1');
        $email = 'contact-hebdo@example.invalid';
        $db->executeStatement('UPDATE entreprise SET email = ?', [$email]);
        $provider->getEntityManager()->clear();
        try {
            $this->runDigest($container, $provider, $email);
        } finally {
            $db->executeStatement('UPDATE entreprise SET email = ?', [$before]);
            $container->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }
    }

    private function runDigest($container, $provider, string $email): void
    {
        $digest = $container->get(SiteWeeklyDigestCommand::class)->digest(MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB);
        $this->assertNotNull($digest);
        foreach (['to', 'siteName', 'blocking', 'blockingTodo', 'ordersTodo', 'orders', 'problems', 'creditsRemaining', 'hasSomething'] as $k) {
            $this->assertArrayHasKey($k, $digest);
        }
        $this->assertSame($email, $digest['to']);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:site:weekly-digest'));
        $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--always' => true]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('1 courriel(s) envoyé(s)', $tester->getDisplay());
        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        $this->assertSame($email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('point de la semaine', (string) $mail->getSubject());
        $this->assertStringContainsString('Ouvrir mon back-office', (string) $mail->getHtmlBody());
    }
}
