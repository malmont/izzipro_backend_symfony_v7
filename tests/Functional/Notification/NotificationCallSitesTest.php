<?php

namespace App\Tests\Functional\Notification;

use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Notification\NotificationCatalog as Catalog;
use App\Services\Notification\NotificationGate;
use App\Services\Notification\NotificationLevelResolver;
use App\Services\Notification\NotificationSummaryService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les niveaux des courriels de suivi, vus depuis de VRAIS points d'appel (lot NOTIF-1, `docs/notifications-courriels.md`) : une
 * réservation prise par un visiteur, le passage horaire qui sort les retenus, une panne de la décision d'envoi, un envoi en échec.
 * Le site de test est remis au niveau « Tout » à la fin de chaque test.
 */
class NotificationCallSitesTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private ?string $contact = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        $this->db()->executeStatement('DELETE FROM notification_preference');
    }

    protected function tearDown(): void
    {
        if ($this->contact !== null) {
            $this->db()->executeStatement('UPDATE entreprise SET email = ?', [$this->contact]);
        }
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'all'");
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        $this->db()->close();
        parent::tearDown();
    }

    public function testABookingIsHeldForTheTeamByTheLevelOfTheSiteAndTheCustomerAlwaysGetsHisEmail(): void
    {
        // Le courriel de l'équipe part à l'adresse de contact de la fiche du site (vide sur le site de test : posée ici, rendue à la fin)
        $team = 'equipe-reservations@mvtest.test';
        $this->contact = (string) $this->db()->fetchOne('SELECT COALESCE(email, \'\') FROM entreprise ORDER BY id LIMIT 1');
        $this->db()->executeStatement('UPDATE entreprise SET email = ?', [$team]);
        static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->clear();
        $book = function (string $customer, int $day): array {
            $this->client->getCookieJar()->clear();
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/reservations', [], [], [
                'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.88.' . random_int(1, 254) . '.' . random_int(1, 254),
            ], json_encode(['service_name' => 'Accompagnement', 'reservation_date' => sprintf('2032-02-%02d', $day), 'reservation_slot' => '09:00 - 12:00',
                'client_name' => 'Cliente Niveau', 'client_email' => $customer, 'client_phone' => '514 555-0101']));
            $this->assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

            return array_map(fn ($m) => mb_strtolower($m->getTo()[0]->getAddress()), $this->getMailerMessages());
        };
        $rows = fn (): array => $this->db()->fetchAllAssociative("SELECT kind, status, topic FROM notification_log WHERE channel = 'email' AND recipient = ? ORDER BY id", [$team]);

        // Site d'essai (niveau « Essentiel ») : le client reçoit son accusé, l'équipe ne reçoit RIEN tout de suite ; son courriel est retenu
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'essential'");
        $to = $book('cliente-essentiel@example.invalid', 10);
        $this->assertContains('cliente-essentiel@example.invalid', $to, 'l\'accusé du client est indispensable : il part');
        $this->assertNotContains($team, $to, 'niveau Essentiel : le courriel de l\'équipe ne part pas');
        $this->assertSame([['kind' => Catalog::BOOKING_NEW, 'status' => 'held', 'topic' => Catalog::TOPIC_BOOKINGS]], $rows());

        // Niveau « Aucune » : le client reçoit toujours son accusé ; pour l'équipe, ni courriel ni ligne
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'none'");
        $to = $book('cliente-aucune@example.invalid', 11);
        $this->assertSame([true, false], [in_array('cliente-aucune@example.invalid', $to, true), in_array($team, $to, true)]);
        $this->assertCount(1, $rows(), 'rien de plus au journal');

        // Niveau « Tout », alors qu'un courriel plus ancien du même sujet attend encore : la nouvelle réservation le rejoint (elle ne
        // passe pas devant), et le passage suivant les sort ENSEMBLE, en un seul courriel
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'all'");
        $to = $book('cliente-tout@example.invalid', 12);
        $this->assertSame([true, false], [in_array('cliente-tout@example.invalid', $to, true), in_array($team, $to, true)]);
        $this->assertSame(['held', 'held'], array_column($rows(), 'status'));
        $before = count($this->getMailerMessages());
        $released = static::getContainer()->get(NotificationSummaryService::class)->releaseDue();
        $this->assertSame([1, 2], [$released['emails'], $released['released']]);
        $mail = $this->getMailerMessages()[$before];
        $this->assertSame([$team, 2], [mb_strtolower($mail->getTo()[0]->getAddress()), substr_count((string) $mail->getHtmlBody(), 'Cliente Niveau')], 'un courriel, deux réservations');
        $this->assertSame(['sent', 'sent', 'sent'], array_column($rows(), 'status'), 'les deux retenus sortis, et la ligne du courriel qui les porte');

        // Plus rien n'attend : la réservation suivante part tout de suite à l'équipe
        $to = $book('cliente-suite@example.invalid', 13);
        $this->assertSame([true, true], [in_array('cliente-suite@example.invalid', $to, true), in_array($team, $to, true)]);
    }

    public function testTheHourlyCommandReleasesTheHeldEmailsOnceAndPurgesTheOldOnes(): void
    {
        $gate = static::getContainer()->get(NotificationGate::class);
        $to = 'equipe-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'normal'");
        $gate->record(Catalog::ORDER_TO_SHIP, $to, null, NotificationGate::STATUS_HELD, 'Commande retenue hier', [], '2020-01-01', NotificationGate::HELD_TOPIC_DONE);
        $gate->record(Catalog::ORDER_TO_SHIP, $to, null, NotificationGate::STATUS_SENT, 'Vieux courriel parti', [], '2020-01-01', null);
        $this->db()->executeStatement("UPDATE notification_log SET sent_at = NOW() - INTERVAL '120 days' WHERE recipient = ?", [$to]);
        $run = function (): string {
            $tester = new CommandTester((new Application(static::$kernel))->find('app:gestion:notifications:envoyer'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);
            static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return $tester->getDisplay();
        };
        $before = count($this->getMailerMessages());
        $this->assertStringContainsString('1 courriel(s) de suivi retenus sortis en 1 courriel(s)', $run());
        $mails = array_slice($this->getMailerMessages(), $before);
        $this->assertCount(1, $mails);
        $this->assertSame([$to, true], [$mails[0]->getTo()[0]->getAddress(), str_contains((string) $mails[0]->getHtmlBody(), 'Commande retenue hier')]);
        // Le retenu est sorti (même vieux : une ligne retenue n'est jamais effacée) ; le vieux courriel déjà parti est effacé
        $this->assertSame(['Commande retenue hier' => 'sent'], array_map('strval', $this->db()->fetchAllKeyValue("SELECT title, status FROM notification_log WHERE recipient = ? AND kind = ?", [$to, Catalog::ORDER_TO_SHIP])));
        // Un second passage ne renvoie rien
        $before = count($this->getMailerMessages());
        $this->assertStringNotContainsString('courriel(s) de suivi retenus', $run());
        $this->assertCount($before, $this->getMailerMessages());
    }

    public function testNothingIsLostWhenTheDecisionBreaksOrTheSummaryCannotBeSent(): void
    {
        $c = static::getContainer();
        $levels = $c->get(NotificationLevelResolver::class);
        // Une décision d'envoi en panne (ici le niveau illisible) : le courriel part quand même, comme avant les niveaux
        $broken = new class($c->get(TenantEntityManagerProvider::class), $c->get(TenantConnectionManager::class), $c->get(\App\Services\Platform\EntitlementService::class), $c->get(\App\Services\Platform\PlatformModeService::class)) extends NotificationLevelResolver {
            public function forAddress(string $email): array
            {
                throw new \RuntimeException('base illisible');
            }
        };
        $gate = new NotificationGate($c->get(TenantEntityManagerProvider::class), $broken, $c->get(StoreClock::class));
        $sent = 0;
        $to = 'panne-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $status = $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande', [], function () use (&$sent): bool {
            ++$sent;

            return true;
        }, new \DateTimeImmutable('2031-09-01 15:00:00', new \DateTimeZone('UTC')));
        $this->assertSame([NotificationGate::STATUS_SENT, 1], [$status, $sent]);

        // Le courriel des retenus ne peut pas partir (envoi refusé) : les lignes restent retenues, et sortent au passage suivant
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'normal'");
        $real = new NotificationGate($c->get(TenantEntityManagerProvider::class), $levels, $c->get(StoreClock::class));
        $real->record(Catalog::ORDER_TO_SHIP, $to, null, NotificationGate::STATUS_HELD, 'Commande en attente', [], '2031-08-30', NotificationGate::HELD_TOPIC_DONE);
        $refusing = $this->createMock(EmailSenderService::class);
        $refusing->method('sendTemplatedEmail')->willReturn(false);
        $summary = fn (EmailSenderService $mailer): NotificationSummaryService => new NotificationSummaryService($c->get(TenantEntityManagerProvider::class), $real, $levels, $c->get(StoreClock::class),
            $mailer, $c->get(EmailLocale::class), $c->get(TranslatorInterface::class), $c->get(AdminSpaceUrl::class), $c->get(SiteName::class),
            $c->get(TenantConnectionManager::class), $c->get(TenantPublicHostResolver::class), new NullLogger());
        $at = new \DateTimeImmutable('2031-09-01 15:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(['ran' => true, 'emails' => 0, 'released' => 0, 'kept' => 0], $summary($refusing)->releaseDue($at));
        $this->assertSame('held', $this->db()->fetchOne("SELECT status FROM notification_log WHERE recipient = ? AND title = 'Commande en attente'", [$to]));
        $this->assertSame(['ran' => true, 'emails' => 1, 'released' => 1, 'kept' => 0], $summary($c->get(EmailSenderService::class))->releaseDue($at));
    }

    public function testTheSiteLevelIsReadOnlyForTheSiteAdministratorInTheRegistry(): void
    {
        $this->db()->executeStatement("UPDATE entreprise SET notification_level = 'normal'");
        $admin = $this->user(['ROLE_ADMIN']);
        $feature = null;
        foreach ($this->decode($this->call('GET', '/api/site/features', null, $admin))['features'] ?? [] as $f) {
            if (($f['key'] ?? null) === 'site.notification_level') {
                $feature = $f;
            }
        }
        $this->assertNotNull($feature, 'site.notification_level est dans le registre');
        $this->assertSame(['normal', 'normal', 'manual'], [$feature['value'], $feature['effective'], $feature['details']['mode'] ?? null]);
        // L'administrateur du site ne peut pas le changer : le niveau du site est réglé par la plateforme
        $r = $this->call('PUT', '/api/site/features', ['values' => ['site.notification_level' => 'all']], $admin);
        $this->assertNotSame(200, $r->getStatusCode(), (string) $r->getContent());
        $this->assertSame('normal', $this->db()->fetchOne('SELECT notification_level FROM entreprise ORDER BY id LIMIT 1'));
    }

    private function user(array $roles): User
    {
        $provider = $this->provider();
        $email = 'appel-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Alix')->setLastname('Appel')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function call(string $method, string $path, ?array $body, ?User $as): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->provider()->getEntityManager()->find(User::class, $as->getId()));
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function provider(): TenantEntityManagerProvider
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->provider()->getEntityManager()->getConnection();
    }
}
