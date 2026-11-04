<?php

namespace App\Command;

use App\Services\Platform\EntitlementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Balayage quotidien des droits d'usage (console de pilotage, lot 3, 04/11/2026) : essais échus → expirés, grâces dépassées →
 * suspendus (projection sur la fiche Entreprise, courriel au site), rappels à J-7 et J-1 une seule fois. Les lectures (isEnabled,
 * registre) expirent déjà d'elles-mêmes un droit échu : ce balayage sert aux courriels et aux sites qu'on ne lit pas.
 * Planification : ops/systemd/entitlements-sweep.timer (à installer, voir docs/console-pilotage.md).
 */
#[AsCommand(name: 'app:entitlements:sweep', description: 'Passe en expiré ou suspendu les droits d\'usage échus et envoie les rappels de fin d\'essai')]
final class EntitlementsSweepCommand extends Command
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $summary = $this->entitlements->sweep();
        $io->success(sprintf('Balayage : %d expiré(s), %d suspendu(s), %d rappel(s).', $summary['expired'], $summary['suspended'], $summary['reminded']));

        return Command::SUCCESS;
    }
}
