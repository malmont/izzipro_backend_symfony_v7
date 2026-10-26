<?php

namespace App\Tests\Functional\Ops;

use App\EventSubscriber\RequestPeakLogSubscriber;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Mesure des requêtes lourdes (S8) : ligne JSON au-delà des seuils, sans chemin réel ni paramètres ; rien sous les seuils */
class RequestPeakLogTest extends KernelTestCase
{
    public function testAHeavyRequestIsLoggedWithoutItsPathAndALightOneIsNot(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $file = static::$kernel->getProjectDir() . '/var/log/request-peaks-test-' . date('Y-m') . '.log';
        @unlink($file);
        $request = Request::create('/api/r/jeton-secret-123?x=1');
        $request->attributes->set('_route', 'pos_public_receipt');
        $event = new TerminateEvent(static::$kernel, $request, new Response('', 200));

        (new RequestPeakLogSubscriber($c->get(TenantConnectionManager::class), static::$kernel->getProjectDir(), 'test', 100000, 100000))->onTerminate($event);
        $this->assertFileDoesNotExist($file, 'sous les seuils : rien');

        (new RequestPeakLogSubscriber($c->get(TenantConnectionManager::class), static::$kernel->getProjectDir(), 'test', 0, 0))->onTerminate($event);
        $line = json_decode(trim((string) file_get_contents($file)), true);
        $this->assertSame(['at', 'route', 'method', 'status', 'site', 'peakMb', 'durationMs'], array_keys($line));
        $this->assertSame(['pos_public_receipt', 'GET', 200], [$line['route'], $line['method'], $line['status']]);
        $this->assertGreaterThan(0, $line['peakMb']);
        $this->assertStringNotContainsString('jeton-secret', (string) file_get_contents($file), 'jamais le chemin réel');
        @unlink($file);
        $this->assertTrue(isset(RequestPeakLogSubscriber::getSubscribedEvents()['kernel.terminate']));

        // Rotation : 3 mois gardés (mois courant compris), les plus anciens supprimés
        $dir = static::$kernel->getProjectDir() . '/var/log/';
        $now = new \DateTimeImmutable('2026-11-01');
        foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $m) {
            file_put_contents($dir . "request-peaks-test-$m.log", "{}\n");
        }
        (new RequestPeakLogSubscriber($c->get(TenantConnectionManager::class), static::$kernel->getProjectDir(), 'test'))->prune('-test', $now);
        $kept = array_map(fn ($f) => substr(basename($f), -11, 7), glob($dir . 'request-peaks-test-*.log'));
        sort($kept);
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $kept);
        array_map('unlink', glob($dir . 'request-peaks-test-*.log'));
        unset($event, $c);
        $this->assertInstanceOf(HttpKernelInterface::class, static::$kernel);
    }
}
