<?php

namespace App\Tests\Functional\Tech;

use App\Entity\User;
use App\Repository\TechMetricRepository;
use App\Services\TechMetricsService\TechCounters;
use App\Services\TechMetricsService\TechDashboardService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran « Tableau de bord technique » (T2, 03/11/2026) : réservé au propriétaire de la plateforme (anonyme renvoyé, administrateur de
 * site 403, entrée de menu invisible pour lui), classement des sites par ressource avec fusion des codes d'une même base, détail d'un
 * site avec ses courbes, indicateurs de la plateforme bon / moyen / mauvais, routes lourdes, seuils modifiables avec validation.
 */
class TechDashboardAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private TechMetricRepository $repository;
    private \PDO $master;
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $hour;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
        $c = static::getContainer();
        $this->repository = $c->get(TechMetricRepository::class);
        $this->master = $c->get(TenantConnectionManager::class)->getPdoMaster();
        $this->master->exec('DELETE FROM tech_metric_sample');
        $this->master->exec("UPDATE tech_threshold SET good = 50, warn = 80 WHERE metric = 'fpm_active_pct'");
        $this->now = TechCounters::now();
        $this->hour = $this->now->setTime((int) $this->now->format('G'), 0);
        $this->seed();
    }

    private function seed(): void
    {
        $h1 = $this->hour->modify('-1 hour');
        $h2 = $this->hour->modify('-2 hours');
        $old = $this->hour->modify('-3 days');
        $point = $this->now->setTime((int) $this->now->format('G'), (int) $this->now->format('i') - ((int) $this->now->format('i') % 5));
        $r = $this->repository;
        $hour = TechMetricRepository::PERIOD_HOUR;
        $pt = TechMetricRepository::PERIOD_POINT;
        // Premier site : 30 requêtes récentes, erreurs, tâches, IA ; second site : 5 requêtes ; une vieille heure hors fenêtre 24 h
        $r->upsert('requests', MV_TEST_TENANT_CODE, '', $hour, $h1, 20);
        $r->upsert('requests', MV_TEST_TENANT_CODE, '', $hour, $h2, 10);
        $r->upsert('request_ms', MV_TEST_TENANT_CODE, '', $hour, $h1, 4000);
        $r->upsert('request_peak_mb', MV_TEST_TENANT_CODE, '', $hour, $h1, 96);
        $r->upsert('errors_5xx', MV_TEST_TENANT_CODE, '', $hour, $h1, 2);
        $r->upsert('jobs', MV_TEST_TENANT_CODE, 'email', $hour, $h1, 3);
        $r->upsert('emails', MV_TEST_TENANT_CODE, '', $hour, $h1, 3);
        $r->upsert('ai_calls', MV_TEST_TENANT_CODE, 'anthropic', $hour, $h1, 4);
        $r->upsert('ai_tokens_in', MV_TEST_TENANT_CODE, 'anthropic', $hour, $h1, 1000);
        $r->upsert('ai_tokens_out', MV_TEST_TENANT_CODE, 'anthropic', $hour, $h1, 200);
        $r->upsert('requests', MV_TEST_TENANT2_CODE, '', $hour, $h1, 5);
        $r->upsert('requests', MV_TEST_TENANT2_CODE, '', $hour, $old, 500);
        $r->upsert('requests', TechCounters::PLATFORM_SITE, '', $hour, $h1, 7);
        $r->upsert('db_size_bytes', MV_TEST_TENANT_CODE, '', $pt, $point, 20 * 1048576);
        $r->upsert('db_size_bytes', MV_TEST_TENANT2_CODE, '', $pt, $point, 30 * 1048576);
        // Plateforme : PHP-FPM moyen (60 %), file vide, disque bon, sauvegarde mauvaise (3 jours), timer bon, Docker, stockage
        $r->upsert('fpm_active', '', '', $pt, $point, 12, ['max_children' => 20, 'max children reached' => 0, 'slow requests' => 0]);
        $r->upsert('fpm_total', '', '', $pt, $point, 14);
        $r->upsert('fpm_active_pct', '', '', $pt, $point, 60);
        $r->upsert('fpm_queue', '', '', $pt, $point, 0);
        $r->upsert('disk_used_pct', '', '/', $pt, $point, 15.9, ['total' => 200_000_000_000, 'used' => 31_800_000_000]);
        $r->upsert('backup_age_s', '', '', $pt, $point, 259200);
        $r->upsert('backup_bytes', '', '', $pt, $point, 150_000_000);
        $r->upsert('backup_db_count', '', '', $pt, $point, 11);
        $r->upsert('timer_last_age_s', '', 'pos-terminal-reconcile.timer', $pt, $point, 120, ['result' => 'success', 'next' => 'bientôt']);
        $r->upsert('timer_last_age_s', '', 'backup-daily.timer', $pt, $point, 90000, ['result' => 'success', 'next' => 'demain']);
        $r->upsert('docker_reclaimable_bytes', '', '', $pt, $point, 5_000_000_000);
        $r->upsert('storage_bytes', '', '', $pt, $point, 600_000_000, ['files' => 1234]);
        // Routes lourdes
        $r->upsert('route_peak_count', '', 'api_memoires_book_generate_pdfs', $hour, $h1, 3);
        $r->upsert('route_peak_mb_max', '', 'api_memoires_book_generate_pdfs', $hour, $h1, 199);
        $r->upsert('route_ms_max', '', 'api_memoires_book_generate_pdfs', $hour, $h1, 15714);
    }

    public function testOnlyThePlatformOwnerSeesTheScreenAndItsMenuEntry(): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique');
        $this->assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'anonyme renvoyé vers la connexion');

        $this->loginAs(['ROLE_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'administrateur de site refusé');
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique/seuils', ['seuils' => ['disk_used_pct' => ['good' => 1, 'warn' => 2]]]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'administrateur de site : seuils refusés');
        $this->assertSame(70.0, $this->threshold('disk_used_pct')['good'], 'seuil intact');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('Tableau de bord technique', (string) $this->client->getResponse()->getContent(), 'entrée de menu absente pour un administrateur de site');

        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin');
        $this->assertStringContainsString('Tableau de bord technique', (string) $this->client->getResponse()->getContent(), 'entrée de menu pour le propriétaire');
        // Ouverte depuis le menu, la page prend l'habillage EasyAdmin ; en accès direct, l'habillage de secours
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_technique');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Classement des sites par ressource', (string) $this->client->getResponse()->getContent());
    }

    public function testTheOwnerSeesTheRankingTheSiteDetailThePlatformAndTheRoutes(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();

        // Classement : le premier site d'abord (30 requêtes contre 5 sur 24 h ; les 500 d'il y a 3 jours sont hors fenêtre) ; la plateforme n'y est pas
        $sites = $crawler->filter('table.tech-classement tbody tr')->each(fn ($tr) => $tr->attr('data-site'));
        $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE], $sites);
        $this->assertStringNotContainsString('data-site="plateforme"', $html);
        $first = $crawler->filter('table.tech-classement tbody tr')->first();
        $this->assertSame('30', trim($first->filter('td')->eq(1)->filter('.tech-valeur')->text()), 'requêtes du premier site');
        $this->assertSame('4,0 s', trim($first->filter('td')->eq(2)->filter('.tech-valeur')->text()), 'temps PHP');
        $this->assertSame('1 200', trim($first->filter('td')->eq(8)->filter('.tech-valeur')->text()), 'jetons IA entrée + sortie');
        $this->assertSame('20,0 Mo', trim($first->filter('td')->eq(9)->filter('.tech-valeur')->text()), 'taille de base : dernier relevé');

        // Détail du premier site par défaut : courbes dans le JSON, dont les requêtes alignées sur les 24 heures
        $json = json_decode(html_entity_decode($crawler->filter('#tech-data')->text()), true);
        $this->assertIsArray($json);
        $ids = array_column($json['site'], 'id');
        $this->assertContains('requests', $ids);
        $this->assertContains('ai_tokens', $ids);
        $this->assertContains('db_size_bytes', $ids);
        $requests = $json['site'][array_search('requests', $ids, true)];
        $this->assertCount(25, $requests['labels'], '24 heures révolues + heure courante');
        $this->assertSame(30.0, (float) array_sum($requests['datasets'][0]['data']));
        $this->assertStringContainsString('assets/js/vendor/chart.umd.4.4.1.js', $html, 'Chart.js servi en local');
        $this->assertStringNotContainsString('cdn.', $html, 'aucun CDN');

        // Plateforme : indicateurs et états
        $this->assertStringContainsString('PHP-FPM : processus occupés', $html);
        $this->assertStringContainsString('▲ Moyen', $html, 'PHP-FPM à 60 % : moyen');
        $this->assertStringContainsString('✖ Mauvais', $html, 'sauvegarde de 3 jours : mauvais');
        $this->assertStringContainsString('● Bon', $html, 'disque à 15,9 % : bon');
        $this->assertStringContainsString('Timer pos-terminal-reconcile', $html);
        $this->assertStringContainsString('Timer backup-daily', $html);
        $this->assertStringContainsString('Docker : espace récupérable', $html);
        $this->assertStringContainsString('Dernière collecte il y a', $html);

        // Routes lourdes et seuils
        $this->assertStringContainsString('api_memoires_book_generate_pdfs', $html);
        $this->assertStringContainsString('199 Mo', $html);
        $this->assertSame(7, $crawler->filter('form.tech-seuils tbody tr')->count(), 'sept seuils');

        // Fenêtre 7 jours : les 500 requêtes d'il y a 3 jours font passer le second site devant ; tri par taille de base aussi
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique?fenetre=7j&site=' . MV_TEST_TENANT2_CODE);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([MV_TEST_TENANT2_CODE, MV_TEST_TENANT_CODE], $crawler->filter('table.tech-classement tbody tr')->each(fn ($tr) => $tr->attr('data-site')));
        $this->assertStringContainsString('Détail du site <strong>' . MV_TEST_TENANT2_CODE, (string) $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique?fenetre=24h&tri=db_size_bytes');
        $this->assertSame([MV_TEST_TENANT2_CODE, MV_TEST_TENANT_CODE], $crawler->filter('table.tech-classement tbody tr')->each(fn ($tr) => $tr->attr('data-site')), 'tri par taille de base');
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique?fenetre=zz&tri=zz&site=%27');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'paramètres inconnus : valeurs par défaut');
    }

    public function testTheOwnerEditsTheThresholdsAndInvalidValuesAreRefused(): void
    {
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique');
        $form = $crawler->filter('form.tech-seuils')->form();
        $token = $form->get('_token')->getValue();

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique/seuils', ['_token' => $token, 'fenetre' => '7j', 'seuils' => ['disk_used_pct' => ['good' => '60', 'warn' => '90'], 'fpm_active_pct' => ['good' => '40,5', 'warn' => '75']]]);
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('fenetre=7j', (string) $this->client->getResponse()->headers->get('Location'));
        $this->assertSame(['good' => 60.0, 'warn' => 90.0], $this->threshold('disk_used_pct'));
        $this->assertSame(['good' => 40.5, 'warn' => 75.0], $this->threshold('fpm_active_pct'), 'virgule décimale acceptée');
        $this->client->followRedirect();
        $this->assertStringContainsString('Seuils enregistrés', (string) $this->client->getResponse()->getContent());

        // Bon au-dessus de moyen : rien n'est enregistré, même pour les autres mesures du même envoi
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique/seuils', ['_token' => $token, 'seuils' => ['disk_used_pct' => ['good' => '95', 'warn' => '90'], 'fpm_queue' => ['good' => '1', 'warn' => '3']]]);
        $this->client->followRedirect();
        $this->assertStringContainsString('rester sous', (string) $this->client->getResponse()->getContent());
        $this->assertSame(['good' => 60.0, 'warn' => 90.0], $this->threshold('disk_used_pct'));
        $this->assertSame(['good' => 0.0, 'warn' => 2.0], $this->threshold('fpm_queue'));

        // Valeur non numérique ou négative refusée ; jeton invalide refusé
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique/seuils', ['_token' => $token, 'seuils' => ['disk_used_pct' => ['good' => 'abc', 'warn' => '-1']]]);
        $this->assertSame(['good' => 60.0, 'warn' => 90.0], $this->threshold('disk_used_pct'));
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique/seuils', ['_token' => 'faux', 'seuils' => ['disk_used_pct' => ['good' => '10', 'warn' => '20']]]);
        $this->client->followRedirect();
        $this->assertStringContainsString('Jeton de sécurité invalide', (string) $this->client->getResponse()->getContent());
        $this->assertSame(['good' => 60.0, 'warn' => 90.0], $this->threshold('disk_used_pct'));

        // L'indicateur suit les nouveaux seuils : PHP-FPM à 60 % devient mauvais avec moyen jusqu'à 55
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/technique/seuils', ['_token' => $token, 'seuils' => ['fpm_active_pct' => ['good' => '30', 'warn' => '55']]]);
        $crawler = $this->client->followRedirect();
        $card = $crawler->filter('.tech-indicateur')->reduce(fn ($n) => str_contains($n->text(), 'PHP-FPM : processus occupés'))->first();
        $this->assertStringContainsString('✖ Mauvais', $card->text());
    }

    public function testStatusFollowsTheThresholdDirection(): void
    {
        $service = static::getContainer()->get(TechDashboardService::class);
        $t = ['m' => ['good' => 50.0, 'warn' => 80.0, 'direction' => 'max'], 'n' => ['good' => 90.0, 'warn' => 70.0, 'direction' => 'min']];
        $this->assertSame('good', $service->status($t, 'm', 50.0));
        $this->assertSame('warn', $service->status($t, 'm', 80.0));
        $this->assertSame('bad', $service->status($t, 'm', 80.1));
        $this->assertSame('bad', $service->status($t, 'm', -1.0), 'jamais relevé');
        $this->assertSame('good', $service->status($t, 'n', 95.0));
        $this->assertSame('warn', $service->status($t, 'n', 75.0));
        $this->assertSame('bad', $service->status($t, 'n', 10.0));
        $this->assertNull($service->status($t, 'inconnue', 1.0));
        $this->assertSame('1,5 Go', TechDashboardService::format(1.5 * 1073741824, 'bytes'));
        $this->assertSame('2 h 05', TechDashboardService::format(7500, 'age'));
        $this->assertSame('1,5 min', TechDashboardService::format(90000, 'duration_ms'));
    }

    private function threshold(string $metric): array
    {
        $stmt = $this->master->prepare('SELECT good, warn FROM tech_threshold WHERE metric = ?');
        $stmt->execute([$metric]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return ['good' => (float) $row['good'], 'warn' => (float) $row['warn']];
    }

    private function loginAs(array $roles): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'technique-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Tech')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user); // super admin : code OTP toujours exigé (03/11/2026)
    }
}
