<?php

namespace App\Command;

use App\Entity\SharedMedia;
use App\Services\SharedMedia\ScrollVideoPreparer;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:prepare-scroll',
    description: 'Demande la préparation d\'une vidéo de la médiathèque pour une scène au défilement (traitée par le worker « media »)'
)]
class PrepareScrollVideoCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenantManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ScrollVideoPreparer $preparer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant', InputArgument::REQUIRED, 'Code du site (table tenants)')
            ->addArgument('media', InputArgument::REQUIRED, 'Identifiant du média partagé')
            ->addOption('again', null, InputOption::VALUE_NONE, 'Vidéo déjà préparée : repartir de la vidéo d\'origine (après un changement des réglages d\'encodage)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getArgument('tenant');
        $tenant = $this->tenantManager->findTenantByCode($code);
        if ($tenant === null || empty($tenant['dbname'])) {
            $io->error("Site « $code » introuvable.");

            return Command::INVALID;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $code);

        $media = $this->emProvider->getEntityManager()->getRepository(SharedMedia::class)->find((int) $input->getArgument('media'));
        if ($media instanceof SharedMedia && $input->getOption('again')) {
            $this->preparer->restore($media);
        }
        if (!$media instanceof SharedMedia || !$this->preparer->request($media)) {
            $io->error('Média introuvable, ou vidéo déjà préparée ou en cours de préparation.');

            return Command::FAILURE;
        }
        $io->success(sprintf('Préparation demandée pour « %s » : le worker « media » la traite en tâche de fond.', $media->getTitre()));

        return Command::SUCCESS;
    }
}
