<?php

namespace App\Command;

use App\Services\Platform\OfferPayments\OfferReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Paiements des offres (lot PROVISION-3, 18/11/2026, `docs/console-pilotage.md`) : sans option, la file de reprise (droits non posés,
 * toutes les 15 minutes) ; `--all`, chaque nuit : tous les abonnements branchés de la boutique de la plateforme comparés à Stripe et à leurs
 * droits, écarts corrigés et notés en incidents, journal des événements purgé à 90 jours.
 * Planification : ops/systemd/platform-offers-retry.timer et platform-offers-reconcile.timer (à installer).
 */
#[AsCommand(name: 'app:platform:offers:reconcile', description: 'Rejoue la file de reprise des droits vendus ; --all : réconciliation complète avec Stripe')]
final class PlatformOffersReconcileCommand extends Command
{
    public function __construct(private readonly OfferReconciler $reconciler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Tous les abonnements branchés (chaque nuit), après la file de reprise');
    }

    protected function execute(InputInterface $input, $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->reconciler->acquireRun()) {
            $io->writeln('Déjà en cours : rien à faire.');

            return Command::SUCCESS;
        }
        try {
            return $this->reconcile($input, $io);
        } finally {
            $this->reconciler->releaseRun();
        }
    }

    private function reconcile(InputInterface $input, SymfonyStyle $io): int
    {
        $retry = $this->reconciler->retryDue();
        $io->writeln(sprintf('File de reprise : %d réussie(s), %d en échec.', $retry['done'], $retry['failed']));
        if ($input->getOption('all')) {
            $all = $this->reconciler->reconcileAll();
            $io->writeln(sprintf('Réconciliation : %d abonnement(s) vu(s), %d état(s) corrigé(s) d\'après Stripe, %d droit(s) corrigé(s), %d en échec ; %d événement(s) purgé(s).',
                $all['checked'], $all['stripeFixed'], $all['rightsFixed'], $all['failed'], $all['purged']));
        }

        return Command::SUCCESS;
    }
}
