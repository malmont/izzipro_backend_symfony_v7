<?php

namespace App\Command;

use App\Entity\Order;
use App\Services\OrderService\OrderShipmentService;
use App\Services\ShippingService\ShippingCarrierGatewayInterface;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Suivi automatique des colis (lot 2 Livraison, 07/11/2026) : pour chaque site, les commandes « En cours de livraison » dont les
 * étiquettes ont été achetées chez EasyPost sont interrogées ; livrées → « Livrée » (OrderShipmentService::deliver). Les commandes
 * expédiées à la main (suivi saisi) ne sont pas interrogées : EasyPost ne connaît pas leur transporteur. Unité systemd :
 * ops/systemd/shipping-track.timer (toutes les 6 h), à installer par le propriétaire.
 */
#[AsCommand(name: 'app:shipping:track', description: 'Interroge EasyPost pour les commandes expédiées avec étiquette et passe « Livrée » celles qui le sont')]
final class ShippingTrackCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantEntityManagerProvider $emProvider, private readonly ShippingCarrierGatewayInterface $gateway, private readonly OrderShipmentService $shipments, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Un seul site (code)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = $input->getOption('tenant');
        $rows = $this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $delivered = 0;
        $checked = 0;
        foreach ($rows as $t) {
            if ($only !== null && $t['code'] !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant($t['dbname'], $t['code']);
                $em = $this->emProvider->getEntityManager();
                if (!$this->gateway->hasConfiguration()) {
                    continue;
                }
                $ids = $em->getConnection()->fetchFirstColumn('SELECT o.id FROM "order" o JOIN shipping_order so ON so.odershipping_id = o.id WHERE o.status_id = ? ORDER BY o.id', [OrderShipmentService::STATUS_SHIPPED]);
                foreach ($ids as $id) {
                    $order = $em->getRepository(Order::class)->find((int) $id);
                    if (!$order instanceof Order || $order->getShippingOrder() === null) {
                        continue;
                    }
                    $allDelivered = true;
                    $any = false;
                    foreach ($order->getShippingOrder()->getParcels() as $parcel) {
                        $code = $parcel->getShippingLabel()?->getTrackingCode();
                        if (!$code) {
                            continue;
                        }
                        $any = true;
                        $checked++;
                        $status = $this->gateway->track($code, null)['status'] ?? null;
                        if ($status !== 'delivered') {
                            $allDelivered = false;
                        }
                    }
                    if ($any && $allDelivered && $this->shipments->deliverBlocker($order) === null) {
                        $this->shipments->deliver($order);
                        $delivered++;
                        $output->writeln(sprintf('%s : commande %s livrée', $t['code'], $order->getReference()));
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[Suivi] %s : %s', $t['code'], $e->getMessage()));
                $output->writeln(sprintf('<comment>%s : %s</comment>', $t['code'], $e->getMessage()));
            }
        }
        $output->writeln(sprintf('%d colis interrogé(s), %d commande(s) livrée(s).', $checked, $delivered));

        return Command::SUCCESS;
    }
}
