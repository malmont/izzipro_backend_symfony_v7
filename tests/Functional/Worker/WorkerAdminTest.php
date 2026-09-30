<?php

namespace App\Tests\Functional\Worker;

use App\Entity\User;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use App\Services\Worker\WorkerMonitor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran « Workers » de l'administration, réservé au propriétaire de la plateforme : état des workers d'après leur
 * battement, redémarrage, relance d'une rédaction de chapitre en échec.
 */
class WorkerAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testWorkerStateFollowsItsHeartbeat(): void
    {
        $monitor = static::getContainer()->get(WorkerMonitor::class);

        $monitor->beat('async', ['state' => 'idle', 'startedAt' => time() - 60, 'handled' => 2]);
        $this->assertSame('idle', $monitor->status()['async']['state']);
        $this->assertTrue($monitor->isWorkerUp('async'));

        $monitor->beat('async', ['state' => 'processing', 'since' => time() - 45, 'message' => 'GenerateChapterMessage (chapitre x, partie 1)']);
        $status = $monitor->status()['async'];
        $this->assertSame('processing', $status['state']);
        $this->assertStringContainsString('45 s', $status['detail']);

        $monitor->beat('async', ['state' => 'processing', 'since' => time() - 3600, 'message' => 'GenerateChapterMessage']);
        $this->assertSame('danger', $monitor->status()['async']['level'], 'traitement anormalement long');

        $monitor->beat('async', ['state' => 'idle', 'at' => time() - 60]);
        $this->assertSame('restarting', $monitor->status()['async']['state']);

        $monitor->beat('async', ['state' => 'idle', 'at' => time() - 900]);
        $this->assertSame('down', $monitor->status()['async']['state']);
        $this->assertFalse($monitor->isWorkerUp('async'));

        // Transport en mémoire pendant les tests : file non lisible, jamais « vide » par défaut
        $this->assertNull($monitor->isQueueEmpty('async'));
    }

    public function testSiteAdminHasNoAccessToTheWorkers(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], $server);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(0, $crawler->filter('a[href*="admin_workers"], a[href*="/admin/workers"]'), 'pas d\'entrée de menu pour un administrateur de site');

        foreach ([['GET', '/admin?routeName=admin_workers'], ['GET', '/admin/workers'], ['POST', '/admin/workers/restart'], ['POST', '/admin/workers/chapters/00000000-0000-4000-8000-000000000000/relaunch'], ['POST', '/admin/workers/chapters/00000000-0000-4000-8000-000000000000/fail']] as [$method, $path]) {
            $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, ['_token' => 'x'], [], $server);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$method $path");
        }
    }

    public function testPlatformOwnerSeesTheWorkers(): void
    {
        static::getContainer()->get(WorkerMonitor::class)->beat('async', ['state' => 'idle']);
        $this->loginAs([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN']);
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST];

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin', [], [], $server);
        $this->assertGreaterThan(0, $crawler->filter('a[href*="admin_workers"], a[href*="/admin/workers"]')->count(), 'entrée de menu');

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_workers', [], [], $server);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('idle', $crawler->filter('[data-worker="async"]')->attr('data-state'));
        $this->assertCount(3, $crawler->filter('[data-worker]'));
        $this->assertCount(1, $crawler->filter('form[action$="/admin/workers/restart"]'));
    }

    public function testPlatformOwnerRestartsTheWorkers(): void
    {
        $this->loginAs([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN']);
        $signal = fn () => static::getContainer()->get('cache.messenger.restart_workers_signal')->getItem('workers.restart_requested_timestamp');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/workers/restart', ['_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertFalse($signal()->isHit(), 'sans jeton valide, aucun redémarrage');

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_workers', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $form = $crawler->filter('form[action$="/admin/workers/restart"]')->form();
        $before = microtime(true);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/workers/restart', $form->getPhpValues(), [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertTrue($signal()->isHit());
        $this->assertGreaterThanOrEqual($before, $signal()->get(), 'même signal que messenger:stop-workers');
    }

    public function testAFailedChapterCanBeRelaunchedFromTheScreen(): void
    {
        $this->loginAs([RoleAssignmentPolicy::SUPER_ADMIN, 'ROLE_ADMIN']);
        $connection = $this->em()->getConnection();
        $chapterId = $connection->fetchOne('SELECT id FROM mv_chapter ORDER BY id LIMIT 1');
        $connection->executeStatement(
            "UPDATE mv_chapter SET generation_status = 'failed', generation_error = 'IA surchargée', updated_at = now(), answers = ? WHERE id = ?",
            [json_encode([['index' => 0, 'question' => 'Q ?', 'answer' => 'Une réponse.']]), $chapterId]
        );

        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?routeName=admin_workers', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('IA surchargée', $crawler->filter('table')->text());
        $form = $crawler->filter('form[action$="/admin/workers/chapters/' . $chapterId . '/relaunch"]')->form();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertCount(1, static::getContainer()->get('messenger.transport.async')->getSent());
        $this->assertSame('pending', $this->em()->getConnection()->fetchOne('SELECT generation_status FROM mv_chapter WHERE id = ?', [$chapterId]));
    }

    private function loginAs(array $roles): void
    {
        $email = 'workers-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname('Workers')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user, 'main');
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
