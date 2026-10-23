<?php

namespace App\Services\PosOfflineService;

use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\PosOfflineSale;
use App\Entity\PosOfflineSync;
use App\Entity\PosReceipt;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\Tax;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\OrderCreationService;
use App\Services\OrderService\OrderItemService;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosAuditService\PosReceiptService;
use App\Services\PosDiscountService\PosDiscountCalculator;
use App\Services\PosDiscountService\PosDiscountService;
use App\Services\PosLineService\PosLineService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use App\Dto\CartQuoteInputDto;

/**
 * Synchronisation des ventes hors ligne (C5 partie 2, 25/10/2026). Chaque vente du lot est traitée dans sa propre
 * transaction : clé d'idempotence liée au terminal, recalcul aux prix et taxes du pack déclaré (écart = anomalie),
 * écart avec le prix courant à titre d'information, enregistrement aux montants perçus et taxes imprimées, numéro
 * officiel dans la suite du poste, stock décrémenté même sous zéro, rattachement à la session ouverte, journal.
 */
class PosOfflineSyncService
{
    public const FORCEABLE = ['PRICE_DRIFT_EXCEEDED', 'PACK_EXPIRED', 'OFFLINE_TOO_OLD', 'CUSTOM_ITEM_LIMIT_EXCEEDED', 'PRICE_OVERRIDE_LIMIT_EXCEEDED'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosOfflinePackService $packs,
        private readonly CashRegisterResolver $registers,
        private readonly CartQuoteCalculator $quotes,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly OrderCreationService $orders,
        private readonly OrderItemService $orderItems,
        private readonly PaymentHandlerUseCase $payments,
        private readonly UpdateStockAndInventoryUseCase $stock,
        private readonly HandleCaisseTransactionUseCase $caisseTransactions,
        private readonly PosReceiptService $receipts,
        private readonly PosAuditService $audit,
        private readonly PosApprovalService $approvals,
        private readonly PosDiscountService $discounts,
        private readonly PosLineService $posLines, // article libre et prix manuel (F7)
    ) {
    }

    /**
     * @param array $batch {packVersion, deviceTime, clockOffsetSeconds, sales: [...], approvals: {provisionalNumber: token}}
     * @return array{syncId: int, serverTime: string, results: list<array>}
     */
    public function process(User $syncedBy, int $terminalId, array $batch): array
    {
        $em = $this->emProvider->getEntityManager();
        $settings = $this->packs->settings();
        $sales = is_array($batch['sales'] ?? null) ? array_values($batch['sales']) : [];
        if ($sales === [] || count($sales) > 200) {
            throw new CashRegisterException('VALIDATION_FAILED', 'sales : de 1 à 200 ventes par lot', 422, [['path' => 'sales', 'message' => '1 à 200 ventes']]);
        }
        $now = new \DateTimeImmutable();
        $deviceTime = is_string($batch['deviceTime'] ?? null) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $batch['deviceTime']) ?: null : null;
        $declaredOffset = is_numeric($batch['clockOffsetSeconds'] ?? null) ? (int) $batch['clockOffsetSeconds'] : 0;
        $measuredOffset = $deviceTime ? $now->getTimestamp() - $deviceTime->getTimestamp() : null;
        $offset = $measuredOffset !== null && abs($measuredOffset) > abs($declaredOffset) ? $measuredOffset : $declaredOffset;
        $approvals = is_array($batch['approvals'] ?? null) ? $batch['approvals'] : [];
        $batchPack = is_string($batch['packVersion'] ?? null) ? $batch['packVersion'] : null;

