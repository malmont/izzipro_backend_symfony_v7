<?php

namespace App\Command;

use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TeamRoleService\TeamRoleService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Équipe de la Boussole ESG (lot BOUSSOLE-CONSEILLER, 13/11/2026) : pose (ou retire avec --revoke) le rôle ROLE_BOUSSOLE_CONSEILLER sur
 * un compte existant d'un site, journalisé dans content_audit_log (ressource user_team, repère `console`). Au cas par cas, sur la liste
 * du propriétaire ; aucune attribution automatique. Même mécanisme que app:memoires:team:grant (convention commune des rôles d'équipe).
 */
#[AsCommand(name: 'app:boussole:team:grant', description: 'Pose ou retire (--revoke) le rôle de conseiller de la Boussole ESG sur un compte d\'un site')]
class BoussoleTeamGrantCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TeamRoleService $teamRoles
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant', InputArgument::REQUIRED, 'Code du site (table tenants)')
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse du compte existant')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Retirer le rôle au lieu de le poser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getArgument('tenant');
        $tenant = $this->tenants->findTenantByCode($code);
        if ($tenant === null) {
            $io->error(sprintf('Site inconnu : %s', $code));

            return Command::FAILURE;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $code);
        $user = $this->emProvider->getEntityManager()->getRepository(User::class)->findOneBy(['email' => trim((string) $input->getArgument('email'))]);
        if ($user === null) {
            $io->error('Aucun compte avec cette adresse sur ce site.');

            return Command::FAILURE;
        }
        $member = !$input->getOption('revoke');
        $changed = $this->teamRoles->set($user, TeamRoles::BOUSSOLE_CONSEILLER, $member, ['console']);
        $io->success(sprintf('%s : %s (rôles : %s).', $user->getEmail(), $changed ? ($member ? 'conseiller ajouté' : 'conseiller retiré') : 'déjà dans cet état, rien écrit', implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
