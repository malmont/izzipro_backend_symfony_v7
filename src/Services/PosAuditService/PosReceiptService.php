<?php

namespace App\Services\PosAuditService;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\Order;
use App\Entity\PosReceipt;
use App\Services\TenantEntityManagerProvider;

/**
 * Numéros de tickets et d'avoirs (chantier C2, 19/10/2026), deux suites par poste. Ordre des verrous (évite les
 * interblocages avec la fermeture) : ligne du poste (lockRegister) AVANT la ligne de la session, puis le journal.
 */
class PosReceiptService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** Verrouille la ligne du poste jusqu'à la fin de la transaction en cours */
    public function lockRegister(CashRegister $register): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeQuery('SELECT id FROM cash_register WHERE id = ? FOR UPDATE', [$register->getId()]);
    }

    /** Attribue le numéro suivant (sous verrou du poste, dans la transaction de l'opération) ; la commande doit avoir un id */
    public function issue(Order $order, Caisse $session, string $kind, int $amountCents): PosReceipt
    {
        $em = $this->emProvider->getEntityManager();
        $register = $session->getCashRegister();
        $this->lockRegister($register);
        $receipt = new PosReceipt($register, $kind, $em->getRepository(PosReceipt::class)->nextNumber($register, $kind), $order, $session, $amountCents);
        $em->persist($receipt);
        $em->flush();

        return $receipt;
    }

    /** @return array<string, PosReceipt> genre → numéro, pour une commande */
    public function forOrder(Order $order): array
    {
        return $this->emProvider->getEntityManager()->getRepository(PosReceipt::class)->forOrders([(int) $order->getId()])[$order->getId()] ?? [];
    }
}
