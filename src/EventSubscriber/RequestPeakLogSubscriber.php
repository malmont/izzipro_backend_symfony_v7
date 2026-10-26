<?php

namespace App\EventSubscriber;

use App\Services\TenantConnectionManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mesure des requêtes web lourdes (S8, 26/10/2026) : avant de passer le plafond mémoire de PHP-FPM de 1024 à 512 Mo, connaître la mémoire
 * maximale réelle par route. En fin de requête (après l'envoi de la réponse), une ligne JSON dans var/log/request-peaks-AAAA-MM.log quand le
 * pic mémoire atteint 64 Mo ou la durée 5 s : {at, route, method, status, site, peakMb, durationMs}. Ni chemin réel ni paramètres (jetons
 * possibles dans l'URL), ni donnée personnelle. Aucun effet sur la réponse ; une écriture impossible est ignorée.
 * Rotation : un fichier par mois ; à la création du fichier d'un nouveau mois, ceux de plus de 3 mois (mois courant compris) sont supprimés.
 */
class RequestPeakLogSubscriber implements EventSubscriberInterface
{
    /** Mois conservés, mois courant compris */
    public const KEEP_MONTHS = 3;

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly int $minMb = 64,
        private readonly int $minMs = 5000,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -1024]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $peakMb = (int) round(memory_get_peak_usage(true) / 1048576);
        $started = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(true));
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        if ($peakMb < $this->minMb && $durationMs < $this->minMs) {
            return;
        }
        try {
            $site = $this->tenants->getCurrentTenantCode();
        } catch (\Throwable) {
            $site = null;
        }
        $line = json_encode([
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'route' => (string) $request->attributes->get('_route', 'inconnue'),
            'method' => $request->getMethod(),
            'status' => $event->getResponse()->getStatusCode(),
            'site' => $site,
            'peakMb' => $peakMb,
            'durationMs' => $durationMs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $suffix = $this->environment === 'test' ? '-test' : '';
        $file = $this->projectDir . '/var/log/request-peaks' . $suffix . '-' . date('Y-m') . '.log';
        if (!is_file($file)) {
            $this->prune($suffix, new \DateTimeImmutable('first day of this month'));
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Supprime les fichiers mensuels antérieurs aux KEEP_MONTHS derniers mois (mois courant compris) */
    public function prune(string $suffix, \DateTimeImmutable $currentMonth): void
    {
        $oldest = $currentMonth->modify('-' . (self::KEEP_MONTHS - 1) . ' months')->format('Y-m');
        foreach (glob($this->projectDir . '/var/log/request-peaks' . $suffix . '-*.log') ?: [] as $old) {
            if (preg_match('/-(\d{4}-\d{2})\.log$/', $old, $m) && $m[1] < $oldest) {
                @unlink($old);
            }
        }
    }
}
