<?php

namespace App\Command\Gemsuite;

use App\Entity\Booking;
use App\Entity\Order;
use App\Entity\User;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\Services\GemsuiteImporterService\GemsuiteSaleManager;
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
    name: 'app:gemsuite:retry-pending-sales',
    description: 'Re-synchronise sur GemSuite les commandes dont les bookings sont restés en PENDING_PAYMENT.',
)]
class RetrySyncPendingSalesCommand extends Command
{
    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private GemsuiteClientManager $gemsuiteClientManager,
        private GemsuiteSaleManager $gemsuiteSaleManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant', 't',
                InputOption::VALUE_REQUIRED,
                'Code du tenant cible (ex: expertnautique)'
            )
            ->addOption(
                'order-id', null,
                InputOption::VALUE_REQUIRED,
                'Cibler une commande spécifique par son ID local'
            )
            ->addOption(
                'since', null,
                InputOption::VALUE_REQUIRED,
                'Re-synchro à partir de cette date (format Y-m-d, ex: 2026-07-11). Par défaut: aujourd\'hui.'
            )
            ->addOption(
                'dry-run', null,
                InputOption::VALUE_NONE,
                'Simule sans appeler l\'API GemSuite'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun    = $input->getOption('dry-run');
        $targetTenant = $input->getOption('tenant');
        $targetOrderId = $input->getOption('order-id');
        $sinceDate    = $input->getOption('since');

        if (!$targetTenant) {
            $io->error('L\'option --tenant est obligatoire. Ex: --tenant=expertnautique');
            return Command::FAILURE;
        }

        // Date de départ : aujourd'hui par défaut
        try {
            $since = $sinceDate
                ? new \DateTimeImmutable($sinceDate . ' 00:00:00')
                : new \DateTimeImmutable('today 00:00:00');
        } catch (\Throwable $e) {
            $io->error('Format de date invalide. Utilisez Y-m-d (ex: 2026-07-11).');
            return Command::FAILURE;
        }

        $io->title(sprintf(
            'Re-synchronisation des ventes GemSuite [tenant: %s]%s%s',
            $targetTenant,
            $targetOrderId ? " [Order #$targetOrderId]" : " [depuis: " . $since->format('Y-m-d') . "]",
            $isDryRun ? ' [DRY-RUN]' : ''
        ));

        // Trouver la DB du tenant
        $tenantDbNames = $this->tenantManager->getAllTenantDbNames();
        $dbname = null;
        foreach ($tenantDbNames as $db) {
            $code = str_starts_with($db, 'db_') ? substr($db, 3) : $db;
            if ($code === $targetTenant) {
                $dbname = $db;
                break;
            }
        }

        if (!$dbname) {
            $io->error("Tenant '$targetTenant' introuvable. Vérifiez le code tenant.");
            return Command::FAILURE;
        }

        $tenant = $this->tenantManager->findTenantByCode($targetTenant);
        if (!$tenant) {
            $io->error("Impossible de charger la configuration du tenant '$targetTenant'.");
            return Command::FAILURE;
        }

        $token = $this->tenantManager->getTenantToken($targetTenant);
        if (!$token) {
            $io->error("Pas de token GemSuite pour '$targetTenant'. Vérifiez la configuration.");
            return Command::FAILURE;
        }

        $this->emProvider->switchTenant($dbname, $targetTenant);
        $em = $this->emProvider->getEntityManager();

        // ----------------------------------------------------------------
        // Construction de la requête : trouver les commandes avec bookings
        // restés en PENDING_PAYMENT (= jamais synchronisées sur GemSuite)
        // ----------------------------------------------------------------
        $qb = $em->createQueryBuilder()
            ->select('DISTINCT o')
            ->from(Order::class, 'o')
            ->join('o.orderItems', 'oi')
            ->join('oi.booking', 'b')
            ->where('b.status = :status')
            ->setParameter('status', 'PENDING_PAYMENT');

        if ($targetOrderId) {
            // Mode ciblé : une commande précise
            $qb->andWhere('o.id = :orderId')
               ->setParameter('orderId', (int) $targetOrderId);
        } else {
            // Mode par date : toutes les commandes depuis $since
            $qb->andWhere('o.orderDate >= :since')
               ->setParameter('since', \DateTime::createFromImmutable($since));
        }

        /** @var Order[] $orders */
        $orders = $qb->getQuery()->getResult();

        if (empty($orders)) {
            $io->success('Aucune commande en attente de synchronisation trouvée pour ces critères.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('%d commande(s) à re-synchroniser.', count($orders)));
        $io->newLine();

        $synced  = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            $user = $order->getUserId();
            $io->text(sprintf(
                '  ▶ Commande #%d (%s) — User: %s',
                $order->getId(),
                $order->getOrderDate()->format('Y-m-d H:i'),
                $user ? ($user->getEmail() ?? "User#{$user->getId()}") : 'Inconnu'
            ));

            if (!$user) {
                $io->warning('    ✗ Aucun utilisateur lié à cette commande. Ignorée.');
                $skipped++;
                continue;
            }

            // Étape 1 : s'assurer que l'utilisateur a un client GemSuite lié
            if (!$user->getGemsuiteClient()) {
                $io->text('    → Utilisateur sans client GemSuite. Tentative de liaison...');

                if ($isDryRun) {
                    $io->text('    [DRY-RUN] findOrCreateClient simulé.');
                } else {
                    try {
                        $gemsuiteClient = $this->gemsuiteClientManager->findOrCreateClient(
                            $user->getEmail(),
                            $user->getFirstname() ?? 'Utilisateur',
                            $user->getLastname() ?? 'Connecté',
                            $targetTenant
                        );

                        if ($gemsuiteClient) {
                            // Re-récupérer l'EM et l'user après switch interne potentiel
                            $em = $this->emProvider->getEntityManager();
                            $freshUser = $em->getRepository(User::class)->find($user->getId());
                            if ($freshUser && !$freshUser->getGemsuiteClient()) {
                                $freshUser->setGemsuiteClient($gemsuiteClient);
                                $em->persist($freshUser);
                                $em->flush();
                            }
                            // Recharger l'order pour avoir la relation user fraîche
                            $em->refresh($order);
                            $io->text(sprintf(
                                '    ✓ Client GemSuite #%d lié.',
                                $gemsuiteClient->getGemsuiteId()
                            ));
                        } else {
                            $io->warning('    ✗ Impossible de créer le client GemSuite (API down?). Commande ignorée.');
                            $failed++;
                            continue;
                        }
                    } catch (\Throwable $e) {
                        $io->error('    ✗ Erreur liaison client : ' . $e->getMessage());
                        $this->logger->error('[RetrySyncPendingSales] Erreur liaison client : ' . $e->getMessage(), [
                            'orderId' => $order->getId(),
                        ]);
                        $failed++;
                        continue;
                    }
                }
            } else {
                $io->text(sprintf(
                    '    ✓ Client GemSuite déjà lié (#%d).',
                    $user->getGemsuiteClient()->getGemsuiteId()
                ));
            }

            // Étape 2 : créer la vente sur GemSuite
            if ($isDryRun) {
                $io->text('    [DRY-RUN] createSale simulé.');
                $synced++;
                continue;
            }

            try {
                $em = $this->emProvider->getEntityManager();
                $em->refresh($order);

                $saleData = $this->gemsuiteSaleManager->createSale($order);

                if ($saleData) {
                    $io->text(sprintf(
                        '    ✓ Vente créée sur GemSuite (ID: %d).',
                        $saleData['id'] ?? 0
                    ));

                    // Mettre à jour le statut du booking en gemsuite_sync
                    foreach ($order->getOrderItems() as $item) {
                        $booking = $item->getBooking();
                        if ($booking && $booking->getStatus() === 'PENDING_PAYMENT') {
                            $booking->setStatus('gemsuite_sync');
                            $booking->setGemsuiteSaleId($saleData['id'] ?? null);
                            $booking->setIsFinalized(true);
                            $em->persist($booking);
                        }
                    }
                    $em->flush();

                    $synced++;
                } else {
                    $io->warning('    ✗ createSale a retourné null (client GemSuite manquant ou erreur API).');
                    $failed++;
                }
            } catch (\Throwable $e) {
                $io->error('    ✗ Erreur createSale : ' . $e->getMessage());
                $this->logger->error('[RetrySyncPendingSales] Erreur createSale : ' . $e->getMessage(), [
                    'orderId' => $order->getId(),
                ]);
                $failed++;
            }

            $io->newLine();
        }

        $io->success(sprintf(
            'Terminé. %d synchronisée(s), %d échouée(s), %d ignorée(s).',
            $synced,
            $failed,
            $skipped
        ));

        return ($failed > 0) ? Command::FAILURE : Command::SUCCESS;
    }
}
