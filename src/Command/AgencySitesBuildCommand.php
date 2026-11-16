<?php

namespace App\Command;

use App\Services\Agency\AgencySiteRequestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Création d'un site par une agence (lot AG-2, `docs/agences-et-import.md` § 8) : passage de rattrapage de la file. Les fabrications
 * interrompues (worker arrêté en cours de route) passent « en échec » ; les demandes restées « en attente » (message perdu, verrou
 * occupé) sont fabriquées, dans l'ordre, une à la fois. Sans demande, la commande ne fait rien. Sert aussi à répéter une fabrication.
 */
#[AsCommand(name: 'app:agency:sites:build', description: 'Fabrique les sites demandés par les agences restés en attente ; passe en échec les fabrications interrompues.')]
class AgencySitesBuildCommand extends Command
{
    public function __construct(private readonly AgencySiteRequestService $requests, private readonly ?\App\Services\Platform\Sites\PlatformSiteService $platformSites = null)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = $this->requests->drain();
        $output->writeln(sprintf('Demandes de site : %d fabrication(s) interrompue(s) passée(s) en échec, %d demande(s) en attente traitée(s).', $done['interrupted'], $done['processed']));
        // lot TYPE-1 : les demandes de la plateforme restées en attente, et les rôles de responsable qui attendaient une acceptation déjà faite
        if ($this->platformSites !== null) {
            $platform = $this->platformSites->drain();
            $output->writeln(sprintf('Demandes de la plateforme : %d interrompue(s) rendue(s) visible(s), %d en attente traitée(s), %d rôle(s) de responsable donné(s).', $platform['interrupted'], $platform['processed'], $platform['managers']));
        }

        return Command::SUCCESS;
    }
}
