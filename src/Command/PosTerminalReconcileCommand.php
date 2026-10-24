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
 */
#[AsCommand(name: 'app:pos:terminal-reconcile', description: 'Caisse : réconcilie les paiements par terminal Stripe en attente (autorisations expirées, paiements sans vente, remboursements)')]
class PosTerminalReconcileCommand extends Command
{
    private const LOCK_KEY = 7461004;

    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly PosTerminalPaymentService $payments, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Début de la plage horaire (HH:MM, heure du serveur) ; hors plage, la commande sort aussitôt')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Fin de la plage horaire (HH:MM)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Un seul site (code)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->inWindow($input->getOption('from'), $input->getOption('to'))) {
            $output->writeln('Hors plage horaire : rien à faire.');

            return Command::SUCCESS;
        }
        $lockFile = fopen($this->projectDir . '/var/pos-terminal-reconcile.lock', 'c');
        if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
            $output->writeln('<comment>Une réconciliation est déjà en cours : sortie.</comment>');

            return Command::SUCCESS;
        }
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
                if ($result['pending'] > 0 || $result['refunds'] > 0 || $output->isVerbose()) {
                    $output->writeln(sprintf('%s : %d en attente → %d autorisés, %d débités, %d annulés, %d refusés ; %d remboursements suivis', $tenant['code'],
                        $result['pending'], $result['authorized'], $result['captured'], $result['canceled'], $result['failed'], $result['refunds']));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s : %s</error>', $tenant['code'], $e->getMessage()));
            }
        }
        flock($lockFile, LOCK_UN);
        $output->writeln(sprintf('Terminé : %d site(s) avec terminal, %d sans terminal ignoré(s).', $visited, $skipped));

        return Command::SUCCESS;
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
