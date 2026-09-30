<?php

namespace App\Services\Worker;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Battement de cœur des workers Messenger : chaque worker écrit son état (au repos, en traitement, arrêté) dans le
 * cache applicatif, lu par l'écran d'administration « Workers » (WorkerMonitor). Ces événements n'existent que dans
 * le processus « messenger:consume ».
 */
class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private int $startedAt = 0;
    private int $handled = 0;
    private int $lastBeat = 0;
    /** @var array<string, mixed> dernier message traité, rappelé dans chaque battement */
    private array $last = [];

    public function __construct(
        private readonly WorkerMonitor $monitor
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onStarted',
            WorkerRunningEvent::class => 'onRunning',
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
            WorkerStoppedEvent::class => 'onStopped',
        ];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->startedAt = time();
        foreach ($event->getWorker()->getMetadata()->getTransportNames() as $transport) {
            $this->beat($transport, ['state' => 'idle']);
        }
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle() || time() - $this->lastBeat < WorkerMonitor::HEARTBEAT_INTERVAL) {
            return;
        }
        foreach ($event->getWorker()->getMetadata()->getTransportNames() as $transport) {
            $this->beat($transport, ['state' => 'idle']);
        }
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->beat($event->getReceiverName(), [
            'state' => 'processing',
            'since' => time(),
            'message' => self::describe($event->getEnvelope()->getMessage()),
        ]);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->finished($event->getReceiverName(), $event->getEnvelope()->getMessage(), 'traité');
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->finished($event->getReceiverName(), $event->getEnvelope()->getMessage(), $event->willRetry() ? 'erreur, nouvelle tentative prévue' : 'erreur');
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        foreach ($event->getWorker()->getMetadata()->getTransportNames() as $transport) {
            $this->beat($transport, ['state' => 'stopped']);
        }
    }

    private function finished(string $transport, object $message, string $result): void
    {
        $this->handled++;
        $this->last = ['lastMessage' => self::describe($message), 'lastMessageAt' => time(), 'lastResult' => $result];
        $this->beat($transport, ['state' => 'idle']);
    }

    private function beat(string $transport, array $data): void
    {
        $this->lastBeat = time();
        $this->monitor->beat($transport, $data + $this->last + [
            'startedAt' => $this->startedAt ?: time(),
            'handled' => $this->handled,
            'host' => gethostname() ?: '',
        ]);
    }

    /** Libellé court d'un message, sans donnée personnelle */
    private static function describe(object $message): string
    {
        $name = substr(strrchr('\\' . $message::class, '\\'), 1);
        $details = [];
        foreach (['chapterId' => 'chapitre', 'part' => 'partie', 'tenantHost' => 'site', 'tenantCode' => 'site', 'jobId' => 'tâche', 'reportId' => 'rapport'] as $property => $label) {
            // Propriétés publiques seulement (les autres messages gardent les leurs privées)
            if (property_exists($message, $property) && (new \ReflectionProperty($message, $property))->isPublic() && is_scalar($message->$property ?? null)) {
                $details[] = $label . ' ' . $message->$property;
            }
        }

        return $details ? $name . ' (' . implode(', ', $details) . ')' : $name;
    }
}
