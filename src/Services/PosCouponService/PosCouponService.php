<?php

namespace App\Services\PosCouponService;

use App\Dto\PaymentMethodDTO;
use App\Entity\Caisse;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\PosManufacturerCoupon;
use App\Entity\User;
use App\Repository\PosManufacturerCouponRepository;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosLineService\PosLineService;
use App\Services\TenantEntityManagerProvider;

/**
 * Coupons de fabricant en caisse (F17, 01/11/2026). Bon remboursable par un tiers : traité comme un MOYEN DE PAIEMENT (part type 6
 * de create-multi-payment, modèle F9), jamais comme une remise : la base taxable et les taxes sont celles du prix plein
 * (Loi sur la taxe d'accise, art. 181(2) ; Revenu Québec, bons de réduction remboursables). Plusieurs coupons par vente ; montant
 * jamais supérieur au prix de la ligne concernée (ou au dû) ; même coupon sérialisé refusé une seconde fois ; coupon expiré
 * refusé. Retours : la part coupon n'est jamais remboursée au client ; coupon annulé (voided) si pas encore réclamé, sinon
 * « retourné après réclamation ». Réclamation par fabricant et période, marquage par lot. Hors ligne : refusé (C5).
 */
class PosCouponService
{
    public const TYPE = 6;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosAuditService $audit,
        private readonly \App\Services\CaisseService\StoreClock $clock,
    ) {
    }

    // ---------------------------------------------------------------- réglages

    public function settings(): array
    {
        return self::settingsOf($this->entreprise());
    }

    /** @return array{enabled: bool, maxWithoutApproval: int, barcodeFormats: list<string>, offline: bool} */
    public static function settingsOf(?Entreprise $e): array
    {
        return [
            'enabled' => $e?->isPosCouponEnabled() ?? false,
            'maxWithoutApproval' => $e?->getPosCouponMaxWithoutApproval() ?? 2000, // cents par vente ; 0 = toujours une validation
            'barcodeFormats' => [CouponBarcodeParser::FORMAT_DATABAR, CouponBarcodeParser::FORMAT_UPC5],
            'taxOnFullPrice' => true,
            'offline' => false,
        ];
    }

    // ---------------------------------------------------------------- lecture d'un code-barres

    /** POST /api/pos/coupons/parse : champs reconnus ; amountReliable false → le POS demande le montant */
    public function parse(mixed $barcode): array
    {
        if (!is_string($barcode) || trim($barcode) === '') {
            throw new CashRegisterException('VALIDATION_FAILED', 'barcode : chaîne attendue', 422, [['path' => 'barcode', 'message' => 'requis']]);
        }
        try {
            $parsed = CouponBarcodeParser::parse($barcode);
        } catch (CouponBarcodeException $e) {
            throw new CashRegisterException('COUPON_BARCODE_INVALID', $e->getMessage(), 422, [], ['barcode' => $barcode]);
        }
        $parsed['expired'] = $parsed['expiresAt'] !== null && $parsed['expiresAt'] < $this->today();
        if ($parsed['serial'] !== null && $parsed['companyPrefix'] !== null && $parsed['offerCode'] !== null) {
            $parsed['alreadyUsed'] = $this->repo()->findActiveSerial($parsed['companyPrefix'], $parsed['offerCode'], $parsed['serial']) !== null;
        } else {
            $parsed['alreadyUsed'] = null; // sans numéro de série : aucun contrôle possible
        }

        return $parsed;
    }

    // ---------------------------------------------------------------- devis et vente

    /**
     * Coupons d'une demande (devis ou vente) normalisés et contrôlés : site, structure, montant, expiration, doublon de série.
     *
     * @param list<array> $raw [{amount, manufacturer?, barcode?, offerCode?, serial?, productVariantId?, expiresAt?}]
     * @param ?int $amountDue montant dû restant (cents) ; le total des coupons ne peut pas le dépasser
     * @return list<array{amount: int, manufacturer: string, companyPrefix: ?string, offerCode: ?string, serial: ?string, barcode: ?string, format: ?string, source: string, productVariantId: ?int, expiresAt: ?string}>
     */
    public function normalize(array $raw, ?int $amountDue = null, bool $forSale = false): array
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            throw new CashRegisterException('COUPON_DISABLED', 'Les coupons de fabricant ne sont pas acceptés sur ce site', 409);
        }
        $out = [];
        $seen = [];
        $total = 0;
        foreach (array_values($raw) as $i => $c) {
            if (!is_array($c)) {
                throw new CashRegisterException('VALIDATION_FAILED', "coupons[$i] : objet attendu", 422, [['path' => "coupons[$i]", 'message' => 'objet attendu']]);
            }
            $parsed = null;
            if (is_string($c['barcode'] ?? null) && trim($c['barcode']) !== '') {
                try {
                    $parsed = CouponBarcodeParser::parse($c['barcode']);
                } catch (CouponBarcodeException $e) {
                    throw new CashRegisterException('COUPON_BARCODE_INVALID', $e->getMessage(), 422, [['path' => "coupons[$i].barcode", 'message' => $e->getMessage()]]);
                }
            }
            $amount = is_numeric($c['amount'] ?? null) ? (int) $c['amount'] : ($parsed['amount'] ?? null);
            if ($amount === null || $amount <= 0 || (float) ($c['amount'] ?? $amount) !== (float) $amount) {
                throw new CashRegisterException('COUPON_AMOUNT_INVALID', 'Montant du coupon : entier positif en cents attendu (saisi par le caissier quand le code-barres ne le donne pas)', 422, [['path' => "coupons[$i].amount", 'message' => 'cents > 0']]);
            }
            $manufacturer = trim((string) ($c['manufacturer'] ?? ''));
            if ($manufacturer === '' && $parsed !== null && $parsed['companyPrefix'] !== null) {
                $manufacturer = 'GS1 ' . $parsed['companyPrefix'];
            }
            if ($manufacturer === '') {
                throw new CashRegisterException('VALIDATION_FAILED', "coupons[$i].manufacturer : fabricant requis (ou code-barres lisible)", 422, [['path' => "coupons[$i].manufacturer", 'message' => 'requis']]);
            }
            $expires = $parsed['expiresAt'] ?? (is_string($c['expiresAt'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['expiresAt']) ? $c['expiresAt'] : null);
            if ($expires !== null && $expires < $this->today()) {
                $this->refuse('expired', $manufacturer, $parsed, $amount);
                throw new CashRegisterException('COUPON_EXPIRED', sprintf('Coupon expiré le %s : à retirer de la vente', $expires), 422, [['path' => "coupons[$i]", 'message' => 'expiré']], ['expiresAt' => $expires, 'manufacturer' => $manufacturer, 'companyPrefix' => $parsed['companyPrefix'] ?? null, 'offerCode' => $parsed['offerCode'] ?? null, 'serial' => $parsed['serial'] ?? null]);
            }
            $prefix = $parsed['companyPrefix'] ?? (is_string($c['companyPrefix'] ?? null) ? preg_replace('/\D/', '', $c['companyPrefix']) : null);
            $offer = $parsed['offerCode'] ?? (is_string($c['offerCode'] ?? null) ? trim($c['offerCode']) : null);
            $serial = $parsed['serial'] ?? (is_string($c['serial'] ?? null) ? trim($c['serial']) : null);
            if ($prefix !== null && $offer !== null && $serial !== null && $serial !== '') {
                $key = $prefix . '|' . $offer . '|' . $serial;
                if (isset($seen[$key]) || $this->repo()->findActiveSerial($prefix, (string) $offer, $serial) !== null) {
                    $this->refuse('already_used', $manufacturer, $parsed, $amount);
                    throw new CashRegisterException('COUPON_ALREADY_USED', 'Ce coupon (fabricant, offre, numéro de série) a déjà été accepté sur ce site', 409, [['path' => "coupons[$i]", 'message' => 'déjà utilisé']], ['manufacturer' => $manufacturer, 'companyPrefix' => $prefix, 'offerCode' => $offer, 'serial' => $serial]);
                }
                $seen[$key] = true;
            }
            $total += $amount;
            $out[] = ['amount' => $amount, 'manufacturer' => $manufacturer, 'companyPrefix' => $prefix !== '' ? $prefix : null, 'offerCode' => $offer !== '' ? $offer : null, 'serial' => $serial !== '' ? $serial : null,
                'barcode' => $parsed !== null ? trim($c['barcode']) : null, 'format' => $parsed['format'] ?? null, 'source' => $parsed !== null ? PosManufacturerCoupon::SOURCE_SCAN : PosManufacturerCoupon::SOURCE_MANUAL,
                'productVariantId' => is_numeric($c['productVariantId'] ?? null) ? (int) $c['productVariantId'] : null, 'expiresAt' => $expires, 'amountReliable' => $parsed['amountReliable'] ?? null];
        }
        if ($amountDue !== null && $total > $amountDue) {
            throw new CashRegisterException('COUPON_EXCEEDS_DUE', 'Le total des coupons dépasse le montant dû', 422, [], ['coupons' => $total, 'amountDue' => $amountDue]);
        }

        return $out;
    }

    /**
     * Devis de caisse : coupons présentés, contrôlés contre les lignes du devis ; rien n'est enregistré.
     *
     * @return array{coupons: list<array>, count: int, total: int, amountDue: int}
     */
    public function preview(mixed $raw, array $quote, int $amountDue): array
    {
        $coupons = $this->normalize(is_array($raw) ? $raw : [], $amountDue);
        $this->checkAgainstLines($coupons, array_map(fn (array $l) => ['productVariantId' => (int) ($l['productVariantId'] ?? 0), 'net' => (int) ($l['total'] ?? $l['net'] ?? 0)], $quote['lines'] ?? []));
        $total = (int) array_sum(array_column($coupons, 'amount'));

        return ['coupons' => $coupons, 'count' => count($coupons), 'total' => $total, 'amountDue' => max(0, $amountDue - $total)];
    }

    /** Parts de type 6 d'une vente → coupons normalisés (contrôles hors prix de ligne, faits à l'enregistrement) */
    public function fromParts(array $parts): array
    {
        return $this->normalize(array_map(fn (PaymentMethodDTO $p) => ($p->getCoupon() ?? []) + ['amount' => (int) round($p->getAmount() * 100)], $parts));
    }

    /** Validation C3 au-delà du montant maximal par vente (réglage du site, 0 = toujours) ; jamais pour un responsable */
    public function demand(User $user, int $total): ?array
    {
        if ($total <= 0 || PosApprovalService::isManager($user)) {
            return null;
        }

        return PosLineService::beyond($total, $this->settings()['maxWithoutApproval']) ? ['action' => PosApprovalService::MANUFACTURER_COUPON, 'context' => ['amount' => $total], 'amount' => $total] : null;
    }

    /**
     * Dans la transaction de la vente : contrôle des montants contre les lignes enregistrées, écriture des coupons, part portée par
     * order.manufacturer_coupon_applied, journal.
     *
     * @return list<PosManufacturerCoupon>
     */
    public function redeem(array $coupons, Order $order, User $user, Caisse $caisse): array
    {
        $lines = [];
        $items = [];
        foreach ($order->getOrderItems() as $item) {
            $vid = (int) ($item->getProductVariant()?->getId() ?? 0);
            $lines[] = ['productVariantId' => $vid, 'net' => (int) round((float) $item->getTotalPrice())];
            $items[$vid] ??= $item;
        }
        $this->checkAgainstLines($coupons, $lines);
        $em = $this->emProvider->getEntityManager();
        $rows = [];
        $total = 0;
        foreach ($coupons as $c) {
            /** @var ?OrderItems $item */
            $item = $c['productVariantId'] !== null ? ($items[$c['productVariantId']] ?? null) : null;
            $row = (new PosManufacturerCoupon())->setOrderId((int) $order->getId())->setOrderItemId($item?->getId())->setCashRegisterId($caisse->getCashRegister()?->getId())->setCaisseId($caisse->getId())
                ->setUserId((int) $user->getId())->setManufacturer($c['manufacturer'])->setCompanyPrefix($c['companyPrefix'])->setOfferCode($c['offerCode'])->setSerial($c['serial'])->setBarcode($c['barcode'])
                ->setFormat($c['format'])->setAmount($c['amount'])->setSource($c['source'])->setExpiresAt($c['expiresAt'] !== null ? new \DateTimeImmutable($c['expiresAt']) : null);
            $em->persist($row);
            $rows[] = $row;
            $total += $c['amount'];
        }
        $order->setManufacturerCouponApplied($total);
        $em->flush();
        foreach ($rows as $row) {
            $this->audit->record(PosAuditService::MANUFACTURER_COUPON_REDEEMED, ['couponId' => $row->getId(), 'manufacturer' => $row->getManufacturer(), 'companyPrefix' => $row->getCompanyPrefix(), 'offerCode' => $row->getOfferCode(),
                'serial' => $row->getSerial(), 'amount' => $row->getAmount(), 'source' => $row->getSource(), 'orderItemId' => $row->getOrderItemId()], $caisse, null, 'order', $order->getId(), $user);
        }

        return $rows;
    }

    /** Montant d'un coupon jamais supérieur au net de la ligne concernée (toutes ses unités) ; sans ligne : au net de la vente */
    private function checkAgainstLines(array $coupons, array $lines): void
    {
        $saleNet = (int) array_sum(array_column($lines, 'net'));
        foreach ($coupons as $i => $c) {
            if ($c['productVariantId'] !== null) {
                $line = null;
                foreach ($lines as $l) {
                    if ($l['productVariantId'] === $c['productVariantId']) {
                        $line = $l;
                        break;
                    }
                }
                if ($line === null) {
                    throw new CashRegisterException('COUPON_ITEM_NOT_IN_SALE', 'L\'article du coupon n\'est pas dans la vente', 422, [['path' => "coupons[$i].productVariantId", 'message' => 'absent de la vente']], ['productVariantId' => $c['productVariantId']]);
                }
                if ($c['amount'] > $line['net']) {
                    throw new CashRegisterException('COUPON_EXCEEDS_ITEM', 'Le coupon dépasse le prix de l\'article concerné', 422, [['path' => "coupons[$i].amount", 'message' => 'supérieur au prix de l\'article']], ['amount' => $c['amount'], 'itemNet' => $line['net'], 'productVariantId' => $c['productVariantId']]);
                }
            } elseif ($c['amount'] > $saleNet) {
                throw new CashRegisterException('COUPON_EXCEEDS_ITEM', 'Le coupon dépasse le montant des articles de la vente', 422, [['path' => "coupons[$i].amount", 'message' => 'supérieur aux articles']], ['amount' => $c['amount'], 'saleNet' => $saleNet]);
            }
        }
    }

    // ---------------------------------------------------------------- retours et annulations

    /** @return list<PosManufacturerCoupon> */
    public function couponsOf(Order $order): array
    {
        return $this->repo()->forOrder((int) $order->getId());
    }

    /**
     * Part coupon attribuable aux unités retournées (jamais remboursée au client) : coupon lié à une ligne → au prorata des unités
     * retournées de cette ligne ; coupon sans ligne → au prorata du net retourné sur le net de la vente.
     *
     * @param list<array{item: OrderItems, quantity: int, net: int}> $lines lignes du retour
     * @return array{notRefunded: int, coupons: list<array{id: int, manufacturer: string, amount: int, status: string, part: int}>}
     */
    public function attributed(Order $original, array $lines): array
    {
        $coupons = $this->couponsOf($original);
        if ($coupons === []) {
            return ['notRefunded' => 0, 'coupons' => []];
        }
        $saleNet = (int) array_sum(array_map(fn (OrderItems $i) => (int) round((float) $i->getTotalPrice()), $original->getOrderItems()->toArray()));
        $returnedNet = (int) array_sum(array_column($lines, 'net'));
        $byItem = [];
        foreach ($lines as $l) {
            $byItem[(int) $l['item']->getId()] = ['qty' => (int) $l['quantity'], 'sold' => (int) $l['item']->getQuantity()];
        }
        $out = [];
        $total = 0;
        foreach ($coupons as $c) {
            if (!$c->isActive()) {
                $part = 0;
            } elseif ($c->getOrderItemId() !== null) {
                $r = $byItem[$c->getOrderItemId()] ?? null;
                $part = $r && $r['sold'] > 0 ? (int) round($c->getAmount() * $r['qty'] / $r['sold']) : 0;
            } else {
                $part = $saleNet > 0 ? (int) round($c->getAmount() * $returnedNet / $saleNet) : 0;
            }
            $part = min($part, $c->getAmount());
            $total += $part;
            $out[] = ['id' => (int) $c->getId(), 'manufacturer' => $c->getManufacturer(), 'amount' => $c->getAmount(), 'status' => $c->getStatus(), 'part' => $part];
        }

        return ['notRefunded' => $total, 'coupons' => $out];
    }

    /**
     * Après un retour (ou une annulation : $all = true) : coupon lié à une ligne entièrement retournée (cumul), ou coupon sans ligne
     * quand toute la vente est retournée → annulé (voided) s'il n'est pas réclamé, sinon « retourné après réclamation ».
     *
     * @param array<int, int> $returnedCumulative quantités retournées par ligne (cumul après ce retour)
     * @return array{voided: int, returnedAfterClaim: int, coupons: list<array>}
     */
    public function onReturn(Order $original, ?Order $returnOrder, array $returnedCumulative, bool $all, ?Caisse $caisse, ?User $user): array
    {
        $result = ['voided' => 0, 'returnedAfterClaim' => 0, 'coupons' => []];
        $coupons = $this->couponsOf($original);
        if ($coupons === []) {
            return $result;
        }
        $sold = [];
        foreach ($original->getOrderItems() as $item) {
            $sold[(int) $item->getId()] = (int) $item->getQuantity();
        }
        $everything = $all;
        if (!$everything) {
            $everything = true;
            foreach ($sold as $id => $qty) {
                if (($returnedCumulative[$id] ?? 0) < $qty) {
                    $everything = false;
                    break;
                }
            }
        }
        foreach ($coupons as $c) {
            if (!$c->isActive()) {
                continue;
            }
            $covered = $everything || ($c->getOrderItemId() !== null && ($returnedCumulative[$c->getOrderItemId()] ?? 0) >= ($sold[$c->getOrderItemId()] ?? PHP_INT_MAX));
            if (!$covered) {
                continue;
            }
            $before = $c->getStatus();
            $c->markReturned($caisse?->getId(), $returnOrder?->getId());
            $result[$c->getStatus() === PosManufacturerCoupon::VOIDED ? 'voided' : 'returnedAfterClaim'] += $c->getAmount();
            $result['coupons'][] = ['id' => $c->getId(), 'manufacturer' => $c->getManufacturer(), 'amount' => $c->getAmount(), 'status' => $c->getStatus()];
            $this->audit->record(PosAuditService::MANUFACTURER_COUPON_VOIDED, ['couponId' => $c->getId(), 'manufacturer' => $c->getManufacturer(), 'amount' => $c->getAmount(), 'before' => $before, 'after' => $c->getStatus(),
                'returnOrderId' => $returnOrder?->getId(), 'kind' => $all ? 'cancellation' : 'return'], $caisse, null, 'order', $original->getId(), $user);
        }
        $this->emProvider->getEntityManager()->flush();

        return $result;
    }

    // ---------------------------------------------------------------- réclamation

    /**
     * Rapport des coupons par fabricant et période d'acceptation (responsable).
     *
     * @param array{from?: mixed, to?: mixed, manufacturer?: mixed, status?: mixed} $filters status : to_claim (défaut) | claimed | returned_after_claim | all
     */
    public function claims(array $filters): array
    {
        [$from, $to, $fromDay, $toDay] = $this->period($filters);
        $status = is_string($filters['status'] ?? null) && $filters['status'] !== '' ? $filters['status'] : 'to_claim';
        $statuses = match ($status) {
            'to_claim' => [PosManufacturerCoupon::REDEEMED],
            'claimed' => [PosManufacturerCoupon::CLAIMED],
            'returned_after_claim' => [PosManufacturerCoupon::RETURNED_AFTER_CLAIM],
            'voided' => [PosManufacturerCoupon::VOIDED],
            'all' => PosManufacturerCoupon::STATUSES,
            default => throw new CashRegisterException('VALIDATION_FAILED', 'status : to_claim, claimed, returned_after_claim, voided ou all', 422, [['path' => 'status', 'message' => 'valeur inconnue']]),
        };
        $manufacturer = is_string($filters['manufacturer'] ?? null) ? trim($filters['manufacturer']) : null;
        $rows = $this->repo()->claims($from, $to, $manufacturer ?: null, $statuses);
        // Fractions de taxe d'après les taxes réellement facturées sur la vente de chaque coupon (TPS et TVQ au Québec, TVH ailleurs)
        $orderTaxes = $this->taxesForOrders(array_values(array_unique(array_map(fn (PosManufacturerCoupon $c) => $c->getOrderId(), $rows))));
        $labels = [];
        foreach ($orderTaxes as $taxes) {
            foreach ($taxes as $label => $_) {
                $labels[$label] = true;
            }
        }
        $labels = array_keys($labels);
        $byManufacturer = [];
        $list = [];
        $fractions = array_fill_keys($labels, 0);
        foreach ($rows as $c) {
            $m = $c->getManufacturer();
            $byManufacturer[$m] ??= ['manufacturer' => $m, 'count' => 0, 'total' => 0, 'taxFractions' => array_fill_keys($labels, 0)];
            $byManufacturer[$m]['count']++;
            $byManufacturer[$m]['total'] += $c->getAmount();
            $row = $this->toArray($c);
            $row['taxFractions'] = array_fill_keys($labels, 0);
            foreach ($orderTaxes[$c->getOrderId()] ?? [] as $label => $rate) {
                $f = self::taxFraction($c->getAmount(), $rate);
                $row['taxFractions'][$label] = $f;
                $byManufacturer[$m]['taxFractions'][$label] += $f;
                $fractions[$label] += $f;
            }
            $list[] = $row;
        }
        $total = (int) array_sum(array_column($list, 'amount'));

        return ['period' => ['from' => $fromDay, 'to' => $toDay], 'status' => $status, 'manufacturer' => $manufacturer ?: null, 'count' => count($list), 'total' => $total,
            'taxFractions' => $fractions, 'taxRule' => 'Taxes calculées sur le prix plein (LTA art. 181(2), TVQ) ; la fraction de taxe du coupon est une information pour le comptable (CTI/RTI), aucune écriture automatique',
            'byManufacturer' => array_values($byManufacturer), 'coupons' => $list];
    }

    /** CSV (« ; », virgule décimale, BOM) du rapport ci-dessus */
    public function claimsCsv(array $report): string
    {
        $taxes = array_keys($report['taxFractions']);
        $header = array_merge(['Fabricant', 'Préfixe GS1', 'Code offre', 'Numéro de série', 'Accepté le', 'Vente', 'Montant', 'Statut', 'Réclamé le', 'Lot', 'Source'], array_map(fn ($t) => 'Fraction ' . $t, $taxes));
        $rows = [];
        foreach ($report['coupons'] as $c) {
            $row = [$c['manufacturer'], $c['companyPrefix'], $c['offerCode'], $c['serial'], $c['redeemedAt'], $c['orderId'], \App\Services\AccountingService\AccountingCsv::fr($c['amount']), $c['status'], $c['claimedAt'], $c['claimBatch'], $c['source']];
            foreach ($taxes as $t) {
                $row[] = \App\Services\AccountingService\AccountingCsv::fr((int) ($c['taxFractions'][$t] ?? 0));
            }
            $rows[] = $row;
        }

        return \App\Services\AccountingService\AccountingCsv::write($header, $rows);
    }

    /** Passe en « réclamé » les coupons à réclamer de la période (et du fabricant) : un lot daté, journalisé */
    public function markClaimed(array $filters, User $user): array
    {
        [$from, $to, $fromDay, $toDay] = $this->period($filters);
        $manufacturer = is_string($filters['manufacturer'] ?? null) ? trim($filters['manufacturer']) : null;
        $rows = $this->repo()->claims($from, $to, $manufacturer ?: null, [PosManufacturerCoupon::REDEEMED]);
        if ($rows === []) {
            throw new CashRegisterException('NOTHING_TO_CLAIM', 'Aucun coupon à réclamer pour cette période', 409, [], ['from' => $fromDay, 'to' => $toDay, 'manufacturer' => $manufacturer]);
        }
        $batch = sprintf('CL-%s-%s', (new \DateTimeImmutable())->format('YmdHis'), substr(bin2hex(random_bytes(3)), 0, 6));
        $total = 0;
        $byManufacturer = [];
        foreach ($rows as $c) {
            $c->markClaimed($batch);
            $total += $c->getAmount();
            $byManufacturer[$c->getManufacturer()] = ($byManufacturer[$c->getManufacturer()] ?? 0) + $c->getAmount();
        }
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::MANUFACTURER_COUPONS_CLAIMED, ['batch' => $batch, 'from' => $fromDay, 'to' => $toDay, 'manufacturer' => $manufacturer ?: null, 'count' => count($rows), 'total' => $total, 'byManufacturer' => $byManufacturer], null, null, 'coupon_claim', null, $user);

        return ['batch' => $batch, 'count' => count($rows), 'total' => $total, 'byManufacturer' => $byManufacturer, 'period' => ['from' => $fromDay, 'to' => $toDay], 'manufacturer' => $manufacturer ?: null];
    }

    // ---------------------------------------------------------------- représentation, ticket, rapports

    public function toArray(PosManufacturerCoupon $c): array
    {
        return ['id' => $c->getId(), 'orderId' => $c->getOrderId(), 'orderItemId' => $c->getOrderItemId(), 'manufacturer' => $c->getManufacturer(), 'companyPrefix' => $c->getCompanyPrefix(), 'offerCode' => $c->getOfferCode(),
            'serial' => $c->getSerial(), 'format' => $c->getFormat(), 'source' => $c->getSource(), 'amount' => $c->getAmount(), 'status' => $c->getStatus(), 'expiresAt' => $c->getExpiresAt()?->format('Y-m-d'),
            'redeemedAt' => $c->getRedeemedAt()->format(\DateTimeInterface::ATOM), 'claimedAt' => $c->getClaimedAt()?->format(\DateTimeInterface::ATOM), 'claimBatch' => $c->getClaimBatch(),
            'voidedAt' => $c->getVoidedAt()?->format(\DateTimeInterface::ATOM), 'returnOrderId' => $c->getReturnOrderId()];
    }

    /** Lignes « Coupon fabricant » du ticket d'une commande : [{manufacturer, offerCode, amount (cents)}] */
    public function receiptBlock(Order $order): array
    {
        return array_map(fn (PosManufacturerCoupon $c) => ['manufacturer' => $c->getManufacturer(), 'offerCode' => $c->getOfferCode(), 'amount' => $c->getAmount(), 'status' => $c->getStatus()], $this->couponsOf($order));
    }

    /** Fraction de taxe d'un montant taxes comprises : montant × taux / (100 + taux) (LTA art. 181(1) ; TVQ 9,975/109,975) */
    public static function taxFraction(int $amount, float $rate): int
    {
        return $rate > 0 ? (int) round($amount * $rate / (100 + $rate)) : 0;
    }

    /** @return array<int, array<string, float>> taux (en pour cent) des taxes facturées sur chaque vente, par libellé (TPS 5, TVQ 9,975…) */
    private function taxesForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT ot.order_tax_id AS order_id, t.name, t.rate FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id IN (' . implode(',', array_map('intval', $orderIds)) . ') AND t.rate > 0 ORDER BY ot.id') as $t) {
            $rate = (float) $t['rate'];
            $out[(int) $t['order_id']][(string) $t['name']] = $rate < 1 ? $rate * 100 : $rate; // la table stocke des fractions (0,05 ; 0,09975) : en pour cent
        }

        return $out;
    }

    private function period(array $filters): array
    {
        $tz = new \DateTimeZone($this->clock->settings()['timezone']);
        $fromDay = is_string($filters['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from']) ? $filters['from'] : (new \DateTimeImmutable('now', $tz))->modify('first day of this month')->format('Y-m-d');
        $toDay = is_string($filters['to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to']) ? $filters['to'] : (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        if ($toDay < $fromDay) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Période invalide (to avant from)', 422, [['path' => 'to', 'message' => 'avant from']]);
        }
        $from = (new \DateTimeImmutable($fromDay . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'));
        $to = (new \DateTimeImmutable($toDay . ' 00:00:00', $tz))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));

        return [$from, $to, $fromDay, $toDay];
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->clock->settings()['timezone'])))->format('Y-m-d');
    }

    private function refuse(string $reason, string $manufacturer, ?array $parsed, int $amount): void
    {
        $this->audit->record(PosAuditService::MANUFACTURER_COUPON_REFUSED, ['reason' => $reason, 'manufacturer' => $manufacturer, 'companyPrefix' => $parsed['companyPrefix'] ?? null, 'offerCode' => $parsed['offerCode'] ?? null,
            'serial' => $parsed['serial'] ?? null, 'expiresAt' => $parsed['expiresAt'] ?? null, 'amount' => $amount]);
    }

    /** Refus survenu dans la transaction d'une vente (journal annulé avec elle) : réécrit après le retour arrière, d'après le contexte de l'erreur */
    public function journalRefusalAfterRollback(CashRegisterException $e, ?User $user): void
    {
        $reason = match ($e->errorCode) { 'COUPON_ALREADY_USED' => 'already_used', 'COUPON_EXPIRED' => 'expired', default => null };
        if ($reason === null) {
            return;
        }
        $c = $e->context;
        $this->audit->record(PosAuditService::MANUFACTURER_COUPON_REFUSED, ['reason' => $reason, 'manufacturer' => $c['manufacturer'] ?? null, 'companyPrefix' => $c['companyPrefix'] ?? null, 'offerCode' => $c['offerCode'] ?? null,
            'serial' => $c['serial'] ?? null, 'expiresAt' => $c['expiresAt'] ?? null, 'inSale' => true], null, null, null, null, $user);
    }

    private function repo(): PosManufacturerCouponRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(PosManufacturerCoupon::class);
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }
}
