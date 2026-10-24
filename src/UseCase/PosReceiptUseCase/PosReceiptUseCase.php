<?php

namespace App\UseCase\PosReceiptUseCase;

use App\Entity\CashRegister;
use App\Entity\PosReceipt;
use App\Entity\PosReturn;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosAuditService\PosReceiptLinkService;
use App\Services\PosAuditService\PosReceiptMailer;
use App\Services\PosAuditService\PosWriteResponseBuilder;
use App\Services\PosReturnService\PosReturnService;
use App\Services\TenantEntityManagerProvider;

/** Ticket numérique (F3, 24/10/2026) : réimpression, envoi par courriel, révocation du lien public, page publique */
class PosReceiptUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly PosWriteResponseBuilder $responses,
        private readonly PosReceiptMailer $mailer,
        private readonly PosReceiptLinkService $links,
        private readonly PosAuditService $audit,
        private readonly \App\Services\PosCustomerService\PosCustomerService $customers, // fiche client (F8) : courriel du client consentant
    ) {
    }

    /** ?barcode=IZ-T-1-123 | ?orderId=&kind=ticket|credit_note | ?kind=&number=&cashRegisterId= */
    public function find(array $query): PosReceipt
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(PosReceipt::class);
        if (is_string($query['barcode'] ?? null) && $query['barcode'] !== '') {
            if (!preg_match('/^IZ-([TA])-(\d+)-(\d+)$/i', trim($query['barcode']), $m)) {
                throw new CashRegisterException('BARCODE_INVALID', 'Code-barres inconnu : IZ-T-<poste>-<numéro> ou IZ-A-<poste>-<numéro> attendu', 422, [], ['barcode' => $query['barcode']]);
            }
            $receipt = $repo->findOneBy(['cashRegister' => (int) $m[2], 'kind' => strtoupper($m[1]) === 'A' ? PosReceipt::CREDIT_NOTE : PosReceipt::TICKET, 'number' => (int) $m[3]]);
        } elseif (is_numeric($query['orderId'] ?? null)) {
            $kind = ($query['kind'] ?? null) === PosReceipt::CREDIT_NOTE ? PosReceipt::CREDIT_NOTE : PosReceipt::TICKET;
            $receipt = $repo->findOneBy(['order' => (int) $query['orderId'], 'kind' => $kind], ['number' => 'DESC']) ?? $repo->findOneBy(['order' => (int) $query['orderId']], ['id' => 'DESC']);
        } elseif (is_numeric($query['number'] ?? null)) {
            $register = $this->registers->resolve(is_numeric($query['cashRegisterId'] ?? null) ? (int) $query['cashRegisterId'] : null);
            $receipt = $repo->findOneBy(['cashRegister' => $register, 'kind' => ($query['kind'] ?? null) === PosReceipt::CREDIT_NOTE ? PosReceipt::CREDIT_NOTE : PosReceipt::TICKET, 'number' => (int) $query['number']]);
        } else {
            throw new CashRegisterException('VALIDATION_FAILED', 'barcode, orderId ou number (+ cashRegisterId) requis', 422, [['path' => 'barcode', 'message' => 'barcode, orderId ou number requis']]);
        }

        return $receipt ?? throw new CashRegisterException('RECEIPT_NOT_FOUND', 'Ticket introuvable', 404, [], array_intersect_key($query, array_flip(['barcode', 'orderId', 'number', 'cashRegisterId'])));
    }

    /** Bloc du ticket (duplicata) ; pour un échange : {return, sale} en plus */
    public function block(PosReceipt $receipt, string $locale, bool $duplicate): array
    {
        $order = $receipt->getOrder();
        $block = $this->responses->receiptBlock($order, $locale, $receipt, $duplicate);
        $em = $this->emProvider->getEntityManager();
        /** @var ?PosReturn $return */
        $return = $em->getRepository(PosReturn::class)->findOneBy(['exchangeOrder' => $order]) ?? $em->getRepository(PosReturn::class)->findOneBy(['returnOrder' => $order]);
        if ($return && $return->getExchangeOrder()) {
            $block['exchange'] = ['returnId' => $return->getId(), 'returnOrderId' => $return->getReturnOrder()->getId(), 'saleOrderId' => $return->getExchangeOrder()->getId(),
                'other' => $this->responses->receiptBlock($order === $return->getExchangeOrder() ? $return->getReturnOrder() : $return->getExchangeOrder(), $locale, null, $duplicate)];
            $block['kind'] = $order === $return->getExchangeOrder() ? 'exchange' : 'return';
        }

        return $block;
    }

    public function reprint(User $user, PosReceipt $receipt, string $locale): array
    {
        $receipt->addReprint();
        $this->emProvider->getEntityManager()->flush();
        $block = $this->block($receipt, $locale, true);
        $this->audit->record(PosAuditService::RECEIPT_REPRINTED, ['barcode' => PosReturnService::barcode($receipt), 'kind' => $receipt->getKind(), 'number' => $receipt->getNumber(), 'reprintCount' => $receipt->getReprintCount(), 'orderId' => $receipt->getOrder()->getId()],
            $receipt->getSession(), $receipt->getCashRegister(), 'receipt', $receipt->getId(), $user);

        return $block;
    }

    public function email(User $user, PosReceipt $receipt, mixed $email, string $locale): array
    {
        if (!$this->links->settings()['emailEnabled']) {
            throw new CashRegisterException('RECEIPT_EMAIL_DISABLED', 'Le reçu par courriel est désactivé sur ce site', 409);
        }
        $to = is_string($email) ? trim($email) : '';
        $source = 'provided';
        if ($to === '' && $receipt->getOrder()->getCustomerId() !== null) { // fiche client (F8) : adresse du client rattaché s'il a consenti aux reçus par courriel
            $customer = $this->emProvider->getEntityManager()->find(\App\Entity\PosCustomer::class, $receipt->getOrder()->getCustomerId());
            if ($customer && $customer->isActive() && $customer->getEmail() !== null && $this->customers->consentGranted($customer, \App\Entity\PosCustomerConsent::RECEIPTS)) {
                $to = $customer->getEmail();
                $source = 'customer';
            }
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || mb_strlen($to) > 254) {
            throw new CashRegisterException('EMAIL_INVALID', 'Adresse courriel invalide', 422, [['path' => 'email', 'message' => 'adresse invalide']]);
        }
        $block = $this->block($receipt, $locale, false);
        $this->mailer->send($receipt, $block, $to, $locale, false);
        $masked = PosReceiptMailer::mask($to);
        $this->audit->record(PosAuditService::RECEIPT_EMAILED, ['barcode' => PosReturnService::barcode($receipt), 'kind' => $receipt->getKind(), 'number' => $receipt->getNumber(), 'orderId' => $receipt->getOrder()->getId(), 'to' => $masked, 'source' => $source],
            $receipt->getSession(), $receipt->getCashRegister(), 'receipt', $receipt->getId(), $user);

        return ['sent' => true, 'barcode' => PosReturnService::barcode($receipt), 'to' => $masked];
    }

    public function revokeLink(User $user, PosReceipt $receipt): array
    {
        if (!\App\Services\PosAuditService\PosApprovalService::isManager($user)) { // S2 (30/10/2026) : révocation d'un lien public réservée au responsable
            throw new CashRegisterException('FORBIDDEN', 'Révocation du lien réservée au responsable de caisse', 403);
        }
        $receipt->revokeLink();
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::RECEIPT_LINK_REVOKED, ['barcode' => PosReturnService::barcode($receipt), 'linkVersion' => $receipt->getLinkVersion()], $receipt->getSession(), $receipt->getCashRegister(), 'receipt', $receipt->getId(), $user);

        return ['revoked' => true, 'barcode' => PosReturnService::barcode($receipt), 'receiptUrl' => $this->links->url($receipt)];
    }

    /** Page publique : {status, receipt?, block?} */
    public function public(string $token, string $locale): array
    {
        $resolved = $this->links->resolve($token);
        if ($resolved['status'] !== 'valid') {
            return $resolved;
        }

        return $resolved + ['block' => $this->block($resolved['receipt'], $locale, false)];
    }
}
