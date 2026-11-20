<?php

namespace App\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Lot TACHES-0 (20/11/2026, `docs/plan-taches.md` § 2, contrat validé par le chef) : le planning « default » du planificateur (Symfony
 * Scheduler), consommé par le 8e worker (`symfony_messenger_worker_scheduler_v2`, transport `scheduler_default`). VIDE : aucune tâche n'y
 * est inscrite tant que le lot TACHES-1 (catalogue d'actions, table `scheduled_task`, verrou) n'est pas livré ; les tâches restent en
 * systemd jusqu'à leur migration une par une (TACHES-5).
 */
#[AsSchedule('default')]
final class PlatformSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule();
    }
}
