<?php

namespace App\Command\Gemsuite;

use App\Entity\Booking;
use App\Entity\Vehicle;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'app:gemsuite:cleanup-bookings',
    description: 'Vérifie les estimations de location en attente et les nettoie si elles sont annulées dans Gemsuite.',
)]
class CleanupPendingBookingsCommand extends Command
{
    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private GemsuiteSyncHandler $syncHandler,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantDbNames = $this->tenantManager->getAllTenantDbNames();

        $io->title('Lancement du nettoyage intelligent des réservations en attente');

        foreach ($tenantDbNames as $dbname) {
            $io->section("Tenant : $dbname");
            
            // On récupère le code tenant à partir du nom de la base
            // (Une approche plus propre serait d'avoir le code dans getAllTenantDbNames)
            $tenant = $this->tenantManager->findTenantByCode($this->extractCodeFromDbName($dbname));
            if (!$tenant) {
                // Fallback si on ne trouve pas par code
                $io->warning("Impossible de trouver le code tenant pour la base $dbname. Passage au suivant.");
                continue;
            }

            $tenantCode = $tenant['code'];
            $this->emProvider->switchTenant($dbname, $tenantCode);
            $em = $this->emProvider->getEntityManager();
            
            $token = $this->tenantManager->getTenantToken($tenantCode);
            if (!$token) {
                $io->error("Pas de token pour $tenantCode.");
                continue;
            }

            // 1. Récupérer les bookings en attente (Estimations)
            // On prend ceux de plus d'une heure (3600 secondes)
            $oneHourAgo = (new \DateTimeImmutable('-1 hour'))->setTimezone(new \DateTimeZone('UTC'));
            
            $pendingBookings = $em->getRepository(Booking::class)->createQueryBuilder('b')
                ->where('b.isFinalized = :finalized')
                ->andWhere('b.gemsuiteSaleId IS NOT NULL')
                ->andWhere('b.createdAt < :oneHourAgo')
                ->setParameter('finalized', false)
                ->setParameter('oneHourAgo', $oneHourAgo)
                ->getQuery()
                ->getResult();

            if (empty($pendingBookings)) {
                $io->text("Aucune réservation en attente pour ce tenant.");
                continue;
            }

            $io->text(sprintf("Traitement de %d réservations en attente...", count($pendingBookings)));

            foreach ($pendingBookings as $booking) {
                $saleId = $booking->getGemsuiteSaleId();
                $io->text(" - Vérification Vente #$saleId...");

                // Appel ciblé à l'API Gemsuite pour cette vente
                $saleData = $this->syncHandler->getExternalSaleData($tenantCode, $saleId);

                if ($saleData === null) {
                    // La vente n'existe plus ou erreur réseau
                    $io->warning("   -> Vente introuvable ou supprimée. Libération du créneau.");
                    
                    // On supprime d'abord le booking local en attente
                    $em->remove($booking);
                    $em->flush();
                    $io->success("   -> Booking supprimé manuellement.");
                    
                    // On force un rafraîchissement complet du calendrier du véhicule pour être sûr
                    $vehicle = $em->getRepository(Vehicle::class)->findOneBy(['product' => $booking->getProduct()]);
                    if ($vehicle) {
                        $this->syncHandler->handleRentalUpdate($tenantCode, $vehicle->getGemsuiteVehicleId());
                        $io->success("   -> Calendrier véhicule mis à jour.");
                    }
                } elseif (!empty($saleData['invoice_number'])) {
                    // La vente est devenue une facture
                    $booking->setIsFinalized(true);
                    $em->flush();
                    $io->success("   -> Booking finalisé (Facture #{$saleData['invoice_number']})");
                } else {
                    $io->text("   -> Toujours en attente (Estimé).");
                }
            }
        }

        $io->success('Fin du nettoyage des réservations.');

        return Command::SUCCESS;
    }

    private function extractCodeFromDbName(string $dbname): string
    {
        // Dans ce projet, le nom de la DB commence souvent par db_ suivi du code
        if (str_starts_with($dbname, 'db_')) {
            return substr($dbname, 3);
        }
        return $dbname;
    }
}
