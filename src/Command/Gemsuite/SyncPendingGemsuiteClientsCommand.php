<?php

namespace App\Command\Gemsuite;

use App\Entity\Booking;
use App\Entity\User;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gemsuite:sync-pending-clients',
    description: 'Rattrappage : lie un compte GemSuite aux utilisateurs locaux qui n\'en ont pas.',
)]
class SyncPendingGemsuiteClientsCommand extends Command
{
    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private GemsuiteClientManager $gemsuiteClientManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire en base')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Restreindre à un seul tenant (code)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');
        $targetTenant = $input->getOption('tenant');

        $io->title('Synchronisation des clients GemSuite manquants' . ($isDryRun ? ' [DRY-RUN]' : ''));

        $tenantDbNames = $this->tenantManager->getAllTenantDbNames();

        $totalFixed = 0;
        $totalFailed = 0;
        $totalPendingBookings = 0;

        foreach ($tenantDbNames as $dbname) {
            $tenantCode = $this->extractCodeFromDbName($dbname);

            if ($targetTenant && $tenantCode !== $targetTenant) {
                continue;
            }

            $io->section("Tenant : $dbname (code: $tenantCode)");

            $tenant = $this->tenantManager->findTenantByCode($tenantCode);
            if (!$tenant) {
                $io->warning("Tenant introuvable pour '$tenantCode'. Passage au suivant.");
                continue;
            }

            $token = $this->tenantManager->getTenantToken($tenantCode);
            if (!$token) {
                $io->error("Pas de token GemSuite pour '$tenantCode'. Passage au suivant.");
                continue;
            }

            $this->emProvider->switchTenant($dbname, $tenantCode);
            $em = $this->emProvider->getEntityManager();

            // Utilisateurs sans client GemSuite
            $usersWithoutClient = $em->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.gemsuiteClient IS NULL')
                ->andWhere('u.email IS NOT NULL')
                ->getQuery()
                ->getResult();

            $io->text(sprintf('%d utilisateur(s) sans client GemSuite.', count($usersWithoutClient)));

            if (empty($usersWithoutClient)) {
                $io->text('  → Rien à faire pour ce tenant.');
            } else {
                $fixed = 0;
                $failed = 0;

                foreach ($usersWithoutClient as $user) {
                    /** @var User $user */
                    $email     = $user->getEmail();
                    $firstName = $user->getFirstname() ?? 'Utilisateur';
                    $lastName  = $user->getLastname() ?? 'Connecté';

                    $io->text(sprintf('  - User #%d (%s)...', $user->getId(), $email));

                    if ($isDryRun) {
                        $io->text('    [DRY-RUN] Skipped.');
                        $fixed++;
                        continue;
                    }

                    try {
                        $gemsuiteClient = $this->gemsuiteClientManager->findOrCreateClient(
                            $email,
                            $firstName,
                            $lastName,
                            $tenantCode
                        );

                        if ($gemsuiteClient) {
                            // Re-récupérer l'EM après switch potentiel interne au GemsuiteClientManager
                            $em = $this->emProvider->getEntityManager();
                            $freshUser = $em->getRepository(User::class)->find($user->getId());

                            if ($freshUser && !$freshUser->getGemsuiteClient()) {
                                $freshUser->setGemsuiteClient($gemsuiteClient);
                                $em->persist($freshUser);
                                $em->flush();
                            }

                            $io->text(sprintf(
                                '    ✓ GemSuite Client #%d lié au User #%d.',
                                $gemsuiteClient->getGemsuiteId(),
                                $user->getId()
                            ));
                            $fixed++;
                        } else {
                            $io->warning(sprintf('    ✗ Impossible pour %s (token invalide ou API down?).', $email));
                            $failed++;
                        }
                    } catch (\Throwable $e) {
                        $io->error(sprintf('    ✗ Erreur pour %s : %s', $email, $e->getMessage()));
                        $this->logger->error('[SyncPendingGemsuiteClients] ' . $e->getMessage(), [
                            'userId' => $user->getId(),
                            'tenant' => $tenantCode,
                        ]);
                        $failed++;
                    }
                }

                $totalFixed  += $fixed;
                $totalFailed += $failed;
                $io->text(sprintf('  → %d lié(s), %d échoué(s).', $fixed, $failed));
            }

            // Stat bookings PENDING_PAYMENT restants (informatif)
            try {
                $em = $this->emProvider->getEntityManager();
                $pendingCount = (int) $em->createQueryBuilder()
                    ->select('COUNT(b.id)')
                    ->from(Booking::class, 'b')
                    ->where('b.status = :status')
                    ->setParameter('status', 'PENDING_PAYMENT')
                    ->getQuery()
                    ->getSingleScalarResult();

                $totalPendingBookings += $pendingCount;

                if ($pendingCount > 0) {
                    $io->note(sprintf(
                        '%d booking(s) toujours en PENDING_PAYMENT sur ce tenant.',
                        $pendingCount
                    ));
                }
            } catch (\Throwable $e) {
                // non-bloquant
            }
        }

        $io->success(sprintf(
            'Terminé. %d utilisateur(s) lié(s), %d échoué(s). Bookings PENDING_PAYMENT restants (tous tenants) : %d.',
            $totalFixed,
            $totalFailed,
            $totalPendingBookings
        ));

        return Command::SUCCESS;
    }

    private function extractCodeFromDbName(string $dbname): string
    {
        if (str_starts_with($dbname, 'db_')) {
            return substr($dbname, 3);
        }
        return $dbname;
    }
}
