<?php

namespace App\Tests\Functional\Tech;

use App\EventSubscriber\TechRequestCounterSubscriber;
use App\EventSubscriber\TechWorkerCounterSubscriber;
use App\Services\TechMetricsService\TechCounters;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Compteurs techniques par site (tableau de bord technique, T1, 03/11/2026) : chaque requête web incrémente le hachage Redis de son site
 * et de son heure (nombre, durée, pic mémoire, erreurs), après l'envoi de la réponse seulement, pour un coût de quelques dixièmes de
 * milliseconde ; une tâche Messenger traitée incrémente la file de son site ; un site ne compte jamais pour un autre.
 */
class TechRequestCounterTest extends WebTestCase
{
    public function testEveryRequestIsCountedForItsSiteAfterTheResponseAtNegligibleCost(): void
    {
        $client = static::createClient();
        $counters = static::getContainer()->get(TechCounters::class);
        $now = TechCounters::now();
        $before = $counters->readHour(TechCounters::KIND_REQUEST, MV_TEST_TENANT_CODE, $now);
        $beforeOther = $counters->readHour(TechCounters::KIND_REQUEST, MV_TEST_TENANT2_CODE, $now);

        $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/order-statuses', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/route-qui-n-existe-pas', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $after = $counters->readHour(TechCounters::KIND_REQUEST, MV_TEST_TENANT_CODE, $now);
        $this->assertSame(2, (int) ($after['n'] ?? 0) - (int) ($before['n'] ?? 0), 'deux requêtes comptées pour le site : ' . json_encode($after));
        $this->assertSame(1, (int) ($after['e4'] ?? 0) - (int) ($before['e4'] ?? 0), 'une erreur 4xx comptée');
        $this->assertGreaterThanOrEqual((int) ($before['ms'] ?? 0), (int) ($after['ms'] ?? 0), 'durée cumulée');
        $this->assertGreaterThan(0, (int) ($after['peak'] ?? 0), 'pic mémoire de l\'heure');
        $this->assertSame($beforeOther, $counters->readHour(TechCounters::KIND_REQUEST, MV_TEST_TENANT2_CODE, $now), 'le second site n\'a rien compté');

        // Après la réponse seulement (événement de terminaison), et coût mesuré de l'incrément
        $this->assertSame([KernelEvents::TERMINATE => ['onTerminate', -1000]], TechRequestCounterSubscriber::getSubscribedEvents());
        $this->assertNotNull(TechCounters::$lastCostMs, 'Redis joignable pendant les tests');
        $this->assertLessThan(5.0, TechCounters::$lastCostMs, sprintf('incrément Redis en %.3f ms', TechCounters::$lastCostMs));
        fwrite(STDERR, sprintf("\n[Tech] coût du compteur de requêtes : %.3f ms\n", TechCounters::$lastCostMs));
    }

    public function testAHandledMessengerMessageIsCountedForItsSiteAndQueue(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $counters = $container->get(TechCounters::class);
        $now = TechCounters::now();
        $before = $counters->readHour(TechCounters::KIND_JOB, MV_TEST_TENANT_CODE, $now);

        $subscriber = $container->get(TechWorkerCounterSubscriber::class);
        $subscriber->onHandled(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'email'));
        $subscriber->onHandled(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'email'));
        $subscriber->onHandled(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'media'));

        $after = $counters->readHour(TechCounters::KIND_JOB, MV_TEST_TENANT_CODE, $now);
        $this->assertSame(2, (int) ($after['email'] ?? 0) - (int) ($before['email'] ?? 0), 'deux courriels');
        $this->assertSame(1, (int) ($after['media'] ?? 0) - (int) ($before['media'] ?? 0), 'une tâche média');
        $this->assertSame([], $counters->readHour(TechCounters::KIND_JOB, MV_TEST_TENANT2_CODE, $now), 'rien pour le second site');
    }
}
