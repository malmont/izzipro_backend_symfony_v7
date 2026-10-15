<?php
namespace App\Services\CaisseService;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\Order;
use App\Entity\TransactionCaisse;
use App\Services\TenantEntityManagerProvider;

/** Sessions de caisse, par poste (CashRegister) depuis le 15/10/2026 */
class CaisseService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function getOpenCaisse(CashRegister $register): ?Caisse
    {
        return $this->emProvider->getEntityManager()->getRepository(Caisse::class)->findOpenSession($register);
    }

    public function getLastClosedCaisse(CashRegister $register): ?Caisse
    {
        return $this->emProvider->getEntityManager()->getRepository(Caisse::class)->getLastClosedCaisse($register);
    }

    /** @return list<Caisse> */
    public function getCaisse(?int $days = null, ?CashRegister $register = null): array
    {
        return $this->emProvider->getEntityManager()->getRepository(Caisse::class)->findSessions($days, $register);
    }

    /** @return list<TransactionCaisse> */
    public function getTransactionsForOpenCaisse(CashRegister $register): array
    {
        $openCaisse = $this->getOpenCaisse($register);
        if (!$openCaisse) {
            return [];
        }

        return $this->emProvider->getEntityManager()->getRepository(TransactionCaisse::class)->findBy(['caisse' => $openCaisse], ['id' => 'ASC']);
    }

    /** Session de la vente (première transaction de caisse de la commande), ouverte ou fermée */
    public function getCaisseOfOrder(Order $order): ?Caisse
    {
        return $this->emProvider->getEntityManager()->getRepository(TransactionCaisse::class)->findOneBy(['orderCaisse' => $order], ['id' => 'ASC'])?->getCaisse();
    }

    /** Session de la vente d'origine, si elle est encore ouverte */
    public function getOpenCaisseOfOrder(Order $order): ?Caisse
    {
        $caisse = $this->getCaisseOfOrder($order);

        return $caisse && $caisse->isOpen() ? $caisse : null;
    }
}
