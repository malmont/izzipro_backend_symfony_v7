<?php

namespace App\Command;

use App\Services\Platform\Sites\PlatformSiteException;
use App\Services\Platform\Sites\PlatformSiteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lot TYPE-1 depuis la CONSOLE DU SERVEUR (16/11/2026, décision du chef de projet) : le super administrateur dépose une demande de
 * création de site (normal ou agence) par le MÊME service et les MÊMES contrôles que `POST /api/platform/sites` ; la demande est tracée
 * « console du serveur ». Aucune route ne l'expose. La fabrication suit par le worker (ou `app:agency:sites:build`). La sortie ne montre
 * ni lien, ni mot de passe, ni l'adresse en clair. Un site, une base, un domaine ou une demande déjà là : refus, rien n'est créé.
 */
#[AsCommand(name: 'app:platform:site:request', description: 'Dépose une demande de création de site (normal ou agence) au nom de la plateforme, par le service de TYPE-1.')]
class PlatformSiteRequestCommand extends Command
{
    public const BY = 'console du serveur';

    public function __construct(private readonly PlatformSiteService $sites)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'normal ou agency', 'normal')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'code du site')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'nom du site')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'adresse de l\'administrateur (« - » : lue sur l\'entrée standard)')
            ->addOption('admin-first-name', null, InputOption::VALUE_REQUIRED, 'prénom de l\'administrateur', '')
            ->addOption('admin-last-name', null, InputOption::VALUE_REQUIRED, 'nom de l\'administrateur', '')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'fr ou en')
            ->addOption('agency-name', null, InputOption::VALUE_REQUIRED, 'agence : nom')
            ->addOption('agency-code', null, InputOption::VALUE_REQUIRED, 'agence : code (défaut : celui du site)')
            ->addOption('test', null, InputOption::VALUE_NONE, 'agence : agence d\'essai');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = SiteRequestEmail::read($input);
        $data = ['type' => (string) $input->getOption('type'), 'code' => (string) $input->getOption('code'), 'name' => (string) $input->getOption('name'), 'adminEmail' => $email,
            'adminFirstName' => (string) $input->getOption('admin-first-name'), 'adminLastName' => (string) $input->getOption('admin-last-name'), 'locale' => $input->getOption('locale')];
        if ($data['type'] === PlatformSiteService::AGENCY) {
            $data['agency'] = ['name' => (string) ($input->getOption('agency-name') ?? $input->getOption('name')), 'isTest' => (bool) $input->getOption('test')]
                + ($input->getOption('agency-code') !== null ? ['code' => (string) $input->getOption('agency-code')] : []);
        }
        try {
            $request = $this->sites->request($data, self::BY);
        } catch (PlatformSiteException $e) {
            $output->writeln(sprintf('<error>Refusé : %s (%s)</error>', $e->errorCode, $e->getMessage()));
            foreach ($e->extra['errors'] ?? [] as $error) {
                $output->writeln(sprintf('  - %s : %s', $error['path'] ?? '?', $error['message'] ?? ''));
            }

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Demande n° %d déposée : site « %s » (%s), type %s, administrateur %s, statut %s. La fabrication suit par le worker ; état : `app:agency:sites:build` ou l\'écran « Plateforme : sites ».',
            (int) $request['id'], $data['name'], $data['code'], $data['type'], SiteRequestEmail::mask($email), (string) ($request['status'] ?? '?')));

        return Command::SUCCESS;
    }
}
