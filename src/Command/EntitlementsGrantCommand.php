<?php

namespace App\Command;

use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Accorde une fonctionnalité gouvernée à un site ou à tous (08/11/2026, IA bornée : conserver « IA complète » aux sites existants quand
 * l'assistant s'est mis à suivre les droits même en mode démo). Idempotent : un site qui a déjà le droit est laissé tel quel ; gmasuite
 * (modèle) est ignoré. Ex. : app:entitlements:grant ai.full --all --note="sites existants" ; --days=30 pose un essai au lieu d'un droit sans fin.
 */
#[AsCommand(name: 'app:entitlements:grant', description: 'Accorde une fonctionnalité gouvernée (module.*, app.*, ai.*) à un site (--tenant) ou à tous les sites (--all), sans fin ou en essai (--days)')]
final class EntitlementsGrantCommand extends Command
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantConnectionManager $tenants)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('feature', InputArgument::REQUIRED, 'Clé de la fonctionnalité (ex. ai.full)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code du site')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les sites de la table tenants (sauf gmasuite)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Essai de N jours au lieu d\'un droit sans fin')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Note portée sur le droit', 'app:entitlements:grant')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $feature = (string) $input->getArgument('feature');
        $codes = [];
        if ($input->getOption('all')) {
            foreach ($this->tenants->getAllTenants() as $t) {
                if ((string) $t['code'] !== 'gmasuite') {
                    $codes[] = (string) $t['code'];
                }
            }
        } elseif (is_string($input->getOption('tenant')) && $input->getOption('tenant') !== '') {
            $codes[] = (string) $input->getOption('tenant');
        } else {
            $output->writeln('<error>--tenant=code ou --all attendu</error>');

            return Command::INVALID;
        }
        $days = $input->getOption('days') !== null ? (int) $input->getOption('days') : null;
        $granted = $kept = 0;
        foreach ($codes as $code) {
            if ($this->entitlements->isEnabled($code, $feature)) {
                $output->writeln(sprintf('= %s : %s déjà accordé', $code, $feature));
                $kept++;
                continue;
            }
            if ($input->getOption('dry-run')) {
                $output->writeln(sprintf('~ %s : %s serait accordé', $code, $feature));
                continue;
            }
            $days !== null
                ? $this->entitlements->trial($code, $feature, $days, 'app:entitlements:grant', (string) $input->getOption('note'))
                : $this->entitlements->grant($code, $feature, 'manual', null, 'app:entitlements:grant', (string) $input->getOption('note'));
            $output->writeln(sprintf('+ %s : %s accordé%s', $code, $feature, $days !== null ? " ($days jours)" : ''));
            $granted++;
        }
        $output->writeln(sprintf('%d accordé(s), %d déjà en place.', $granted, $kept));

        return Command::SUCCESS;
    }
}
