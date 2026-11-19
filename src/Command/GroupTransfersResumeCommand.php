<?php

namespace App\Command;

use App\Services\Group\Transfer\GroupTransferService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lot G-5 : reprise des envois de transfert bloqués en `sending` depuis plus de 5 minutes (panne entre les deux écritures). Termine l'envoi
 * d'après les marques des sites, sans jamais sortir deux fois le stock ; destination encore en échec : compensation et `send_failed`.
 * Minuteur `ops/systemd/group-transfers-resume.{service,timer}` (toutes les 10 minutes), installé par le propriétaire.
 */
#[AsCommand(name: 'app:group:transfers:resume', description: 'Reprend les envois de transfert entre boutiques restés bloqués')]
class GroupTransfersResumeCommand extends Command
{
    public function __construct(private readonly GroupTransferService $transfers)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Transferts repris : %d', $this->transfers->resume()));

        return Command::SUCCESS;
    }
}
