<?php

namespace App\Tests\Functional\Doctrine;

use App\EventSubscriber\CacheInvalidationSubscriber;
use App\EventSubscriber\ProductUsageGuardSubscriber;
use App\EventSubscriber\RetentionGuardSubscriber;
use App\EventSubscriber\SubscriptionLifecycleSubscriber;
use App\EventSubscriber\TaxInUseGuardSubscriber;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Montée Symfony 7.4 (20/11/2026) : Symfony 7 n'inscrit plus les abonnés Doctrine (étiquette doctrine.event_subscriber
 * ignorée sans erreur). Les cinq gardes de src/EventSubscriber auraient cessé de tourner en silence : invalidation du
 * cache, conservation des ventes, taxe et produit utilisés, cycle de vie des abonnements. Elles sont des écouteurs
 * (config/services.yaml) ; ce test vérifie qu'elles sont branchées, sur chaque événement qu'elles déclarent, et dans
 * l'ordre de la 6.4 sur onFlush.
 */
class DoctrineGuardsWiringTest extends KernelTestCase
{
    public function testEveryDoctrineSubscriberOfOurCodeIsWiredOnEachOfItsEvents(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $events = $c->get('doctrine.dbal.default_connection.event_manager');
        $classes = self::ourDoctrineSubscribers();
        $this->assertGreaterThanOrEqual(5, count($classes), 'les cinq gardes au moins');

        foreach ($classes as $class) {
            $subscriber = $c->get($class);
            $this->assertInstanceOf(EventSubscriber::class, $subscriber);
            foreach ($subscriber->getSubscribedEvents() as $event) {
                $wired = $events->hasListeners($event) ? array_map('get_class', array_values($events->getListeners($event))) : [];
                $this->assertContains($class, $wired, sprintf('%s doit écouter %s', $class, $event));
            }
        }
    }

    public function testTheGuardsRunOnFlushInTheOrderOfSymfony64(): void
    {
        self::bootKernel();
        $events = static::getContainer()->get('doctrine.dbal.default_connection.event_manager');
        $order = array_values(array_filter(
            array_map('get_class', array_values($events->getListeners(Events::onFlush))),
            fn (string $class) => str_starts_with($class, 'App\\EventSubscriber\\'),
        ));
        $this->assertSame([
            ProductUsageGuardSubscriber::class,
            CacheInvalidationSubscriber::class,
            RetentionGuardSubscriber::class,
            SubscriptionLifecycleSubscriber::class,
            TaxInUseGuardSubscriber::class,
        ], $order);
    }

    /** @return list<class-string> classes de src/ qui implémentent Doctrine\Common\EventSubscriber */
    private static function ourDoctrineSubscribers(): array
    {
        $classes = [];
        $src = static::$kernel->getProjectDir() . '/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code = (string) file_get_contents((string) $file);
            if (!str_ends_with((string) $file, '.php') || !str_contains($code, 'use Doctrine\\Common\\EventSubscriber;')
                || !preg_match('/^namespace ([^;]+);/m', $code, $ns) || !preg_match('/^(?:final )?class (\w+)[^{]*implements[^{]*\bEventSubscriber\b/m', $code, $cl)) {
                continue;
            }
            $classes[] = $ns[1] . '\\' . $cl[1];
        }
        sort($classes);

        return $classes;
    }
}
