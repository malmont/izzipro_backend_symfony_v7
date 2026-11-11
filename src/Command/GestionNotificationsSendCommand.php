<?php

namespace App\Command;

use App\Services\GestionService\GestionNotificationService;
use App\Services\GestionService\GestionStockSnapshotService;
use App\Services\GestionService\PeriodCalendar;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Traiter même hors de l\'heure prévue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('tenant');
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
                $result = $this->notifications->runDaily(null, (bool) $input->getOption('force'));
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
        $io->success(sprintf('%d site(s) à leur heure, %d notification(s), %d site(s) en erreur.', $sites, $notified, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
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
