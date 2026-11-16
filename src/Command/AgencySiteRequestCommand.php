<?php

namespace App\Command;

use App\Services\Agency\AgencyException;
use App\Services\Agency\AgencyRepository;
use App\Services\Agency\AgencySiteRequestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lot AG-2 depuis la CONSOLE DU SERVEUR (16/11/2026, décision du chef de projet) : dépose, au nom d'une agence, une demande de création
 * du site d'un client, par le MÊME service et les MÊMES contrôles que `POST /api/agency/sites` (agence vivante, limites, code libre) ;
 * tracée « console du serveur » au journal de l'agence. Aucune route ne l'expose. Le site naît en construction. La sortie ne montre ni
 * lien, ni mot de passe, ni l'adresse en clair.
 */
#[AsCommand(name: 'app:agency:site:request', description: 'Dépose, au nom d\'une agence, une demande de création du site d\'un client, par le service d\'AG-2.')]
class AgencySiteRequestCommand extends Command
{
    public function __construct(private readonly AgencyRepository $agencies, private readonly AgencySiteRequestService $requests)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('agency', null, InputOption::VALUE_REQUIRED, 'code de l\'agence')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'code du site du client')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'nom du site du client')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'adresse de l\'administrateur du client (« - » : lue sur l\'entrée standard)')
            ->addOption('admin-first-name', null, InputOption::VALUE_REQUIRED, 'prénom', '')
            ->addOption('admin-last-name', null, InputOption::VALUE_REQUIRED, 'nom', '')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'fr ou en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agency = $this->agencies->findByCode((string) $input->getOption('agency'));
        if ($agency === null) {
            $output->writeln('<error>Refusé : agence inconnue.</error>');

            return Command::FAILURE;
        }
        $email = SiteRequestEmail::read($input);
        $data = ['code' => (string) $input->getOption('code'), 'name' => (string) $input->getOption('name'), 'adminEmail' => $email,
            'adminFirstName' => (string) $input->getOption('admin-first-name'), 'adminLastName' => (string) $input->getOption('admin-last-name'), 'locale' => $input->getOption('locale')];
        try {
            $request = $this->requests->request($agency, $data, PlatformSiteRequestCommand::BY);
        } catch (AgencyException $e) {
            $output->writeln(sprintf('<error>Refusé : %s (%s)</error>', $e->errorCode, $e->getMessage()));
            foreach ($e->extra['errors'] ?? [] as $error) {
                $output->writeln(sprintf('  - %s : %s', $error['path'] ?? '?', $error['message'] ?? ''));
            }

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Demande n° %d déposée par l\'agence « %s » : site « %s » (%s), administrateur %s, statut %s. La fabrication suit par le worker ; état : `app:agency:sites:build`.',
            (int) $request['id'], (string) $agency['name'], $data['name'], $data['code'], SiteRequestEmail::mask($email), (string) ($request['status'] ?? '?')));

        return Command::SUCCESS;
    }
}
