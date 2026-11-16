<?php

namespace App\Gestion\Command;

use App\Gestion\Services\GestionNotificationService;
use App\Gestion\Services\GestionStockSnapshotService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Notifications sur téléphone de la gestion (lot N1, 11/11/2026, `docs/notifications-gestion.md`) : passage quotidien de chaque site à
 * 10 h, heure du magasin (stock bas). Lancée toutes les heures par le timer de l'hôte `gestion-notifications.timer` (ops/systemd/) :
 * chaque site n'est traité qu'à son heure locale, sauf `--force`. Un site en erreur n'arrête pas les autres.
 */
#[AsCommand(
    name: 'app:gestion:notifications:envoyer',
    description: 'Passage quotidien des notifications de la gestion (tous les sites, ou --tenant ; --force ignore l\'heure du magasin)'
)]
class GestionNotificationsSendCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionNotificationService $notifications,
        private readonly LoggerInterface $logger,
        private readonly ?GestionStockSnapshotService $snapshots = null, // lot S1 : instantané quotidien de la valeur du stock, au même passage
        private readonly ?PeriodCalendar $calendar = null,
        private readonly ?\App\Services\FeatureAccess\ModuleAccessService $modules = null, // lot MOD : module de gestion éteint (mode live) = pas de notification
        private readonly ?\App\Services\Platform\PlatformModeService $platform = null,
        private readonly ?\App\Services\Notification\NotificationSummaryService $held = null, // lot NOTIF-1 : sortie des courriels de suivi retenus
        private readonly ?\App\Services\Notification\NotificationWeeklyService $weekly = null, // lot NOTIF-2 : résumé de la semaine, le lundi à partir de 8 h du magasin
        private readonly ?\App\Gestion\Services\Report\GestionReportService $reports = null, // lot DOC-1 : retrait des rapports PDF passé leur délai de conservation
        private readonly ?\App\Import\CustomerImportService $customerImports = null, // lot IMP-2 : effacement des clients repris d'une ancienne boutique qui n'ont pas accepté à temps
        private readonly ?\App\Import\OrderImportService $orderImports = null, // lot IMP-3 : fichiers de commandes abandonnés
        private readonly ?\App\Services\Agency\AgencySiteRequestService $agencySites = null, // lot AG-2 : rattrapage de la file des sites demandés par les agences
        private readonly ?\App\Services\Platform\Sites\PlatformSiteService $platformSites = null, // lot TYPE-1 : rattrapage des demandes de création de site de la plateforme
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Traiter même hors de l\'heure prévue')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'Instant simulé, en UTC (AAAA-MM-JJ HH:MM) : pour un essai, jamais dans le minuteur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('tenant');
        $at = $input->getOption('at') !== null ? new \DateTimeImmutable((string) $input->getOption('at'), new \DateTimeZone('UTC')) : null;
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sites = 0;
        $notified = 0;
        $errors = 0;
        foreach ($rows as $row) {
            if ($only !== null && $row['code'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $row['dbname'], (string) $row['code']);
                $this->purgeReports((string) $row['code']);
                $this->eraseImportGuests((string) $row['code'], $io); // pour tout site, module de gestion activé ou non
                $this->releaseHeld((string) $row['code'], $io, $at); // pour tout site, module de gestion activé ou non : ces courriels ne sont pas ceux de la gestion
                if ($this->platform !== null && $this->modules !== null && !$this->platform->isDemo() && !$this->modules->isEnabled(\App\Services\FeatureAccess\ModuleAccessService::MANAGEMENT)) {
                    $this->snapshot((string) $row['code']); // lot MOD : module éteint, plateforme en live : pas de notification ; l'instantané du stock reste pris (historique)
                    $io->text(sprintf('%s : application de gestion non activée, aucune notification', $row['code']));
                    continue;
                }
                $result = $this->notifications->runDaily($at, (bool) $input->getOption('force'));
                if ($result['ran']) {
                    $this->snapshot((string) $row['code']);
                    ++$sites;
                    $notified += $result['notified'];
                    $io->text(sprintf('%s : %d notification(s), %d sans envoi', $row['code'], $result['notified'], $result['skipped']));
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error(sprintf('Notifications gestion : site %s non traité : %s', $row['code'], $e->getMessage()));
                $io->warning(sprintf('%s : non traité (%s)', $row['code'], $e->getMessage()));
            }
        }
        // Lot AG-2 : la file des sites demandés par les agences (base maître, une fois par passage, hors de tout site). Une fabrication
        // interrompue passe en échec ; une demande restée en attente est confiée de nouveau au worker. Jamais bloquant.
        if ($only === null && $at === null) {
            try {
                $swept = $this->agencySites?->sweep() ?? ['interrupted' => 0, 'requeued' => 0];
                if (array_sum($swept) > 0) {
                    $io->text(sprintf('Sites demandés par les agences : %d fabrication(s) interrompue(s) en échec, %d demande(s) confiée(s) de nouveau au worker', $swept['interrupted'], $swept['requeued']));
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Sites demandés par les agences : passage de rattrapage en échec (%s)', $e::class));
            }
            // lot TYPE-1 : les demandes de la plateforme (interrompues rendues visibles, en attente remises en file, rôles de responsable
            // dont l'acceptation est notée). À part : un échec du passage des agences ne l'empêche pas.
            try {
                $platform = $this->platformSites?->sweep() ?? ['interrupted' => 0, 'requeued' => 0, 'managers' => 0];
                if (array_sum($platform) > 0) {
                    $io->text(sprintf('Sites demandés par la plateforme : %d demande(s) interrompue(s) rendue(s) visible(s), %d confiée(s) de nouveau au worker, %d rôle(s) de responsable donné(s)', $platform['interrupted'], $platform['requeued'], $platform['managers']));
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Sites demandés par la plateforme : passage de rattrapage en échec (%s)', $e::class));
            }
        }
        $io->success(sprintf('%d site(s) à leur heure, %d notification(s), %d site(s) en erreur.', $sites, $notified, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Lot NOTIF-1 : les courriels de suivi retenus qui sont dus sortent, un par destinataire et par sujet, à partir de 8 h du magasin
     * (`NotificationSummaryService`). À chaque passage horaire : un second passage ne retrouve plus ce que le premier a sorti. Une erreur
     * n'empêche jamais les notifications du téléphone.
     */
    private function releaseHeld(string $code, SymfonyStyle $io, ?\DateTimeImmutable $at = null): void
    {
        if ($this->held === null) {
            return;
        }
        try {
            $result = $this->held->releaseDue($at);
            if ($result['emails'] > 0 || $result['kept'] > 0) {
                $io->text(sprintf('%s : %d courriel(s) de suivi retenus sortis en %d courriel(s), %d gardé(s) sans envoi', $code, $result['released'], $result['emails'], $result['kept']));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Courriels de suivi retenus : site %s non traité : %s', $code, $e->getMessage()));
        }
        if ($this->weekly === null || $code === 'gmasuite') {
            return;
        }
        try {
            $result = $this->weekly->sendIfDue($at);
            if ($result['emails'] > 0 || $result['kept'] > 0) {
                $io->text(sprintf('%s : résumé de la semaine envoyé à %d administrateur(s), %d courriel(s) retenu(s) sorti(s), %d gardé(s) sans envoi', $code, $result['emails'], $result['released'], $result['kept']));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Résumé de la semaine : site %s non traité : %s', $code, $e->getMessage()));
        }
    }

    /** Lot DOC-1 : les rapports PDF de la gestion passé leur délai de conservation (90 jours) sont retirés, avec leur fichier ; une erreur n'empêche rien d'autre */
    /**
     * Lot IMP-2 : un client repris du fichier d'une ancienne boutique qui n'a pas accepté son invitation est effacé 90 jours après
     * l'import (`ImportGuests::ERASE_DAYS`), et un fichier de clients déposé puis jamais confirmé est supprimé après 7 jours. À chaque
     * passage horaire, pour tout site ; une erreur n'empêche jamais la suite.
     */
    private function eraseImportGuests(string $code, SymfonyStyle $io): void
    {
        try {
            $result = $this->customerImports?->purgeDue() ?? ['erased' => 0, 'kept' => 0, 'failed' => 0, 'abandoned' => 0];
            // lot IMP-3 : un fichier de commandes déposé et jamais confirmé (ou un import resté en cours plus d'un jour) est supprimé
            $orders = $this->orderImports?->purgeDue() ?? 0;
            if ($orders > 0) {
                $io->text(sprintf('%s : %d import(s) de commandes abandonné(s), fichier supprimé', $code, $orders));
            }
            if (array_sum($result) > 0) {
                $io->text(sprintf('%s : %d client(s) repris non acceptés effacé(s), %d gardé(s) (une commande les tient, ou le compte a été activé), %d en échec, %d import(s) de clients abandonné(s)', $code, $result['erased'], $result['kept'], $result['failed'], $result['abandoned']));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Clients repris par import : effacement du site %s en échec (%s)', $code, $e::class));
        }
    }

    private function purgeReports(string $code): void
    {
        try {
            $this->reports?->purgeExpired();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Rapports de la gestion : purge du site %s en échec (%s)', $code, $e::class));
        }
    }

    /** Lot S1 : instantané de la valeur du stock du jour du magasin ; une erreur n'empêche jamais les notifications */
    private function snapshot(string $code): void
    {
        if ($this->snapshots === null || $this->calendar === null) {
            return;
        }
        try {
            $this->snapshots->take($this->calendar->today());
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Instantané du stock : site %s non traité : %s', $code, $e->getMessage()));
        }
    }
}