        $sync = new PosOfflineSync($terminalId, (int) $syncedBy->getId());
        $em->persist($sync);
        $em->flush();
        $lastSync = $em->getRepository(PosOfflineSync::class)->findOneBy(['terminalId' => $terminalId, 'chainBroken' => false], ['id' => 'DESC']);
        $prevHash = $this->lastHash($terminalId);
        $results = [];
        $counts = ['accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        $drift = 0;
        $chainBroken = false;
        $queuedAmount = 0;
        foreach ($sales as $i => $sale) {
            if (!is_array($sale) || !is_string($sale['idempotencyKey'] ?? null) || !preg_match('/^[0-9a-f-]{36}$/i', $sale['idempotencyKey']) || !is_string($sale['provisionalNumber'] ?? null) || !preg_match('/^P-\d+-\d+$/', $sale['provisionalNumber'])) {
                $results[] = ['index' => $i, 'provisionalNumber' => $sale['provisionalNumber'] ?? null, 'status' => 'rejected', 'error' => 'OFFLINE_SALE_INVALID', 'message' => 'idempotencyKey (UUID) et provisionalNumber (P-<terminal>-<compteur>) requis'];
                $counts['rejected']++;
                continue;
            }
            $key = strtolower($sale['idempotencyKey']);
            /** @var ?PosOfflineSale $seen */
            $seen = $em->getRepository(PosOfflineSale::class)->findOneBy(['terminalId' => $terminalId, 'idempotencyKey' => $key]);
            if ($seen && $seen->getStatus() === 'accepted') {
                $results[] = ['status' => 'duplicate'] + $seen->getResult();
                $counts['duplicates']++;
                continue;
            }
            // Chaînage du journal local : vérifié, jamais bloquant
            $chain = is_array($sale['chain'] ?? null) ? $sale['chain'] : [];
            $expectedHash = self::hashOf($sale, (string) ($chain['prevHash'] ?? ''));
            $broken = ($prevHash !== null && ($chain['prevHash'] ?? null) !== $prevHash) || ($chain['hash'] ?? null) !== $expectedHash;
            $chainBroken = $chainBroken || $broken;
            $prevHash = is_string($chain['hash'] ?? null) ? $chain['hash'] : $prevHash;

            $queuedAmount += (int) ($sale['totals']['cashTotal'] ?? 0);
            $limitError = null;
            if (!$settings['enabled']) {
                $limitError = ['OFFLINE_DISABLED', 'Le mode hors ligne est désactivé sur ce site' . ($settings['reason'] === 'TAX_PROVIDER_STRIPE' ? ' (taxes calculées par Stripe Tax)' : '')];
            } elseif ($i >= $settings['maxQueuedSales'] || $queuedAmount > $settings['maxQueuedAmount']) {
                $limitError = ['OFFLINE_LIMIT_EXCEEDED', 'File hors ligne au-delà des limites du site'];
            }
            $forceToken = is_string($approvals[$sale['provisionalNumber']] ?? null) ? $approvals[$sale['provisionalNumber']] : null;
            $em->getConnection()->beginTransaction();
            try {
                if ($limitError) {
                    throw new CashRegisterException($limitError[0], $limitError[1], 409, [], ['limits' => array_intersect_key($settings, array_flip(['maxQueuedSales', 'maxQueuedAmount']))]);
                }
                $result = $this->accept($syncedBy, $terminalId, $sale, $key, $batchPack, $offset, $now, $settings, $forceToken, $broken);
                $row = $seen ?? new PosOfflineSale($terminalId, $key, $sale['provisionalNumber']);
                $row->setStatus('accepted')->setResult($result)->setOrderId($result['orderId'])->setCashRegisterId($result['cashRegisterId'])->setPackVersion($result['packVersion'])
                    ->setChainSeq(is_numeric($chain['seq'] ?? null) ? (int) $chain['seq'] : null)->setChainHash(is_string($chain['hash'] ?? null) ? $chain['hash'] : null)
                    ->setPriceDrift((int) $result['priceDrift']['amount'])->setStockShortfall(count($result['stockShortfall']))->setLateEntry($result['lateEntry']);
                $em->persist($row);
                $em->flush();
                $em->getConnection()->commit();
                $drift += abs((int) $result['priceDrift']['amount']);
                $counts['accepted']++;
                $results[] = ['status' => 'accepted'] + $result;
            } catch (CashRegisterException $e) {
                $em->getConnection()->rollBack();
                $body = ['provisionalNumber' => $sale['provisionalNumber'], 'error' => $e->errorCode, 'message' => $e->getMessage(), 'forceable' => in_array($e->errorCode, self::FORCEABLE, true)] + $e->context;
                $em->getConnection()->beginTransaction();
                $row = $seen ?? new PosOfflineSale($terminalId, $key, $sale['provisionalNumber']);
                $row->setStatus('rejected')->setResult($body)->setPackVersion(is_string($sale['packVersion'] ?? $batchPack) ? ($sale['packVersion'] ?? $batchPack) : null);
                $em->persist($row);
                $em->flush();
                $em->getConnection()->commit();
                $counts['rejected']++;
                $results[] = ['status' => 'rejected'] + $body;
            }
        }
        $sync->finish(count($sales), $counts['accepted'], $counts['duplicates'], $counts['rejected'], $drift, $chainBroken, $prevHash, $offset, $batchPack);
        $em->flush();
        $this->audit->record(PosAuditService::OFFLINE_SYNC, ['syncId' => $sync->getId(), 'terminalId' => $terminalId, 'count' => count($sales)] + $counts + ['driftTotal' => $drift, 'chainBroken' => $chainBroken, 'clockOffsetSeconds' => $offset, 'packVersion' => $batchPack,
            'provisionalNumbers' => array_values(array_filter(array_map(fn ($r) => $r['provisionalNumber'] ?? null, $results)))], null, null, 'offline_sync', $sync->getId(), $syncedBy);

        return ['syncId' => $sync->getId(), 'serverTime' => $now->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => $offset, 'chainBroken' => $chainBroken, 'results' => $results];
    }

