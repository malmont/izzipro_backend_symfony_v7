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

            // --- PASSE 1 : Bookings créés depuis le front (avec gemsuiteSaleId) ---
            // On vérifie si la vente Gemsuite existe encore ou a été facturée
            $oneHourAgo = (new \DateTimeImmutable('-1 hour'))->setTimezone(new \DateTimeZone('UTC'));

            $pendingBookings = $em->getRepository(Booking::class)->createQueryBuilder('b')
                ->where('b.isFinalized = :finalized')
                ->andWhere('b.gemsuiteSaleId IS NOT NULL')
                ->andWhere('b.createdAt < :oneHourAgo')
                ->setParameter('finalized', false)
                ->setParameter('oneHourAgo', $oneHourAgo)
                ->getQuery()
                ->getResult();

            $io->text(sprintf("Passe 1 : %d réservations avec saleId à vérifier...", count($pendingBookings)));

            foreach ($pendingBookings as $booking) {
                $saleId = $booking->getGemsuiteSaleId();
                $io->text(" - Vérification Vente #$saleId...");

                $saleData = $this->syncHandler->getExternalSaleData($tenantCode, $saleId);

                if ($saleData === null) {
                    $io->warning("   -> Vente introuvable ou supprimée. Libération du créneau.");
                    $em->remove($booking);
                    $em->flush();
                    $io->success("   -> Booking supprimé.");

                    $vehicle = $em->getRepository(Vehicle::class)->findOneBy(['product' => $booking->getProduct()]);
                    if ($vehicle) {
                        $this->syncHandler->handleRentalUpdate($tenantCode, $vehicle->getGemsuiteVehicleId());
                        $io->success("   -> Calendrier véhicule mis à jour.");
                    }
                } elseif (!empty($saleData['invoice_number'])) {
                    $booking->setIsFinalized(true);
                    $em->flush();
                    $io->success("   -> Booking finalisé (Facture #{$saleData['invoice_number']})");
                } else {
                    $io->text("   -> Toujours en attente (Estimé).");
                }
            }

            // --- PASSE 2 : Bookings synchronisés depuis Gemsuite (sans gemsuiteSaleId) ---
            // On vérifie uniquement les créneaux futurs (à partir d'aujourd'hui)
            // pour s'assurer qu'ils existent encore dans le calendrier Gemsuite
            $twoHoursAgo  = (new \DateTimeImmutable('-2 hours'))->setTimezone(new \DateTimeZone('UTC'));
            $todayStart   = (new \DateTimeImmutable('today'))->setTimezone(new \DateTimeZone('UTC'));

            $syncedBookings = $em->getRepository(Booking::class)->createQueryBuilder('b')
                ->where('b.status = :status')
                ->andWhere('b.gemsuiteSaleId IS NULL')
                ->andWhere('b.startAt >= :todayStart')
                ->andWhere('b.createdAt < :twoHoursAgo')
                ->setParameter('status', 'gemsuite_sync')
                ->setParameter('todayStart', $todayStart)
                ->setParameter('twoHoursAgo', $twoHoursAgo)
                ->getQuery()
                ->getResult();

            $io->text(sprintf("Passe 2 : %d créneaux synchronisés (futurs) à vérifier...", count($syncedBookings)));

            // On regroupe par véhicule pour limiter les appels API
            $vehicleAppointmentsCache = [];

            foreach ($syncedBookings as $booking) {
                $vehicle = $em->getRepository(Vehicle::class)->findOneBy(['product' => $booking->getProduct()]);
                if (!$vehicle) {
                    continue;
                }

                $vehicleId = $vehicle->getGemsuiteVehicleId();

                // On ne rappelle l'API qu'une seule fois par véhicule (mise en cache)
                if (!isset($vehicleAppointmentsCache[$vehicleId])) {
                    $vehicleAppointmentsCache[$vehicleId] = $this->syncHandler->fetchRentalsForVehiclePublic($tenantCode, $vehicleId);
                }

                $appointments = $vehicleAppointmentsCache[$vehicleId];
                $tz = new \DateTimeZone('America/Toronto');

                // On vérifie si le créneau du booking existe encore dans la liste Gemsuite
                $found = false;
                foreach ($appointments as $appt) {
                    if (empty($appt['start']) || empty($appt['end'])) {
                        continue;
                    }
                    $apptStart = (new \DateTimeImmutable($appt['start'], $tz))->format('Y-m-d H:i');
                    $apptEnd   = (new \DateTimeImmutable($appt['end'], $tz))->format('Y-m-d H:i');

                    $bookingStart = (clone $booking->getStartAt())->setTimezone($tz)->format('Y-m-d H:i');
                    $bookingEnd   = (clone $booking->getEndAt())->setTimezone($tz)->format('Y-m-d H:i');

                    if ($apptStart === $bookingStart && $apptEnd === $bookingEnd) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $io->warning(sprintf(
                        "   -> Créneau %s-%s introuvable dans Gemsuite. Suppression.",
                        (clone $booking->getStartAt())->setTimezone($tz)->format('H:i'),
                        (clone $booking->getEndAt())->setTimezone($tz)->format('H:i')
                    ));
                    $em->remove($booking);
                }
            }

            $em->flush();
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
