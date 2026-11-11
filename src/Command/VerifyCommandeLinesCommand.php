<?php

namespace App\Command;

use App\Gestion\Services\CommandeLinesVerifier;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `app:gestion:lignes:verifier [--tenant=code]` (lot L0, 10/11/2026) : sur chaque site (ou un seul), compare l'ancien calcul et le
 * calcul par lignes d'achat de tous les tableaux de bord et résultats de période (`CommandeLinesVerifier`). Lecture seule. Code de
 * sortie 1 au moindre écart de tableau de bord ou écart de résultat de période sans vente antérieure à l'achat.
 */
#[AsCommand(name: 'app:gestion:lignes:verifier', description: 'Vérifie que les lignes d\'achat rendent les mêmes chiffres que l\'ancien calcul (lecture seule)')]
class VerifyCommandeLinesCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly CommandeLinesVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un site (tous les sites sinon)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('tenant');
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC);
        $failed = false;
        $table = [];
        foreach ($rows as $row) {
            if ($only !== null && $row['code'] !== $only) {
                continue;
            }
            $this->emProvider->switchTenant((string) $row['dbname'], (string) $row['code']);
            $r = $this->verifier->verify();
            $explained = count($r['periodDiffs']) - $r['unexplained'];
            $ok = $r['dashboardDiffs'] === [] && $r['unexplained'] === 0;
            $failed = $failed || !$ok;
            $table[] = [$row['code'], $r['products'], $r['lines'], $r['dashboards'], count($r['dashboardDiffs']), $r['periods'], $explained, $r['unexplained'], $ok ? 'OK' : 'ÉCART'];
            foreach ($r['dashboardDiffs'] as $diff) {
                $io->writeln(sprintf('  [%s] tableau de bord %s', $row['code'], $diff));
            }
            foreach ($r['periodDiffs'] as $diff) {
                $io->writeln(sprintf('  [%s] résultat %s : %s ; ventes antérieures à l\'achat : %d%s', $row['code'], $diff['period'], implode(', ', $diff['fields']), $diff['salesBeforePurchase'], $diff['salesBeforePurchase'] > 0 ? ' (règle du coût à la date de la vente)' : ' (NON EXPLIQUÉ)'));
            }
        }
        $io->table(['site', 'articles rattachés', 'lignes', 'tableaux comparés', 'écarts', 'périodes', 'résultats expliqués', 'non expliqués', 'verdict'], $table);
        $failed ? $io->error('Écart : voir le détail ci-dessus.') : $io->success('Aucun écart : tableaux de bord identiques, résultats de période identiques ou expliqués.');

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