    /** Une vente, dans la transaction ouverte par process() ; renvoie le résultat « accepted » */
    private function accept(User $syncedBy, int $terminalId, array $sale, string $key, ?string $batchPack, int $offset, \DateTimeImmutable $now, array $settings, ?string $forceToken, bool $chainBroken): array
    {
        $em = $this->emProvider->getEntityManager();
        $errors = [];
        $items = is_array($sale['items'] ?? null) ? array_values($sale['items']) : [];
        if ($items === []) {
            $errors[] = ['path' => 'items', 'message' => 'au moins un article'];
        }
        $soldAt = is_string($sale['soldAt'] ?? null) ? (\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $sale['soldAt']) ?: null) : null;
        if ($soldAt === null) {
            $errors[] = ['path' => 'soldAt', 'message' => 'date ISO 8601 attendue'];
        }
        $payments = is_array($sale['payments'] ?? null) ? $sale['payments'] : [];
        $cashPaid = 0;
        foreach ($payments as $p) {
            if (!is_array($p) || (int) ($p['type'] ?? 0) !== 2 || !is_numeric($p['amount'] ?? null)) {
                $errors[] = ['path' => 'payments', 'message' => in_array((int) ($p['type'] ?? 0), [4, 5], true)
                    ? 'carte-cadeau et avoir indisponibles hors ligne (solde inconnu sans réseau) : espèces seulement' : 'espèces seulement hors ligne (type 2, montant en cents)'];
                break;
            }
            $cashPaid += (int) $p['amount'];
        }
        $declared = is_array($sale['totals'] ?? null) ? $sale['totals'] : [];
        foreach (['subtotal', 'total', 'cashTotal'] as $k) {
            if (!is_numeric($declared[$k] ?? null)) {
                $errors[] = ['path' => "totals.$k", 'message' => 'requis (cents)'];
            }
        }
        if ($errors) {
            throw new CashRegisterException('OFFLINE_SALE_INVALID', 'Vente hors ligne incomplète : ' . $errors[0]['path'] . ' ' . $errors[0]['message'], 422, $errors);
        }
        $soldAtCorrected = $soldAt->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds');
        // Pack déclaré : prix et taxes connus de la tablette
        $packVersion = is_string($sale['packVersion'] ?? null) ? $sale['packVersion'] : $batchPack;
        $pack = $packVersion !== null ? $this->packs->find($packVersion) : null;
        if ($pack === null) {
            throw new CashRegisterException('PACK_UNKNOWN', 'Version de pack inconnue : la vente ne peut pas être recalculée aux conditions de la tablette', 422, [], ['packVersion' => $packVersion]);
        }
        $seller = is_numeric($sale['sellerId'] ?? null) ? $em->find(User::class, (int) $sale['sellerId']) : null;
        if (!$seller instanceof User || array_intersect(['ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $seller->getRoles()) === []) {
            throw new CashRegisterException('SELLER_INVALID', 'Vendeur inconnu ou sans droit de caisse', 422, [], ['sellerId' => $sale['sellerId'] ?? null]);
        }
        $register = $this->registers->resolve(is_numeric($sale['cashRegisterId'] ?? null) ? (int) $sale['cashRegisterId'] : null);
        $caisse = $this->registers->requireOpenSession($register->getId(), 409);
        $lateEntry = $caisse->getCreatedAt() && $soldAtCorrected < \DateTimeImmutable::createFromInterface($caisse->getCreatedAt());

        // Recalcul aux conditions du pack déclaré (règle pos-offline-v1)
        $snapshot = $pack->getSnapshot();
        $lines = [];
        $variants = [];
        $lineSettings = $this->posLines->settings(); // article libre et prix manuel (F7) : permis hors ligne si activés, plafond sans validation
        foreach ($items as $i => $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            $lineErrors = [];
            if (is_array($item['custom'] ?? null)) { // article libre : prix saisi par la tablette, taxable ou non, sans variante ni stock
                if (!$lineSettings['customItems']['enabled']) {
                    throw new CashRegisterException('OFFLINE_SALE_INVALID', "items[$i] : l'article libre est désactivé sur ce site", 422, [['path' => "items[$i].custom", 'message' => 'article libre désactivé']]);
                }
                $custom = $qty > 0 ? PosLineService::customLine($item['custom'], "items[$i]", $lineErrors) : null;
                if ($custom === null) {
                    throw new CashRegisterException('OFFLINE_SALE_INVALID', "items[$i] : article libre invalide", 422, $lineErrors ?: [['path' => "items[$i].quantity", 'message' => 'quantité invalide']]);
                }
                $lines[] = ['unitPrice' => $custom['unitPrice'], 'quantity' => $qty, 'discounts' => is_array($item['discounts'] ?? null) ? $item['discounts'] : [], 'custom' => ['label' => $custom['custom']['label'], 'unitPrice' => $custom['unitPrice'], 'taxable' => $custom['custom']['taxable']]];
                $variants[] = null;
                continue;
            }
            $variant = is_numeric($item['productVariantId'] ?? null) ? $em->find(ProductVariant::class, (int) $item['productVariantId']) : null;
            if (!$variant || $qty <= 0 || !isset($snapshot['prices'][$variant->getId()])) {
                throw new CashRegisterException('OFFLINE_SALE_INVALID', "items[$i] : variante inconnue du pack ou quantité invalide", 422, [['path' => "items[$i]", 'message' => 'variante inconnue du pack']]);
            }
            $price = $snapshot['prices'][$variant->getId()];
            $unit = $price['unit'];
            if (!$price['variantPrice'] && $price['special'] !== null && ($price['from'] === null || $soldAtCorrected >= new \DateTimeImmutable($price['from'])) && ($price['to'] === null || $soldAtCorrected <= new \DateTimeImmutable($price['to']))) {
                $unit = $price['special'];
            }
            $line = ['unitPrice' => $unit, 'quantity' => $qty, 'discounts' => is_array($item['discounts'] ?? null) ? $item['discounts'] : []];
            if (is_array($item['priceOverride'] ?? null)) { // prix manuel : remplace le prix du pack, prix du pack gardé comme prix catalogue
                if (!$lineSettings['priceOverride']['enabled']) {
                    throw new CashRegisterException('OFFLINE_SALE_INVALID', "items[$i] : le prix manuel est désactivé sur ce site", 422, [['path' => "items[$i].priceOverride", 'message' => 'prix manuel désactivé']]);
                }
                $override = PosLineService::priceOverride($item['priceOverride'], $unit, $qty, "items[$i]", $lineErrors);
                if ($override === null) {
                    throw new CashRegisterException('OFFLINE_SALE_INVALID', "items[$i] : prix manuel invalide", 422, $lineErrors);
                }
                $line['priceOverride'] = $override;
                $line['catalogUnitPrice'] = $unit;
            }
            $lines[] = $line;
            $variants[] = $variant;
        }
        $this->posLines->check(array_map(fn ($l) => array_intersect_key($l, ['custom' => 1, 'priceOverride' => 1]), $lines)); // motifs du prix manuel
        $ticketDiscount = is_array($sale['ticketDiscount'] ?? null) && $sale['ticketDiscount'] !== [] ? $sale['ticketDiscount'] : null;
        $this->discounts->check(array_map(fn ($l) => ['discounts' => $l['discounts']], $lines), $ticketDiscount);
        $expected = PosOfflineCalculator::compute($lines, $ticketDiscount, $snapshot['taxes'], (int) $snapshot['step'], $cashPaid);
        // Remises : plafond du vendeur (sans validation possible hors ligne)
        if ($expected['discountTotal'] > 0) {
            $summary = ['lines' => $expected['discountTotal'] - ($expected['ticketDiscount']['amount'] ?? 0), 'ticket' => $expected['ticketDiscount'], 'total' => $expected['discountTotal'], 'gross' => (int) array_sum(array_map(fn ($l) => $l['gross'], $expected['lines']))];
            $cap = $this->discounts->settings()[PosApprovalService::isManager($seller) ? 'manager' : 'cashier'];
            $percent = $summary['gross'] > 0 ? round($summary['total'] * 100 / $summary['gross'], 2) : 0.0;
            if (!in_array('ROLE_ADMIN', $seller->getRoles(), true) && !PosDiscountService::within($summary['total'], $percent, $cap)) {
                throw new CashRegisterException('OFFLINE_SALE_INVALID', 'Remise au-delà du plafond du vendeur (aucune validation possible hors ligne)', 422, [], ['limit' => $cap, 'amount' => $summary['total'], 'percent' => $percent]);
            }
        }
        $declaredTotal = (int) $declared['total'];
        $declaredCash = (int) $declared['cashTotal'];
        $declaredTaxes = is_array($declared['taxes'] ?? null) ? $declared['taxes'] : [];
        $driftAmount = $declaredTotal - $expected['total'];
        $taxDrift = [];
        foreach ($expected['taxes'] as $k => $t) {
            $taxDrift[] = ['label' => $t['label'], 'expected' => $t['amount'], 'declared' => (int) ($declaredTaxes[$k]['amount'] ?? 0), 'drift' => (int) ($declaredTaxes[$k]['amount'] ?? 0) - $t['amount']];
        }
        // Écart avec le prix courant (information)
        $currentDrift = null;
        try {
            $current = $this->quotes->quote(CartQuoteInputDto::fromArray(['items' => array_map(fn ($l, $v) => ($v !== null ? ['productVariantId' => $v->getId()] : ['custom' => $l['custom']]) + ['quantity' => $l['quantity'], 'discounts' => $l['discounts']]
                + (isset($l['priceOverride']) ? ['priceOverride' => ['unitPrice' => $l['priceOverride']['unitPrice']]] : []), $lines, $variants),
                'shippingAddress' => $this->taxAddress->forStore(), 'ticketDiscount' => $ticketDiscount], true));
            $currentDrift = $declaredTotal - (int) $current['total'];
        } catch (\Throwable) {
        }
        $within = abs($driftAmount) <= $settings['priceTolerance'];
        $forced = null;
        $packExpired = $soldAtCorrected > $pack->getValidUntil();
        $tooOld = $soldAtCorrected < $now->modify('-' . $settings['maxOfflineHours'] . ' hours');
        // Article libre et prix manuel (F7) : au-delà du montant maximal sans validation → refus forçable (aucune validation possible hors ligne)
        $customTotal = (int) array_sum(array_map(fn ($l, $e) => isset($l['custom']) ? (int) $e['net'] : 0, $lines, $expected['lines']));
        $reduction = (int) array_sum(array_map(fn ($l) => isset($l['priceOverride']) ? max(0, (int) $l['priceOverride']['difference']) : 0, $lines));
        $capCode = match (true) {
            $customTotal > 0 && !PosApprovalService::isManager($seller) && PosLineService::beyond($customTotal, $lineSettings['customItems']['maxAmount']) => 'CUSTOM_ITEM_LIMIT_EXCEEDED',
            $reduction > 0 && !PosApprovalService::isManager($seller) && PosLineService::beyond($reduction, $lineSettings['priceOverride']['maxAmount']) => 'PRICE_OVERRIDE_LIMIT_EXCEEDED',
            default => null,
        };
        if (!$within || $packExpired || $tooOld || $capCode !== null) {
            $code = !$within ? 'PRICE_DRIFT_EXCEEDED' : ($packExpired ? 'PACK_EXPIRED' : ($tooOld ? 'OFFLINE_TOO_OLD' : $capCode));
            if ($forceToken === null) {
                throw new CashRegisterException($code, match ($code) {
                    'PRICE_DRIFT_EXCEEDED' => 'Écart entre le total perçu et le recalcul au pack déclaré au-delà de la tolérance',
                    'PACK_EXPIRED' => 'Vente datée après la fin de validité du pack déclaré',
                    'CUSTOM_ITEM_LIMIT_EXCEEDED' => 'Articles libres au-delà du montant maximal sans validation',
                    'PRICE_OVERRIDE_LIMIT_EXCEEDED' => 'Baisse de prix manuelle au-delà du maximum sans validation',
                    default => 'Vente trop ancienne pour la durée hors ligne autorisée',
                }, 409, [], ['drift' => $driftAmount, 'tolerance' => $settings['priceTolerance'], 'expected' => $expected['total'], 'declared' => $declaredTotal, 'taxes' => $taxDrift, 'packValidUntil' => $pack->getValidUntil()->format(\DateTimeInterface::ATOM),
                    'customItemsTotal' => $customTotal, 'customItemsMax' => $lineSettings['customItems']['maxAmount'], 'priceOverrideReduction' => $reduction, 'priceOverrideMax' => $lineSettings['priceOverride']['maxAmount']]);
            }
            // Acceptation forcée par un responsable (C3) : jeton par vente, contexte {terminalId, counter, amount}
            preg_match('/^P-(\d+)-(\d+)$/', $sale['provisionalNumber'], $pn);
            $this->approvals->requireFor(PosApprovalService::OFFLINE_FORCE, $syncedBy, ['terminalId' => $terminalId, 'counter' => (int) $pn[2], 'amount' => $declaredCash], $declaredCash, $forceToken);
            $forced = ['code' => $code, 'approvedBy' => $this->approvals->approvedBy() ?? (PosApprovalService::isManager($syncedBy) ? ['id' => $syncedBy->getId(), 'name' => trim($syncedBy->getFirstname() . ' ' . $syncedBy->getLastname())] : null)];
        }
        if ($cashPaid !== $declaredCash) {
            throw new CashRegisterException('OFFLINE_SALE_INVALID', 'La somme des paiements en espèces doit égaler totals.cashTotal', 422, [], ['cashPaid' => $cashPaid, 'cashTotal' => $declaredCash]);
        }

        // Commande aux montants perçus et taxes imprimées, datée de la vente corrigée
        $this->receipts->lockRegister($register);
        $order = $this->orders->createOrder($seller, $em->getReference(OrderSource::class, 2), null, null, $em->getReference(StatusCommande::class, 3), $em->getReference(OrderType::class, 1));
        $order->setOrderDate(\DateTime::createFromImmutable($soldAtCorrected));
        $order->setSyncedAt($now);
        $stockShortfall = [];
        foreach ($lines as $i => $line) {
            $variant = $variants[$i];
            $computed = $expected['lines'][$i];
            if ($variant === null) { // article libre (F7) : ligne sans variante, aucun stock
                $item = (new \App\Entity\OrderItems())->setOrderAssociated($order)->setQuantity($line['quantity'])->setUnitPrice((float) $line['unitPrice'])->setTotalPrice((float) $computed['net'])
                    ->setCustomLabel($line['custom']['label'])->setTaxable($line['custom']['taxable']);
                $order->addOrderItem($item);
                if ($computed['discountAmount'] > 0) {
                    $applied = PosDiscountCalculator::apply([['kind' => 'sale', 'unitPrice' => $line['unitPrice'], 'quantity' => $line['quantity'], 'total' => $line['unitPrice'] * $line['quantity']]], [['discounts' => $line['discounts']]], null);
                    $item->setDiscounts($applied['lines'][0]['discounts'])->setDiscountAmount($computed['discountAmount']);
                }
                $em->persist($item);
                continue;
            }
            $unitPrice = isset($line['priceOverride']) ? (int) $line['priceOverride']['unitPrice'] : (int) $line['unitPrice'];
            $item = $this->orderItems->createOrderItem($order, $variant, $line['quantity'], (float) $unitPrice);
            if (isset($line['priceOverride'])) { // prix manuel (F7) : prix du pack gardé comme prix catalogue, motif
                $item->setCatalogUnitPrice((int) $line['catalogUnitPrice'])->setPriceReasonCode($line['priceOverride']['reasonCode'])->setPriceReasonText($line['priceOverride']['reasonText']);
            }
            if ($computed['discountAmount'] > 0) {
                $applied = PosDiscountCalculator::apply([['kind' => 'sale', 'unitPrice' => $unitPrice, 'quantity' => $line['quantity'], 'total' => $unitPrice * $line['quantity']]], [['discounts' => $line['discounts']]], null);
                $item->setDiscounts($applied['lines'][0]['discounts'])->setDiscountAmount($computed['discountAmount'])->setTotalPrice((float) $computed['net']);
            }
            $em->persist($item);
            $before = $variant->getStockQuantity();
            $this->stock->execute($variant, $line['quantity'], false);
            if ($variant->getStockQuantity() < 0) {
                $stockShortfall[] = ['productVariantId' => $variant->getId(), 'shortBy' => -$variant->getStockQuantity(), 'stockBefore' => $before];
            }
        }
        if ($expected['ticketDiscount']) {
            $t = $expected['ticketDiscount'];
            $order->setDiscountAmount((int) $t['amount'])->setDiscountPercentBp($t['type'] === 'percent' ? (int) round($t['value'] * 100) : null)->setDiscountReasonCode($t['reasonCode'] ?? null)->setDiscountReasonText($t['reasonText'] ?? null);
        }
        $declaredSubtotal = (int) $declared['subtotal'];
        $declaredTaxTotal = 0;
        foreach ($expected['taxes'] as $k => $t) {
            $amount = (int) ($declaredTaxes[$k]['amount'] ?? $t['amount']);
            $declaredTaxTotal += $amount;
            $tax = isset($snapshot['taxes'][$k]['id']) ? $em->find(Tax::class, (int) $snapshot['taxes'][$k]['id']) : null;
            if ($tax) {
                $orderTax = (new OrderTax())->setOrderTax($order)->setTax($tax)->setAmount($amount);
                $order->addOrderTax($orderTax);
                $em->persist($orderTax);
            }
        }
        $cashRounding = $declaredCash - $declaredTotal;
        $order->setSubTotal($declaredSubtotal)->setTotalTax($declaredTaxTotal)->setTotalAmount($declaredTotal)->setCashRounding($cashRounding);
        $em->persist($order);
        $em->flush();
        $this->payments->handlePayment($order, $declaredCash, 2, 2, 2, \DateTime::createFromImmutable($soldAtCorrected), null);
        $this->caisseTransactions->execute($caisse, $order, $seller, $declaredTotal, HandleCaisseTransactionUseCase::SALE, [], $declaredCash, $cashRounding);
        $receipt = $this->receipts->issue($order, $caisse, PosReceipt::TICKET, $declaredTotal);
        $receipt->setProvisionalNumber($sale['provisionalNumber']);
        $em->flush();
        $offline = ['provisionalNumber' => $sale['provisionalNumber'], 'soldAt' => $soldAtCorrected->format(\DateTimeInterface::ATOM), 'deviceSoldAt' => $soldAt->format(\DateTimeInterface::ATOM), 'clockOffsetSeconds' => $offset,
            'packVersion' => $packVersion, 'chain' => $sale['chain'] ?? null, 'chainBroken' => $chainBroken, 'priceDrift' => ['amount' => $driftAmount, 'taxes' => $taxDrift, 'within' => $within], 'currentPriceDrift' => $currentDrift,
            'stockShortfall' => $stockShortfall, 'lateEntry' => $lateEntry, 'forced' => $forced, 'cashReceived' => is_numeric($sale['cashReceived'] ?? null) ? (int) $sale['cashReceived'] : null];
        $this->audit->record(PosAuditService::SALE, [
            'kind' => 'sale', 'reference' => $order->getReference(), 'receiptKind' => $receipt->getKind(), 'receiptNumber' => $receipt->getNumber(),
            'subTotal' => $declaredSubtotal, 'totalTax' => $declaredTaxTotal, 'totalAmount' => $declaredTotal, 'cashRounding' => $cashRounding, 'discountTotal' => $expected['discountTotal'],
            'payments' => [['type' => 2, 'amount' => $declaredCash]], 'items' => array_map(fn ($l, $v) => ['productVariantId' => $v?->getId(), 'quantity' => $l['quantity'], 'unitPrice' => isset($l['priceOverride']) ? $l['priceOverride']['unitPrice'] : $l['unitPrice']] + (isset($l['custom']) ? ['custom' => $l['custom']] : []), $lines, $variants),
            'offline' => $offline, 'syncedBy' => ['id' => $syncedBy->getId(), 'name' => trim($syncedBy->getFirstname() . ' ' . $syncedBy->getLastname())],
        ] + ($customTotal > 0 ? ['customItemsTotal' => $customTotal] : []), $caisse, null, 'order', $order->getId(), $seller);
        foreach ($order->getOrderItems() as $i) { // prix manuels (F7) : une entrée PRICE_CHANGED par ligne, comme en ligne
            if ($i->hasPriceOverride()) {
                $this->audit->record(PosAuditService::PRICE_CHANGED, [
                    'reference' => $order->getReference(), 'receiptNumber' => $receipt->getNumber(), 'orderItemId' => $i->getId(), 'productVariantId' => $i->getProductVariant()?->getId(),
                    'name' => $i->displayName(), 'quantity' => $i->getQuantity(), 'before' => $i->getCatalogUnitPrice(), 'after' => (int) round((float) $i->getUnitPrice()),
                    'difference' => ($i->getCatalogUnitPrice() - (int) round((float) $i->getUnitPrice())) * $i->getQuantity(), 'reason' => ['code' => $i->getPriceReasonCode(), 'text' => $i->getPriceReasonText()],
                    'offline' => ['provisionalNumber' => $sale['provisionalNumber']], 'approvedBy' => $forced['approvedBy'] ?? null,
                ], $caisse, null, 'order', $order->getId(), $seller);
            }
        }

        return ['provisionalNumber' => $sale['provisionalNumber'], 'idempotencyKey' => $key, 'orderId' => $order->getId(), 'cashRegisterId' => $register->getId(), 'packVersion' => $packVersion,
            'receiptNumber' => $receipt->getNumber(), 'receiptReference' => $register->receiptReference($receipt->getNumber()), 'barcode' => sprintf('IZ-T-%d-%d', $register->getId(), $receipt->getNumber()),
            'soldAt' => $soldAtCorrected->format(\DateTimeInterface::ATOM), 'priceDrift' => $offline['priceDrift'], 'currentPriceDrift' => $currentDrift, 'stockShortfall' => $stockShortfall,
            'lateEntry' => $lateEntry, 'chainBroken' => $chainBroken, 'forced' => $forced !== null, 'totals' => ['expected' => $expected['total'], 'declared' => $declaredTotal, 'cashTotal' => $declaredCash]];
    }

    /** Empreinte d'une vente pour le chaînage : sha256(prevHash + JSON canonique de la vente sans chain) */
    public static function hashOf(array $sale, string $prevHash): string
    {
        unset($sale['chain']);

        return hash('sha256', $prevHash . self::canonical($sale));
    }

    /** JSON canonique : clés triées récursivement, sans espaces, UTF-8 et barres obliques non échappés */
    public static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value, SORT_STRING);
            }
        }

        return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            return array_map(self::sortKeys(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sortKeys(...), $value);
    }

    private function lastHash(int $terminalId): ?string
    {
        $last = $this->emProvider->getEntityManager()->getRepository(PosOfflineSale::class)->findOneBy(['terminalId' => $terminalId], ['id' => 'DESC']);

        return $last?->getChainHash();
    }
}
