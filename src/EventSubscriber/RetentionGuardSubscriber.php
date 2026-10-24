<?php

namespace App\EventSubscriber;

use App\Entity\Caisse;
use App\Entity\CashDetails;
use App\Entity\CashRegister;
use App\Entity\CashRegisterReport;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderTax;
use App\Entity\Payments;
use App\Entity\PosApproval;
use App\Entity\PosAuditLog;
use App\Entity\PosGiftCard;
use App\Entity\PosGiftCardTransaction;
use App\Entity\PosOfflineSale;
use App\Entity\PosOfflineSync;
use App\Entity\PosReceipt;
use App\Entity\PosReturn;
use App\Entity\PosTerminalPayment;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\TransactionCaisse;
use App\Entity\User;
use App\Services\PosAuditService\PosAuditService;
use App\Services\RetentionService\RetentionPolicy;
use App\Services\RetentionService\RetentionViolationException;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Garde de conservation des données de vente (lot A1, 30/10/2026), au niveau Doctrine donc valable pour EasyAdmin, l'API et la
 * console. Pendant la durée de conservation :
 *  - SUPPRESSION refusée (409 RETENTION_LOCKED, message clair) des données de caisse (ventes au comptoir et commandes de type
 *    carte-cadeau, lignes, taxes, paiements, transactions et détails de caisse, sessions, rapports Z, tickets, retours,
 *    cartes-cadeaux et leurs mouvements, journal, paiements terminal, ventes hors ligne, validations) et des commandes web PAYÉES
 *    (une commande jamais payée reste supprimable) ; suppression d'un utilisateur, produit, variante ou poste référencé par une
 *    vente refusée avec un message clair (au lieu d'une erreur SQL) ;
 *  - MODIFICATION des données de caisse hors exceptions : mode « observe » (défaut) = journalisée RETENTION_WRITE_OBSERVED
 *    {entity, id, fields, origin, user}, mode « block » = refusée. Les entités créées dans la même requête sont exemptées
 *    (compléments de la vente en cours). Aucune purge automatique.
 */
class RetentionGuardSubscriber implements EventSubscriber
{
    /** Champs légitimement modifiables après la vente (contre-écritures et suivi) */
    public const ALLOWED_UPDATES = [
        Order::class => ['status', 'statusUpdatedAt', 'totalAmount', 'subTotal', 'total_tax', 'syncedAt', 'customerId', 'taxTransactionId', 'stripeInvoiceId'], // noms de champs Doctrine (total_tax est bien en snake)
        OrderTax::class => ['amount'], // annulation : taxes ramenées à 0 (contre-écriture C1)
        Payments::class => ['stripePaymentId', 'stripeReceiptUrl', 'stripeStatus', 'stripeCardBrand', 'stripeLast4', 'stripeRiskLevel', 'statutPayment'],
        Caisse::class => ['amountTotal', 'fonDeCaisse', 'isOpen', 'closedAt'],
        PosReceipt::class => ['reprintCount', 'linkVersion', 'linkRevokedAt', 'provisionalNumber'],
        PosReturn::class => ['exchangeOrder', 'storeCreditId', 'giftCardRefund'],
        PosGiftCard::class => ['balance', 'status', 'canceledAt', 'replacedById', 'customerId', 'issuedReturnId'],
        PosGiftCardTransaction::class => ['returnId', 'approvedBy', 'reason'],
        PosApproval::class => ['usedAt'],
    ];
    /** Entités à état (machine d'états de paiement, file hors ligne) : protégées de la suppression, libres en modification */
    public const FREE_UPDATES = [PosTerminalPayment::class, PosOfflineSale::class, PosOfflineSync::class];
    /** @var array<int, true> entités insérées pendant cette requête (exemptées de l'observation des modifications) */
    private array $created = [];
    private bool $suspended = false;

    public function __construct(private readonly RetentionPolicy $policy, private readonly PosAuditService $audit, private readonly RequestStack $requestStack, private readonly Security $security)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postPersist];
    }

    /** Suspension explicite (jeux de données de test, scripts d'administration assumés) */
    public function suspend(bool $on = true): void
    {
        $this->suspended = $on;
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->created[spl_object_id($args->getObject())] = true;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->suspended) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->guardDeletion($entity, $em);
        }
        $mode = null;
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $class = $em->getClassMetadata($entity::class)->getName(); // classe réelle (les proxys Doctrine « Proxies\__CG__\… » ne sont pas la clé des listes)
            if (!$this->isSaleData($entity) || isset($this->created[spl_object_id($entity)]) || in_array($class, self::FREE_UPDATES, true)) {
                continue;
            }
            $at = $this->dateOf($entity);
            if ($at === null || !$this->policy->isRetained($at)) {
                continue;
            }
            $fields = array_values(array_diff(array_keys($uow->getEntityChangeSet($entity)), self::ALLOWED_UPDATES[$class] ?? []));
            if ($fields === []) {
                continue;
            }
            $mode ??= $this->policy->settings()['writeMode'];
            $label = $this->label($entity, $em);
            if ($mode === 'block') {
                throw new RetentionViolationException(sprintf('%s : donnée de vente sous conservation légale, modification refusée (champs : %s). Corriger par une contre-écriture.', $label, implode(', ', $fields)), ['entity' => $label, 'fields' => $fields]);
            }
            $this->audit->record(PosAuditService::RETENTION_WRITE_OBSERVED, ['entity' => $class, 'id' => $this->idOf($entity, $em), 'fields' => $fields, 'origin' => $this->origin()], subjectType: 'retention', user: $this->security->getUser() instanceof User ? $this->security->getUser() : null);
        }
    }

    private function guardDeletion(object $entity, $em): void
    {
        if ($this->isSaleData($entity)) {
            $at = $this->dateOf($entity);
            if ($at !== null && $this->policy->isRetained($at)) {
                $end = $this->policy->endOfRetention($at)->format('d/m/Y');
                throw new RetentionViolationException(sprintf('%s : donnée de vente sous conservation légale jusqu\'au %s, suppression refusée. Une erreur se corrige par une contre-écriture (remboursement, mouvement de caisse) ; aucune purge automatique.', $this->label($entity, $em), $end), ['entity' => $entity::class, 'retainedUntil' => $end]);
            }

            return;
        }
        // Parents référencés par des ventes : refus avec un message clair plutôt qu'une erreur SQL
        $conn = $em->getConnection();
        $check = function (string $sql, array $params, string $message) use ($conn) {
            if ((int) $conn->fetchOne($sql, $params) > 0) {
                throw new RetentionViolationException($message);
            }
        };
        if ($entity instanceof User && $entity->getId()) {
            $check('SELECT COUNT(*) FROM "order" WHERE user_id_id = ?', [$entity->getId()], 'Cet utilisateur a réalisé des ventes ou passé des commandes : il ne peut pas être supprimé. Utilisez l\'anonymisation (suppression de compte) ou désactivez-le.');
            $check('SELECT COUNT(*) FROM transaction_caisse WHERE user_caisse_id = ?', [$entity->getId()], 'Cet utilisateur a des mouvements de caisse : il ne peut pas être supprimé. Utilisez l\'anonymisation ou désactivez-le.');
        }
        if ($entity instanceof ProductVariant && $entity->getId()) {
            $check('SELECT COUNT(*) FROM order_items WHERE product_variant_id = ?', [$entity->getId()], 'Cette variante figure sur des lignes de vente : elle ne peut pas être supprimée. Désactivez-la ou mettez son stock à zéro.');
        }
        if ($entity instanceof Product && $entity->getId()) {
            $check('SELECT COUNT(*) FROM order_items i JOIN product_variant v ON v.id = i.product_variant_id WHERE v.product_id = ?', [$entity->getId()], 'Ce produit figure sur des lignes de vente : il ne peut pas être supprimé. Retirez-le du web et de la caisse (isWeb, isPos) ou désactivez la vente.');
        }
        if ($entity instanceof CashRegister && $entity->getId()) {
            $check('SELECT COUNT(*) FROM caisse WHERE cash_register_id = ?', [$entity->getId()], 'Ce poste de caisse a des sessions : il ne peut pas être supprimé. Désactivez-le.');
        }
    }

    private function isSaleData(object $entity): bool
    {
        return match (true) {
            $entity instanceof Order => $this->isProtectedOrder($entity),
            $entity instanceof OrderItems => $this->isProtectedOrder($entity->getOrderAssociated()),
            $entity instanceof OrderTax => $this->isProtectedOrder($entity->getOrderTax()),
            $entity instanceof Payments => $this->isProtectedOrder($entity->getOrderPayment()),
            $entity instanceof TransactionCaisse, $entity instanceof CashDetails, $entity instanceof Caisse, $entity instanceof CashRegisterReport, $entity instanceof PosReceipt,
            $entity instanceof PosReturn, $entity instanceof PosGiftCard, $entity instanceof PosGiftCardTransaction, $entity instanceof PosAuditLog, $entity instanceof PosTerminalPayment,
            $entity instanceof PosOfflineSale, $entity instanceof PosOfflineSync, $entity instanceof PosApproval => true,
            default => false,
        };
    }

    /** Vente au comptoir ou commande de type carte-cadeau ; commande web : seulement si payée (paiement enregistré ou statut complété/livré) */
    private function isProtectedOrder(?Order $order): bool
    {
        if ($order === null) {
            return false;
        }
        if ($order->getOrderSource()?->getId() === 2 || $order->getOrderType()?->getId() === 3) {
            return true;
        }
        foreach ($order->getPayments() as $p) {
            if ((float) $p->getAmount() > 0) {
                return true;
            }
        }

        return in_array($order->getStatus()?->getId(), [3, 4, 5, 6], true);
    }

    private function dateOf(object $entity): ?\DateTimeInterface
    {
        return match (true) {
            $entity instanceof Order => $entity->getOrderDate(),
            $entity instanceof OrderItems => $entity->getOrderAssociated()?->getOrderDate(),
            $entity instanceof OrderTax => $entity->getOrderTax()?->getOrderDate(),
            $entity instanceof Payments => $entity->getPaymentDate() ?? $entity->getOrderPayment()?->getOrderDate(),
            $entity instanceof TransactionCaisse => $entity->getTransactionDate(),
            $entity instanceof CashDetails => $entity->getTransactionCaisse()?->getTransactionDate(),
            $entity instanceof Caisse => $entity->getCreatedAt(),
            $entity instanceof CashRegisterReport => $entity->getClosedAt(),
            $entity instanceof PosAuditLog => $entity->getOccurredAt(),
            $entity instanceof PosReceipt, $entity instanceof PosReturn, $entity instanceof PosGiftCard, $entity instanceof PosGiftCardTransaction, $entity instanceof PosTerminalPayment,
            $entity instanceof PosOfflineSale, $entity instanceof PosOfflineSync, $entity instanceof PosApproval => method_exists($entity, 'getCreatedAt') ? $entity->getCreatedAt() : new \DateTimeImmutable(),
            default => null,
        };
    }

    private function label(object $entity, $em): string
    {
        $class = $em->getClassMetadata($entity::class)->getName();
        $short = substr(strrchr($class, '\\') ?: $class, 1);
        $names = [Order::class => 'Commande', OrderItems::class => 'Ligne de commande', OrderTax::class => 'Taxe de commande', Payments::class => 'Paiement', TransactionCaisse::class => 'Transaction de caisse', CashDetails::class => 'Détail de caisse',
            Caisse::class => 'Session de caisse', CashRegisterReport::class => 'Rapport Z', PosReceipt::class => 'Ticket', PosReturn::class => 'Retour', PosGiftCard::class => 'Carte-cadeau ou avoir', PosGiftCardTransaction::class => 'Mouvement de carte-cadeau',
            PosAuditLog::class => 'Entrée du journal', PosTerminalPayment::class => 'Paiement terminal', PosOfflineSale::class => 'Vente hors ligne', PosOfflineSync::class => 'Synchronisation hors ligne', PosApproval::class => 'Validation'];

        return ($names[$class] ?? $short) . ' #' . ($this->idOf($entity, $em) ?? '?');
    }

    private function idOf(object $entity, $em): ?int
    {
        $id = $em->getUnitOfWork()->getEntityIdentifier($entity);

        return is_numeric(reset($id)) ? (int) reset($id) : null;
    }

    private function origin(): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request) {
            return (string) ($request->attributes->get('_route') ?: ($request->getMethod() . ' ' . $request->getPathInfo()));
        }

        return \PHP_SAPI === 'cli' ? 'console:' . implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2)) : 'unknown';
    }
}
