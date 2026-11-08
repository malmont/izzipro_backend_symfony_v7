<?php

namespace App\Command;

use App\MemoiresVivantes\Services\ChapterInvitationService;
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
 * Invitations par e-mail des chapitres Mémoires Vivantes (08/11/2026) : sur chaque site, renvoie un lien pour les
 * invitations actives dont le lien est expiré, ou les déclare épuisées après le dernier renouvellement (avis au
 * propriétaire du livre). Lancée toutes les heures par le timer de l'hôte `memoires-invitations-renew.timer`
 * (ops/systemd/). Un site en erreur n'arrête pas les autres.
 */
#[AsCommand(
    name: 'app:memoires:invitations:renew',
    description: 'Renouvelle les liens d\'invitation expirés des chapitres Mémoires Vivantes (tous les sites, ou --tenant)'
)]
class MemoiresInvitationsRenewCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ChapterInvitationService $invitations,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('tenant');
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $totals = ['renewed' => 0, 'exhausted' => 0, 'failed' => 0];
        $errors = 0;

        foreach ($rows as $row) {
            if ($only !== null && $row['code'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $row['dbname'], (string) $row['code']);
                $result = $this->invitations->renewDue();
                foreach ($result as $key => $count) {
                    $totals[$key] += $count;
                }
                if (array_sum($result) > 0) {
                    $io->text(sprintf('%s : %d renouvelé(s), %d épuisé(s), %d envoi(s) raté(s)', $row['code'], $result['renewed'], $result['exhausted'], $result['failed']));
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error(sprintf('Invitations : site %s non traité : %s', $row['code'], $e->getMessage()));
                $io->warning(sprintf('%s : non traité (%s)', $row['code'], $e->getMessage()));
            }
        }

        $io->success(sprintf('%d lien(s) renouvelé(s), %d invitation(s) épuisée(s), %d envoi(s) raté(s), %d site(s) en erreur.', $totals['renewed'], $totals['exhausted'], $totals['failed'], $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
