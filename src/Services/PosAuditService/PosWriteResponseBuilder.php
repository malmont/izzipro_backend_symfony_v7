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
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly CaisseService $caisseService, private readonly PosReceiptService $receipts, private readonly \App\Services\PosTerminalService\PosTerminalPaymentService $terminalPayments, private readonly \App\Services\PosReturnService\PosReturnService $returns, private readonly PosReceiptLinkService $links,
        private readonly \App\Services\PosGiftCardService\PosGiftCardService $giftCards) // cartes-cadeaux et avoirs (F9, 27/10/2026)
    {
    }

    /** POST /api/order/create-multi-payment (201) */
    public function sale(Order $order, string $locale = 'fr'): array
    {
        $em = $this->emProvider->getEntityManager();

        return ['success' => true, 'orderId' => $order->getId(), 'message' => 'Commande créée.'] + $this->receiptBlock($order, $locale);
    }

    /** Bloc ticket complet d'une commande (F3) : ticket de vente par défaut, ou le reçu donné (avoir), duplicata ou non */
    public function receiptBlock(Order $order, string $locale = 'fr', ?PosReceipt $receipt = null, bool $duplicate = false): array
    {
        $em = $this->emProvider->getEntityManager();
        $receipts = $this->receipts->forOrder($order);
        $receipt ??= $receipts[PosReceipt::TICKET] ?? $receipts[PosReceipt::CREDIT_NOTE] ?? null;

        $receipt ??= $receipts[PosReceipt::GIFT_CARD] ?? null;
        // Cartes-cadeaux (F9) : parts réglées par carte ou avoir, carte émise par cette commande (type 3), avoir émis par ce retour (4 derniers caractères seulement)
        $giftCards = ['redemptions' => $this->giftCards->redemptionsOf($order), 'issued' => null, 'storeCredit' => null];
        if ($order->getOrderType()?->getId() === 3) {
            $issued = $em->getRepository(\App\Entity\PosGiftCard::class)->findOneBy(['issuedOrderId' => $order->getId()], ['id' => 'ASC']);
            $giftCards['issued'] = $issued ? $this->giftCards->toArray($issued) : null;
        }
        $return = $em->getRepository(\App\Entity\PosReturn::class)->findOneBy(['returnOrder' => $order]);
        if ($return?->getStoreCreditId()) {
            $credit = $em->find(\App\Entity\PosGiftCard::class, $return->getStoreCreditId());
            $giftCards['storeCredit'] = $credit ? $this->giftCards->toArray($credit) : null;
        }

        return PosReceiptDto::fromOrder($order, $em->getRepository(Entreprise::class)->findOneBy([]), $this->caisseService->getCaisseOfOrder($order), $locale, $receipts,
            $this->terminalPayments->findByOrder((int) $order->getId()), $receipt ? $this->links->url($receipt) : null, $duplicate, $receipt, $giftCards);
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
            'creditNoteReference' => $creditNote->getCashRegister()->receiptReference($creditNote->getNumber()),
            'barcode' => \App\Services\PosReturnService\PosReturnService::barcode($creditNote),
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
            // Paiement par terminal (C4) : état courant du paiement (création 201, annulation ou remboursement 200)
            'terminal_payment' => ($t = $em->find(\App\Entity\PosTerminalPayment::class, $subjectId)) ? ['status' => 201, 'body' => $this->terminalPayments->toArray($t)] : null,
            'terminal_update' => ($t = $em->find(\App\Entity\PosTerminalPayment::class, $subjectId)) ? ['status' => 200, 'body' => $this->terminalPayments->toArray($t)] : null,
            // Retour partiel et échange (F2) : réponse reconstituée depuis le retour (et la vente d'échange)
            'return' => ($r = $em->find(\App\Entity\PosReturn::class, $subjectId)) ? ['status' => 201, 'body' => $this->returns->toArray($r, $locale)] : null,
            'exchange' => ($r = $em->find(\App\Entity\PosReturn::class, $subjectId)) && $r->getExchangeOrder() ? ['status' => 201, 'body' => ['return' => $this->returns->toArray($r, $locale), 'sale' => $this->sale($r->getExchangeOrder(), $locale)]] : null,
            // Reçu envoyé par courriel (F3) : rejeu sans second courriel
            'receipt_email' => ($rc = $em->find(PosReceipt::class, $subjectId)) ? ['status' => 200, 'body' => ['sent' => true, 'barcode' => \App\Services\PosReturnService\PosReturnService::barcode($rc), 'replayed' => true]] : null,
            // Cartes-cadeaux et avoirs (F9) : état courant de la carte (le code en clair n'est jamais rejoué : empreinte seule en base)
            'gift_card' => ($g = $em->find(\App\Entity\PosGiftCard::class, $subjectId)) ? ['status' => 201, 'body' => ['giftCard' => $this->giftCards->toArray($g), 'replayed' => true]] : null,
            'gift_card_update' => ($g = $em->find(\App\Entity\PosGiftCard::class, $subjectId)) ? ['status' => 200, 'body' => ['giftCard' => $this->giftCards->toArray($g), 'replayed' => true]] : null,
            default => null,
        };
    }
}
