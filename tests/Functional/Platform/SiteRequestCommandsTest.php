<?php

namespace App\Tests\Functional\Platform;

use App\Services\Agency\AgencyService;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les commandes du serveur qui déposent une demande de site (16/11/2026, décision du chef de projet) : TYPE-1 au nom de la plateforme,
 * AG-2 au nom d'une agence, par les mêmes services et les mêmes contrôles que leurs routes ; tracées « console du serveur » ; un site
 * existant refusé ; la sortie ne montre jamais l'adresse en clair, ni lien, ni mot de passe.
 */
class SiteRequestCommandsTest extends KernelTestCase
{
    private \PDO $master;
    private string $tag;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testTheTwoCommandsFileARequestThroughTheSameServicesAndNeverPrintTheAddress(): void
    {
        $application = new Application(static::$kernel);
        $email = "commande-{$this->tag}@example.invalid";

        // TYPE-1 : un site déjà existant est refusé, rien n'est déposé
        $platform = new CommandTester($application->find('app:platform:site:request'));
        $this->assertSame(1, $platform->execute(['--type' => 'agency', '--code' => MV_TEST_TENANT_CODE, '--name' => 'Agence', '--admin-email' => $email]));
        $this->assertStringContainsString('PLATFORM_SITE_CODE_TAKEN', $platform->getDisplay());
        // une agence neuve : la demande est déposée, au nom de la console du serveur ; l'adresse lue sur l'entrée standard, jamais affichée
        $platform->setInputs([$email]);
        $this->assertSame(0, $platform->execute(['--type' => 'agency', '--code' => "cmd-agence-{$this->tag}", '--name' => 'Agence d\'essai', '--admin-email' => '-', '--test' => true]), $platform->getDisplay());
        $this->assertStringNotContainsString($email, $platform->getDisplay());
        $this->assertStringContainsString('c***@e***.invalid', $platform->getDisplay());
        $this->assertDoesNotMatchRegularExpression('#https?://|mot de passe#i', $platform->getDisplay());
        $row = $this->master->query("SELECT type, requested_by, admin_email, options FROM platform_site_requests WHERE tenant_code = 'cmd-agence-{$this->tag}'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['agency', 'console du serveur', $email], [$row['type'], $row['requested_by'], $row['admin_email']]);
        $this->assertTrue(json_decode((string) $row['options'], true)['agency']['isTest']);
        // la même demande une seconde fois : refusée (code déjà demandé)
        $this->assertSame(1, $platform->execute(['--type' => 'agency', '--code' => "cmd-agence-{$this->tag}", '--name' => 'Agence d\'essai', '--admin-email' => $email]));

        // AG-2 : agence inconnue refusée ; puis, au nom d'une agence vivante, la demande du site d'un client
        $agencyCommand = new CommandTester($application->find('app:agency:site:request'));
        $this->assertSame(1, $agencyCommand->execute(['--agency' => 'inconnue-' . $this->tag, '--code' => "cmd-client-{$this->tag}", '--name' => 'Client', '--admin-email' => $email]));
        $agencyId = (int) static::getContainer()->get(AgencyService::class)->create(['code' => "cmd-agence-{$this->tag}", 'name' => 'Agence d\'essai', 'homeTenantCode' => MV_TEST_TENANT_CODE, 'test' => true], 'essai')['id'];
        $this->assertSame(1, $agencyCommand->execute(['--agency' => "cmd-agence-{$this->tag}", '--code' => MV_TEST_TENANT2_CODE, '--name' => 'Client', '--admin-email' => $email]), 'un site existant est refusé');
        $this->assertSame(0, $agencyCommand->execute(['--agency' => "cmd-agence-{$this->tag}", '--code' => "cmd-client-{$this->tag}", '--name' => 'Client d\'essai', '--admin-email' => $email]), $agencyCommand->getDisplay());
        $this->assertStringNotContainsString($email, $agencyCommand->getDisplay());
        $stmt = $this->master->prepare('SELECT requested_by FROM agency_site_requests WHERE agency_id = ? AND tenant_code = ?');
        $stmt->execute([$agencyId, "cmd-client-{$this->tag}"]);
        $this->assertSame('console du serveur', $stmt->fetchColumn());
    }

    private function clean(): void
    {
        $this->master->exec("DELETE FROM platform_site_requests WHERE tenant_code LIKE 'cmd-agence-%'");
        $this->master->exec("DELETE FROM agency_site_requests WHERE tenant_code LIKE 'cmd-client-%'");
        $this->master->exec("DELETE FROM agency_events WHERE agency_id IN (SELECT id FROM agencies WHERE code LIKE 'cmd-agence-%')");
        $this->master->exec("DELETE FROM agency_sites WHERE agency_id IN (SELECT id FROM agencies WHERE code LIKE 'cmd-agence-%')");
        $this->master->exec("DELETE FROM agency_plans WHERE agency_id IN (SELECT id FROM agencies WHERE code LIKE 'cmd-agence-%')");
        $this->master->exec("DELETE FROM agencies WHERE code LIKE 'cmd-agence-%'");
    }
}
