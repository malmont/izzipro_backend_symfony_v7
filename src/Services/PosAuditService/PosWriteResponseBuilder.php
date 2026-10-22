<?php

namespace App\Services\PosAuditService;

use App\Dto\CashRegisterReportOutputDto;
use App\Dto\PosReceiptDto;
use App\Entity\Caisse;
use App\Entity\CashRegisterReport;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\PosAuditLog;
use App\Entity\PosReceipt;
use App\Services\CaisseService\CaisseService;
use App\Services\TenantEntityManagerProvider;

/**
 * Corps des réponses des écritures de caisse (chantier C5, 22/10/2026), partagé entre les contrôleurs et le rejeu
 * d'une clé d'idempotence dont la réponse n'a pas pu être gardée : la réponse est alors reconstituée depuis l'objet
 * créé (vente, annulation, session, rapport Z, tiroir), à l'identique. Montants : ceux des routes d'origine.
 */
class PosWriteResponseBuilder
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly CaisseService $caisseService, private readonly PosReceiptService $receipts)
    {
    }

    /** POST /api/order/create-multi-payment (201) */
    public function sale(Order $order, string $locale = 'fr'): array
    {
        $em = $this->emProvider->getEntityManager();

        return ['success' => true, 'orderId' => $order->getId(), 'message' => 'Commande créée.']
            + PosReceiptDto::fromOrder($order, $em->getRepository(Entreprise::class)->findOneBy([]), $this->caisseService->getCaisseOfOrder($order), $locale, $this->receipts->forOrder($order));
    }

    /** POST /api/order/cancel/{id} d'une vente au comptoir (200) : dernier avoir de la commande */
    public function cancel(Order $order): array
    {
        $creditNote = null;
        foreach ($this->emProvider->getEntityManager()->getRepository(PosReceipt::class)->findBy(['order' => $order, 'kind' => PosReceipt::CREDIT_NOTE], ['number' => 'DESC'], 1) as $receipt) {
            $creditNote = $receipt;
        }

        return ['message' => 'Order canceled and refunded successfully'] + ($creditNote ? [
            'creditNoteNumber' => $creditNote->getNumber(),
            'cashRegister' => ['id' => $creditNote->getCashRegister()->getId(), 'name' => $creditNote->getCashRegister()->getName()],
        ] : []);
    }

    /** POST /api/caisse/open (201) */
    public function open(Caisse $caisse): array
    {
        return [
            'message' => 'Caisse opened successfully', 'caisse_id' => $caisse->getId(), 'cashRegisterId' => $caisse->getCashRegister()->getId(),
            'amountTotal' => $caisse->getAmountTotal(), 'fonDeCaisse' => $caisse->getFonDeCaisse(), 'createdAt' => $caisse->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /** POST /api/caisse/close (200) */
    public function close(CashRegisterReport $report): array
    {
        return [
            'message' => 'Caisse closed successfully', 'caisse_id' => $report->getSession()->getId(), 'cashRegisterId' => $report->getCashRegister()->getId(),
            'report' => CashRegisterReportOutputDto::detail($report),
        ];
    }

    /** POST /api/caisse/deposit | withdraw | cashfunddeposit | cashfundwithdraw (201) */
    public function move(Caisse $caisse, string $message): array
    {
        return ['message' => $message, 'new_total' => $caisse->getAmountTotal(), 'cashRegisterId' => $caisse->getCashRegister()->getId()];
    }

    /** POST /api/caisse/drawer-open (201), depuis l'entrée de journal créée */
    public function drawer(PosAuditLog $entry): array
    {
        return ['auditId' => $entry->getId(), 'sequence' => $entry->getSequence(), 'occurredAt' => $entry->getOccurredAt()->format(\DateTimeInterface::ATOM), 'cashRegisterId' => $entry->getCashRegisterId()];
    }

    /** Réponse reconstituée d'une clé « committed » dont la réponse n'a pas été gardée ; null si l'objet n'existe plus */
    public function rebuild(string $subjectType, int $subjectId, string $locale = 'fr'): ?array
    {
        $em = $this->emProvider->getEntityManager();

        return match ($subjectType) {
            'sale' => ($o = $em->find(Order::class, $subjectId)) ? ['status' => 201, 'body' => $this->sale($o, $locale)] : null,
            'cancel' => ($o = $em->find(Order::class, $subjectId)) ? ['status' => 200, 'body' => $this->cancel($o)] : null,
            'session_open' => ($c = $em->find(Caisse::class, $subjectId)) ? ['status' => 201, 'body' => $this->open($c)] : null,
            'session_close' => ($z = $em->find(CashRegisterReport::class, $subjectId)) ? ['status' => 200, 'body' => $this->close($z)] : null,
            'drawer' => ($a = $em->find(PosAuditLog::class, $subjectId)) ? ['status' => 201, 'body' => $this->drawer($a)] : null,
            default => null,
        };
    }
}
