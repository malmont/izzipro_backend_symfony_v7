<?php

namespace App\Command;

use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Réconciliation des paiements par terminal Stripe (C4, 23/10/2026), à planifier par cron (toutes les 15 minutes) :
 * seuls les sites ayant un terminal activé sont parcourus ; un site sans paiement en attente ne provoque aucun appel
 * Stripe (une requête indexée) ; verrou contre deux exécutions simultanées (fichier + verrou consultatif par base) ;
 * plage horaire facultative (--from / --to). Installation : docs/caisse.md, « Terminal de paiement ».
 * État daté de chaque exécution (M1) : var/pos-terminal-reconcile.status.json (dernière exécution et historique des 50 dernières),
 * lu par la surveillance de la caisse (GET /api/pos/health) ; écrit même hors plage horaire ou si une autre exécution tient le verrou.
 */
#[AsCommand(name: 'app:pos:terminal-reconcile', description: 'Caisse : réconcilie les paiements par terminal Stripe en attente (autorisations expirées, paiements sans vente, remboursements)')]
class PosTerminalReconcileCommand extends Command
{
    private const LOCK_KEY = 7461004;
    public const STATUS_FILE = 'var/pos-terminal-reconcile.status.json';

    /** État daté propre à l'environnement : les tests n'écrivent jamais celui du serveur (M1) */
    public static function statusPath(string $projectDir, string $environment): string
    {
        return $projectDir . '/' . ($environment === 'test' ? 'var/pos-terminal-reconcile.status.test.json' : self::STATUS_FILE);
    }
    private const HISTORY = 50;

    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly PosTerminalPaymentService $payments, private readonly string $projectDir,
        private readonly \App\Services\PosStripeService\PosHealthService $health, // alertes de surveillance (M1)
        private readonly string $environment = 'dev')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Début de la plage horaire (HH:MM, heure du serveur) ; hors plage, la commande sort aussitôt')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Fin de la plage horaire (HH:MM)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Un seul site (code)')
            ->addOption('no-alerts', null, InputOption::VALUE_NONE, 'Sans les contrôles de surveillance ni les alertes par courriel (M1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = new \DateTimeImmutable();
        $window = ['from' => $input->getOption('from'), 'to' => $input->getOption('to')];
        if (!$this->inWindow($input->getOption('from'), $input->getOption('to'))) {
            $output->writeln('Hors plage horaire : rien à faire.');
            $this->writeStatus(['outcome' => 'outside_window', 'startedAt' => $startedAt, 'window' => $window]);

            return Command::SUCCESS;
        }
        $lockFile = fopen($this->projectDir . ($this->environment === 'test' ? '/var/pos-terminal-reconcile.test.lock' : '/var/pos-terminal-reconcile.lock'), 'c');
        if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
            $output->writeln('<comment>Une réconciliation est déjà en cours : sortie.</comment>');
            $this->writeStatus(['outcome' => 'locked', 'startedAt' => $startedAt, 'window' => $window]);

            return Command::SUCCESS;
        }
        $sites = [];
        $errors = 0;
        $only = $input->getOption('tenant');
        $visited = $skipped = 0;
        foreach ($this->tenants->getAllTenants() as $tenant) {
            if ($only !== null && $tenant['code'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
                $connection = $this->emProvider->getEntityManager()->getConnection();
                // Sites sans terminal : non parcourus (requêtes indexées, aucun appel Stripe). Terminal = lecteur piloté par le serveur,
                // ou poste en mode kit Stripe Terminal (F13/F16 : Tap to Pay, Bluetooth), dont les paiements et remboursements se réconcilient aussi
                if (!$connection->fetchOne("SELECT 1 FROM cash_register WHERE terminal_enabled = true OR terminal_mode IN ('tap_to_pay', 'bluetooth') LIMIT 1")) {
                    $skipped++;
                    continue;
                }
                if (!$connection->fetchOne('SELECT pg_try_advisory_lock(?)', [self::LOCK_KEY])) {
                    $output->writeln(sprintf('<comment>%s : réconciliation déjà en cours sur ce site, ignoré</comment>', $tenant['code']));
                    continue;
                }
                try {
                    $result = $this->payments->reconcile();
                } finally {
                    $connection->executeStatement('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
                }
                $visited++;
                $sites[$tenant['code']] = $result;
                if ($result['pending'] > 0 || $result['refunds'] > 0 || $output->isVerbose()) {
                    $output->writeln(sprintf('%s : %d en attente → %d autorisés, %d débités, %d annulés, %d refusés ; %d remboursements suivis', $tenant['code'],
                        $result['pending'], $result['authorized'], $result['captured'], $result['canceled'], $result['failed'], $result['refunds']));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s : %s</error>', $tenant['code'], $e->getMessage()));
                $sites[$tenant['code']] = ['error' => mb_substr($e->getMessage(), 0, 300)];
                $errors++;
            }
        }
        $this->writeStatus(['outcome' => $errors > 0 ? 'partial' : 'ok', 'startedAt' => $startedAt, 'window' => $window, 'visited' => $visited, 'skipped' => $skipped, 'errors' => $errors, 'sites' => $sites]);
        $alerts = $input->getOption('no-alerts') ? null : $this->alerts($only, $output);
        flock($lockFile, LOCK_UN);
        if ($alerts !== null && $alerts !== []) {
            $output->writeln('Alertes envoyées : ' . implode(' ; ', $alerts));
        }
        $output->writeln(sprintf('[%s] Terminé : %d site(s) avec terminal, %d sans terminal ignoré(s)%s.', $startedAt->format(\DateTimeInterface::ATOM), $visited, $skipped, $errors > 0 ? sprintf(', %d en erreur', $errors) : ''));

        return Command::SUCCESS;
    }

    /**
     * Surveillance (M1) après la réconciliation : sites qui utilisent la caisse (au moins une session ouverte un jour), une fois par base ;
     * compte Stripe relu seulement pour un site en mode réel. Une erreur sur un site n'arrête pas les autres.
     *
     * @return list<string> « site : CODES » des alertes envoyées
     */
    private function alerts(?string $only, OutputInterface $output): array
    {
        $sent = [];
        $seen = [];
        foreach ($this->tenants->getAllTenants() as $tenant) {
            if (($only !== null && $tenant['code'] !== $only) || isset($seen[$tenant['dbname']])) {
                continue;
            }
            $seen[$tenant['dbname']] = true;
            try {
                $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
                $connection = $this->emProvider->getEntityManager()->getConnection();
                if (!$connection->fetchOne('SELECT 1 FROM caisse LIMIT 1')) {
                    continue;
                }
                $live = $connection->fetchOne("SELECT pos_stripe_mode FROM entreprise LIMIT 1") === 'live';
                $codes = $this->health->alert($this->health->report($live));
                if ($codes !== []) {
                    $sent[] = $tenant['code'] . ' : ' . implode(', ', $codes);
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s (surveillance) : %s</error>', $tenant['code'], $e->getMessage()));
            }
        }

        return $sent;
    }

    /** État daté de l'exécution : dernière exécution + historique borné, écriture atomique (fichier temporaire puis renommage) */
    private function writeStatus(array $run): void
    {
        $finishedAt = new \DateTimeImmutable();
        $run = ['startedAt' => $run['startedAt']->format(\DateTimeInterface::ATOM), 'finishedAt' => $finishedAt->format(\DateTimeInterface::ATOM),
            'durationMs' => (int) round(((float) $finishedAt->format('U.u') - (float) $run['startedAt']->format('U.u')) * 1000)] + $run;
        $path = self::statusPath($this->projectDir, $this->environment);
        $previous = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $history = array_slice(array_merge([array_diff_key($run, ['sites' => true])], is_array($previous['history'] ?? null) ? $previous['history'] : []), 0, self::HISTORY);
        $lastCompleted = in_array($run['outcome'], ['ok', 'partial'], true) ? $run : ($previous['lastCompleted'] ?? null);
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['last' => $run, 'lastCompleted' => $lastCompleted, 'history' => $history], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $path);
        }
    }

    private function inWindow(?string $from, ?string $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }
        $now = (new \DateTimeImmutable())->format('H:i');
        $from ??= '00:00';
        $to ??= '23:59';

        return $from <= $to ? ($now >= $from && $now <= $to) : ($now >= $from || $now <= $to);
    }
}
