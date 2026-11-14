<?php

namespace App\LossPrevention\Command;

use App\LossPrevention\Services\LossAnalysisService;
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
 * Prévention des pertes (lot L1-a, 14/11/2026, `docs/prevention-pertes.md`) : un passage borné de l'analyse de la caisse sur chaque site dont
 * le module est ACCORDÉ (`entreprise.is_loss_prevention_active`). Lancée par le minuteur de l'hôte `pertes-analyse.timer` (ops/systemd/).
 * Un site sans le module n'est jamais analysé par le minuteur, même en mode « demo » de la plateforme : seul le droit accordé compte ici.
 * Après le passage d'un site : le courriel de résumé du jour, s'il est dû (`LossDigestService`, lot L1-b).
 * Un site en erreur n'arrête pas les autres ; un passage déjà en cours sur un site (verrou) est simplement sauté. La commande sort en échec
 * si un site est en erreur ou si une règle a dû sauter une entrée du journal de caisse (elle ne lui sera plus présentée).
 */
#[AsCommand(
    name: 'app:pertes:analyser',
    description: 'Analyse de la caisse pour la prévention des pertes (sites où le module est accordé, ou --tenant ; --passes pour rattraper un retard)'
)]
class LossAnalyseCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossAnalysisService $analysis,
        private readonly LoggerInterface $logger,
        private readonly ?\App\LossPrevention\Services\LossDigestService $digest = null, // lot L1-b : courriel de résumé du jour
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code d\'un seul site')
            ->addOption('passes', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de passages bornés par site (rattrapage)', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('tenant');
        $passes = max(1, min(50, (int) $input->getOption('passes')));
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sites = 0;
        $opened = 0;
        $errors = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            if ($only !== null && $row['code'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $row['dbname'], (string) $row['code']);
                if (!$this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT is_loss_prevention_active FROM entreprise ORDER BY id LIMIT 1')) {
                    continue; // module non accordé : rien, pas même une ligne de sortie
                }
                $read = 0;
                $siteOpened = 0;
                $siteSkipped = 0;
                $status = 'done';
                for ($i = 0; $i < $passes; ++$i) {
                    $result = $this->analysis->run();
                    $status = $result['status'];
                    $read += $result['read'];
                    $siteOpened += $result['opened'];
                    $siteSkipped += $result['skipped'];
                    if ($status !== 'done' || !$result['pending']) {
                        break;
                    }
                }
                ++$sites;
                $opened += $siteOpened;
                $skipped += $siteSkipped;
                $io->text(sprintf('%s : %s, %d entrée(s) lue(s), %d constat(s) ouvert(s), %d entrée(s) sautée(s)', $row['code'], $status, $read, $siteOpened, $siteSkipped));
                // Courriel de résumé du jour (lot L1-b) : au plus un par journée du magasin ; un échec d'envoi n'est pas une erreur de l'analyse
                try {
                    $digest = $this->digest?->sendIfDue();
                    if ($digest !== null && $digest['status'] === 'sent') {
                        $io->text(sprintf('%s : courriel de résumé envoyé à %d destinataire(s)', $row['code'], $digest['sent']));
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('Prévention des pertes : site %s, courriel de résumé en échec : %s', $row['code'], $e->getMessage()), ['exception' => $e]);
                }
                if ($siteSkipped > 0) {
                    $this->logger->error(sprintf('Prévention des pertes : site %s, %d entrée(s) du journal de caisse sautée(s) par une règle en échec', $row['code'], $siteSkipped));
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error(sprintf('Prévention des pertes : site %s non analysé : %s', $row['code'], $e->getMessage()), ['exception' => $e]);
                $io->warning(sprintf('%s : non analysé', $row['code']));
            }
        }
        $summary = sprintf('%d site(s) analysé(s), %d constat(s) ouvert(s), %d site(s) en erreur, %d entrée(s) sautée(s).', $sites, $opened, $errors, $skipped);
        if ($errors > 0 || $skipped > 0) { // une entrée sautée n'est plus jamais présentée aux règles : le minuteur doit sortir en échec
            $io->error($summary);

            return Command::FAILURE;
        }
        $io->success($summary);

        return Command::SUCCESS;
    }
}
