<?php

namespace App\Tests\Functional\Platform;

use App\Entity\User;
use App\Services\Platform\PlatformAlertRecipient;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'adresse qui reçoit les alertes de la plateforme (19/11/2026, demande du propriétaire) : réglage de la page Plateforme d'abord, variable
 * du serveur en secours, sinon rien ; format vérifié ; courriel d'essai 3 par heure ; l'adresse n'est écrite dans aucun journal (seule sa
 * forme masquée) ; écran réservé au super administrateur, jeton CSRF exigé. Les deux usages sont vérifiés dans leurs propres essais
 * (PlatformIncidentTest : courriel du jour ; PosStripeLiveApiTest : alertes de la caisse).
 */
class PlatformAlertRecipientTest extends WebTestCase
{
    private const ADDRESS = 'alertes-plateforme-essai@example.invalid';

    private KernelBrowser $client;
    private \PDO $master;
    /** @var array{0: mixed, 1: mixed} */
    private array $mailer;

    protected function setUp(): void
    {
        // l'envoi réel passe par le compte de la plateforme (MAILER_DSN, lu une fois par conteneur : posé avant le client)
        $this->mailer = [$_ENV['MAILER_DSN'] ?? null, $_SERVER['MAILER_DSN'] ?? null];
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'null://plateforme%40arkanoa.test@default';
        $this->client = static::createClient();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->master->exec("DELETE FROM platform_settings WHERE key = 'platform_alert_email'");
        unset($_ENV['POS_ALERT_PLATFORM_EMAIL'], $_SERVER['POS_ALERT_PLATFORM_EMAIL']);
        static::getContainer()->get('cache.app')->deleteItem('platform_alert_email_tests');
    }

    protected function tearDown(): void
    {
        $this->master->exec("DELETE FROM platform_settings WHERE key = 'platform_alert_email'");
        unset($_ENV['POS_ALERT_PLATFORM_EMAIL'], $_SERVER['POS_ALERT_PLATFORM_EMAIL']);
        [$_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']] = $this->mailer;
        parent::tearDown();
    }

    public function testTheSettingComesFirstThenTheServerVariableThenNothing(): void
    {
        $service = static::getContainer()->get(PlatformAlertRecipient::class);
        $offset = $this->logSize();
        $this->assertNull($service->get(), 'ni réglage ni variable : rien ne part');
        $this->assertNull($service->state()['source']);
        $_ENV['POS_ALERT_PLATFORM_EMAIL'] = 'variable-serveur@example.invalid';
        $this->assertSame('variable-serveur@example.invalid', $service->get(), 'la variable du serveur en secours');
        $this->assertSame(['env', 'v***@example.invalid'], [$service->state()['source'], $service->state()['masked']]);
        $service->set(self::ADDRESS, 'super@example.invalid');
        $this->assertSame(self::ADDRESS, $service->get(), 'le réglage d\'abord');
        $this->assertSame(['setting', self::ADDRESS], [$service->state()['source'], $service->state()['email']]);
        foreach (['pas-une-adresse', 'a@b', str_repeat('x', 175) . '@example.invalid'] as $bad) {
            try {
                $service->set($bad, 'test');
                $this->fail('refusé : ' . $bad);
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(self::ADDRESS, $service->get(), 'un refus ne change rien');
        $service->set('', 'test');
        $this->assertSame('variable-serveur@example.invalid', $service->get(), 'réglage retiré : la variable reprend la main');
        // l'adresse n'est écrite dans aucun journal : seule sa forme masquée
        $service->set(self::ADDRESS, 'test');
        $log = $this->logSince($offset);
        $this->assertStringNotContainsString(self::ADDRESS, $log);
        $this->assertStringContainsString('a***@example.invalid', $log);
    }

    public function testTheTestEmailIsLimitedToThreeAnHour(): void
    {
        $service = static::getContainer()->get(PlatformAlertRecipient::class);
        $offset = $this->logSize();
        try {
            $service->sendTest('test');
            $this->fail('sans adresse : refusé');
        } catch (\DomainException) {
        }
        $service->set(self::ADDRESS, 'test');
        $before = count($this->getMailerMessages());
        for ($i = 1; $i <= PlatformAlertRecipient::TESTS_PER_HOUR; ++$i) {
            $this->assertSame(['sent' => true, 'masked' => 'a***@example.invalid', 'remaining' => PlatformAlertRecipient::TESTS_PER_HOUR - $i], $service->sendTest('test'));
        }
        $sent = array_slice($this->getMailerMessages(), $before);
        $this->assertCount(3, $sent);
        $this->assertSame(self::ADDRESS, $sent[0]->getTo()[0]->getAddress());
        try {
            $service->sendTest('test');
            $this->fail('4e essai de l\'heure : refusé');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('3 courriels', $e->getMessage());
        }
        $log = $this->logSince($offset);
        $this->assertStringContainsString('a***@example.invalid', $log, 'les essais sont journalisés, masqués');
        $this->assertStringNotContainsString(self::ADDRESS, $log);
    }

    public function testTheScreenIsReservedToTheSuperAdminWithAToken(): void
    {
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/alertes';
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->client->request('POST', $url, ['_token' => 'x', 'email' => self::ADDRESS], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'administrateur de site : refusé');
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->client->request('POST', $url, ['_token' => 'faux', 'email' => self::ADDRESS], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertNull(static::getContainer()->get(PlatformAlertRecipient::class)->state()['email'], 'jeton faux : rien n\'est enregistré');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('Adresse qui reçoit les alertes de la plateforme', (string) $this->client->getResponse()->getContent());
        $token = $crawler->filter('form[action$="/admin/plateforme/alertes"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, ['_token' => $token, 'email' => self::ADDRESS], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(self::ADDRESS, static::getContainer()->get(PlatformAlertRecipient::class)->get());
        $this->client->request('POST', $url, ['_token' => $token, 'email' => 'pas-une-adresse'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(self::ADDRESS, static::getContainer()->get(PlatformAlertRecipient::class)->get(), 'format refusé : l\'adresse reste');
    }

    private function logSize(): int
    {
        clearstatcache();
        $file = static::getContainer()->getParameter('kernel.logs_dir') . '/test.log';

        return is_file($file) ? (int) filesize($file) : 0;
    }

    /** Ce que l'essai a écrit au journal depuis `$offset` (le journal entier peut dépasser la mémoire) */
    private function logSince(int $offset): string
    {
        $file = static::getContainer()->getParameter('kernel.logs_dir') . '/test.log';
        clearstatcache();
        $read = @file_get_contents($file, false, null, $offset);
        $this->assertIsString($read, 'journal des essais lisible');

        return $read;
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'alertes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Alertes')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }
}
