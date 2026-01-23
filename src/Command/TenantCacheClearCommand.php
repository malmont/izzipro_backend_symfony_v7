<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[AsCommand(
    name: 'tenant:cache:clear',
    description: 'Vide le cache de résolution des tenants (Redis).',
)]
class TenantCacheClearCommand extends Command
{
    public function __construct(
        private TagAwareCacheInterface $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Optionnel : Si un jour tu veux vraiment cibler (mais je te le déconseille pour l'instant)
        // $this->addOption('code', null, InputOption::VALUE_OPTIONAL, 'Le code du tenant à vider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // C'est ici que la magie des Tags opère.
        // On supprime d'un coup TOUTES les entrées qui ont été marquées avec le tag 'tenants'
        // dans ton TenantConnectionManager.
        $this->cache->invalidateTags(['tenants']);

        $io->success('Le cache de résolution des tenants a été vidé avec succès (Tag: "tenants").');
        $io->info('Les prochaines visites déclencheront une requête SQL sur la base Master pour se mettre à jour.');

        return Command::SUCCESS;
    }
}