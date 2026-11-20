<?php

namespace App\Tests\Functional\Ops;

use App\Scheduler\PlatformSchedule;
use App\Services\Worker\WorkerMonitor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot TACHES-0 (20/11/2026, `docs/plan-taches.md` § 2) : le composant du planificateur est là, et ne fait RIEN. Le planning « default »
 * existe et est vide (aucune tâche avant TACHES-1) ; son transport `scheduler_default` est déclaré à Messenger ; l'écran « Workers » suit
 * le 8e worker par son battement, sans file Redis.
 */
class SchedulerComponentTest extends KernelTestCase
{
    public function testTheDefaultScheduleExistsAndIsEmpty(): void
    {
        self::bootKernel();
        $schedule = static::getContainer()->get(PlatformSchedule::class)->getSchedule();
        $this->assertSame([], $schedule->getRecurringMessages(), 'aucune tâche avant TACHES-1 : les tâches restent en systemd');
        $this->assertTrue(static::getContainer()->has('messenger.transport.scheduler_default'), 'le transport du planning « default » est déclaré');
    }

    public function testTheWorkersScreenFollowsTheSchedulerWorkerWithoutAQueue(): void
    {
        $this->assertSame(['label' => 'Planificateur des tâches', 'suffix' => null], WorkerMonitor::WORKERS['scheduler_default']);
        $this->assertCount(8, WorkerMonitor::WORKERS);
        self::bootKernel();
        $monitor = static::getContainer()->get(WorkerMonitor::class);
        $this->assertNull($monitor->queue('scheduler_default'), 'aucune file Redis à lire pour le planificateur');
        $this->assertArrayHasKey('scheduler_default', $monitor->status());
    }
}
