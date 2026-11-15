<?php

namespace App\LossPrevention\Command;

use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
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
 * Prévention des pertes, lot EX-1 (`docs/prevention-pertes.md` § 8, « Retour arrière » ; `docs/mise-en-ligne.md`) : efface les constats
 * d'exercice d'un site ou de tous les sites, par la seule porte que la base leur laisse (preuves, journal, constats, dans cet ordre).
 *
 * À JOUER AVANT TOUT RETOUR À UNE VERSION DU CODE D'AVANT EX-1 : l'ancien code ne connaît pas la marque `exercise` ; un exercice resté en
 * base y deviendrait un vrai constat (synthèse, liste, vignette, courriel du jour) et ne pourrait plus être effacé. La commande ne touche
 * jamais un vrai constat (la base le refuse elle-même). `--dry-run` compte sans rien effacer. Un site en erreur n'arrête pas les autres ;
 * la commande sort alors en échec : le retour arrière ne doit pas se faire tant qu'un site garde des exercices.
 */
#[AsCommand(
    name: 'app:pertes:exercices:effacer',
    description: 'Efface les constats d\'exercice de la prévention des pertes (--tenant=<code> ou --all ; --dry-run pour compter) : à jouer avant tout retour à un code d\'avant EX-1'
)]
class LossExercisePurgeCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les sites')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les constats d\'exercice sans rien effacer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('tenant');
        $all = (bool) $input->getOption('all');
        if (($only === null) === !$all) { // l'un ou l'autre, jamais aucun ni les deux : pas d'effacement de tous les sites par oubli d'une option
            $io->error('Indiquez --tenant=<code> ou --all.');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sites = 0;
        $total = 0;
        $errors = 0;
        foreach ($rows as $row) {
            if ($only !== null && $row['code'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $row['dbname'], (string) $row['code']);
                $em = $this->emProvider->getEntityManager();
                $findings = $em->getRepository(LossFinding::class);
                $count = $findings->exerciseCount();
                ++$sites;
                if ($count === 0) {
                    continue;
                }
                if (!$dryRun) {
                    $count = $findings->deleteExercises();
                    $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::EXERCISES_DELETED, null, null, ['count' => $count, 'by' => 'command']);
                }
                $total += $count;
                $io->text(sprintf('%s : %d constat(s) d\'exercice %s', $row['code'], $count, $dryRun ? 'présent(s)' : 'effacé(s)'));
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error(sprintf('Prévention des pertes : site %s, effacement des exercices en échec : %s', $row['code'], $e->getMessage()), ['exception' => $e]);
                $io->warning(sprintf('%s : en échec', $row['code']));
            }
        }
        if ($only !== null && $sites === 0 && $errors === 0) {
            $io->error(sprintf('Site « %s » inconnu.', $only));

            return Command::FAILURE;
        }
        $summary = sprintf('%d site(s) lu(s), %d constat(s) d\'exercice %s, %d site(s) en erreur.', $sites, $total, $dryRun ? 'présent(s)' : 'effacé(s)', $errors);
        if ($errors > 0) {
            $io->error($summary);

            return Command::FAILURE;
        }
        $io->success($summary);

        return Command::SUCCESS;
    }
}
