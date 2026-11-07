<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\StatusCommande;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Expédition manuelle d'une commande par le marchand (livraison, lot 1, 07/11/2026) : « j'expédie moi-même ». Le marchand saisit le
 * transporteur et le numéro de suivi, la commande passe « En cours de livraison » (statut 5) avec la date d'expédition, le client
 * reçoit un courriel avec le lien de suivi (page du transporteur quand il est connu, OrderPresenter::trackingUrl) ; puis « Livrée »
 * (statut 6). Jamais sur une vente au comptoir (caisse), une commande annulée (7) ou non payée (1). L'achat d'étiquette par la
 * plateforme (lot 2) réutilisera ship() avec le suivi rendu par EasyPost. Tout est tracé dans content_audit_log (ressource order).
 */
class OrderShipmentService
{
    public const STATUS_INCOMPLETE = 1;
    public const STATUS_SHIPPED = 5;
    public const STATUS_DELIVERED = 6;
    public const STATUS_CANCELLED = 7;
    /** Statuts depuis lesquels une commande peut être expédiée : en cours, complétée (payée), en préparation */
    public const SHIPPABLE_STATUSES = [2, 3, 4];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EmailSenderService $mailer,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Pourquoi la commande ne peut pas être expédiée : null si c'est possible */
    public function shipBlocker(Order $order): ?string
    {
        if ($order->getOrderSource()?->getId() === 2) {
            return 'COUNTER_SALE';
        }
        $status = (int) ($order->getStatus()?->getId() ?? 0);

        return match (true) {
            $status === self::STATUS_CANCELLED => 'ORDER_CANCELLED',
            $status === self::STATUS_INCOMPLETE => 'ORDER_UNPAID',
            $status === self::STATUS_SHIPPED, $status === self::STATUS_DELIVERED => 'ALREADY_SHIPPED',
            !in_array($status, self::SHIPPABLE_STATUSES, true) => 'STATUS_NOT_SHIPPABLE',
            default => null,
        };
    }

    public function deliverBlocker(Order $order): ?string
    {
        if ($order->getOrderSource()?->getId() === 2) {
            return 'COUNTER_SALE';
        }

        return (int) ($order->getStatus()?->getId() ?? 0) === self::STATUS_SHIPPED ? null : 'NOT_SHIPPED';
    }

    public const MESSAGES = [
        'COUNTER_SALE' => 'Vente au comptoir : rien à expédier.',
        'ORDER_CANCELLED' => 'Commande annulée : rien à expédier.',
        'ORDER_UNPAID' => 'Commande non payée : encaissez-la ou annulez-la avant d\'expédier.',
        'ALREADY_SHIPPED' => 'Cette commande est déjà expédiée ou livrée.',
        'STATUS_NOT_SHIPPABLE' => 'Le statut de la commande ne permet pas l\'expédition.',
        'NOT_SHIPPED' => 'Marquez d\'abord la commande comme expédiée.',
    ];

    /**
     * Marque la commande expédiée, garde le suivi et prévient le client (langue du site).
     *
     * @return array{notified: bool, trackingUrl: ?string}
     */
    public function ship(Order $order, string $carrier, ?string $trackingNumber, ?string $trackingUrl, bool $notify, string $domain): array
    {
        $em = $this->emProvider->getEntityManager();
        $before = ['status' => $order->getStatus()?->getId(), 'trackingNumber' => $order->getTrackingNumber()];
        $trackingNumber = $trackingNumber !== null && trim($trackingNumber) !== '' ? trim($trackingNumber) : null;
        $trackingUrl = $trackingUrl !== null && trim($trackingUrl) !== '' ? trim($trackingUrl) : ($trackingNumber !== null ? OrderPresenter::trackingUrl($carrier, $trackingNumber) : null);
        $status = $em->getRepository(StatusCommande::class)->find(self::STATUS_SHIPPED);
        if ($status === null) {
            throw new \RuntimeException('Statut « En cours de livraison » introuvable sur ce site.');
        }
        $order->setStatus($status)->setStatusUpdatedAt(new \DateTime())->setShippedAt(new \DateTimeImmutable())
            ->setTrackingCarrier($carrier)->setTrackingNumber($trackingNumber)->setTrackingUrl($trackingUrl);
        $em->flush();

        $notified = false;
        $email = (string) ($order->getUserId()?->getEmail() ?? '');
        if ($notify && $email !== '' && !str_ends_with($email, '@compte-supprime.invalid')) {
            try {
                $notified = $this->mailer->sendCustomerEmail($email, 'order_shipped.subject', ['%reference%' => (string) $order->getReference()], 'emails/order_shipped.html.twig', [
                    'firstname' => $order->getUserId()?->getFirstname(), 'reference' => (string) $order->getReference(), 'carrier' => $carrier,
                    'trackingNumber' => $trackingNumber, 'trackingUrl' => $trackingUrl, 'shippedAt' => $order->getShippedAt(),
                ], null, $domain, 'order_shipped.subject');
            } catch (\Throwable $e) {
                $this->logger->error('[Expédition] courriel au client non envoyé : ' . $e->getMessage(), ['orderId' => $order->getId()]);
            }
        }
        $this->audit->record('order', (int) $order->getId(), 'ship', $before, ['status' => self::STATUS_SHIPPED, 'carrier' => $carrier, 'trackingNumber' => $trackingNumber, 'trackingUrl' => $trackingUrl, 'notified' => $notified], ['status', 'shippedAt', 'trackingCarrier', 'trackingNumber', 'trackingUrl']);

        return ['notified' => $notified, 'trackingUrl' => $trackingUrl];
    }

    public function deliver(Order $order): void
    {
        $em = $this->emProvider->getEntityManager();
        $status = $em->getRepository(StatusCommande::class)->find(self::STATUS_DELIVERED);
        if ($status === null) {
            throw new \RuntimeException('Statut « Livrée » introuvable sur ce site.');
        }
        $before = ['status' => $order->getStatus()?->getId()];
        $order->setStatus($status)->setStatusUpdatedAt(new \DateTime());
        $em->flush();
        $this->audit->record('order', (int) $order->getId(), 'deliver', $before, ['status' => self::STATUS_DELIVERED], ['status']);
    }
}
