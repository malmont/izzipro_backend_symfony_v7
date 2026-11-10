<?php

namespace App\Services\GestionService;

use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingContentService\LandingContentException;
use App\Services\TenantEntityManagerProvider;

/**
 * Appliquer un prix (lot P4, 11/11/2026, `docs/prix-gestion.md`) : aperçu sans écriture (ancien et nouveau prix, marge, alertes, empreinte),
 * application en une transaction par le **chemin d'écriture d'un article** (`LandingContentEditor` validate → hooks → flush → invalidation du cache
 * PRODUCTS → `DemoContentMarker` ; `ContentAuditRecorder` par article ou variante), journal et annulation dans les 24 h, lien avec le guide
 * (conseil marqué fait). Mode coefficient : même règle que `Product::updatePrice()` (prix affiché = coût × coefficient, sans traitement des taxes).
 */
class GestionPriceService
{
    public const MODES = ['percent', 'coefficient'];
    public const ROUNDINGS = ['none', 'ends_99', 'up_50', 'up_100'];
    public const MAX_PRODUCTS = 500;
    public const MAX_ROWS = 2000;
    public const PERCENT_RANGE = [-90, 300];
    public const COEFFICIENT_RANGE = [0.5, 20];
    public const BIG_CHANGE = 0.5;
    public const UNDO_HOURS = 24;

    /** Coût unitaire courant d'un article (règle L0, comme le simulateur) : moyenne pondérée des lignes d'achat au coût connu, sinon prix d'achat de la fiche */
    private const COST = 'COALESCE((SELECT COALESCE(SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0), AVG(l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)))'
        . ' FROM commande_line l JOIN commande lc ON lc.id = l.commande_id WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL), p.purchase_price)';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingContentEditor $editor,
        private readonly ContentAuditRecorder $audit,
        private readonly GestionAdviceService $advices,
        private readonly PeriodCalendar $calendar,
    ) {
    }

    /** @return array<string, mixed> aperçu : { rows, summary, pricesIncludeTax, previewHash, caveats } */
    public function preview(array $body, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $in = $this->input($body);
        [$products, $lineCosts] = $this->scope($in);
        $rows = [];
        foreach ($products as $p) {
            array_push($rows, ...$this->rowsFor($p, $in, $lineCosts[$p['id']] ?? null, $today));
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new GestionValidationException(['productIds' => 'TOO_MANY_ROWS'], sprintf('Plus de %d lignes (articles et variantes) : découpez la demande.', self::MAX_ROWS));
        }
        $changed = count(array_filter($rows, fn ($r) => $r['changed']));
        $includesTax = $this->conn()->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1');
        $includesTax = $includesTax !== false && in_array($includesTax, [true, 't', 1, '1'], true);

        return [
            'rows' => $rows,
            'summary' => ['products' => count($products), 'rows' => count($rows), 'changed' => $changed, 'skipped' => count($rows) - $changed],
            'pricesIncludeTax' => $includesTax,
            'previewHash' => self::hash($in, $rows),
            'caveats' => $includesTax ? [['code' => 'PRICES_INCLUDE_TAX', 'text' => 'Les prix du site sont taxes comprises : la marge affichée comprend la taxe.']] : [],
        ];
    }

    /**
     * Application : l'aperçu est recalculé (409 PRICES_CHANGED si l'empreinte diffère) ; lignes écrites = changées, sans prix sous le coût ni grand
     * écart sauf accord explicite ; tout ou rien. Conseil d'origine marqué fait (applied.pricePercent) si demandé.
     *
     * @return array<string, mixed>
     */
    public function apply(array $body, ?string $user): array
    {
        $preview = $this->preview($body);
        $hash = $body['previewHash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        if (!hash_equals($preview['previewHash'], $hash)) {
            throw new GestionConflictException('PRICES_CHANGED', 'Des prix ont changé depuis l\'aperçu : voici l\'aperçu à jour.', 409, ['preview' => $preview]);
        }
        $allowBelow = ($body['allowBelowCost'] ?? false) === true;
        $allowBig = ($body['allowBigChange'] ?? false) === true;
        $write = array_values(array_filter($preview['rows'], fn ($r) => $r['changed']
            && ($allowBelow || !in_array('BELOW_COST', $r['alerts'], true)) && ($allowBig || !in_array('BIG_CHANGE', $r['alerts'], true))));
        if ($write === []) {
            throw new GestionConflictException('NOTHING_TO_APPLY', 'Aucun prix à écrire (inchangés, sous le coût ou grand écart sans accord).', 422, ['preview' => $preview]);
        }
        $in = $this->input($body);
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            foreach ($write as $r) {
                $this->writePrice($r['level'], $r['level'] === 'product' ? $r['productId'] : (int) $r['variantId'], $r['newPrice']);
            }
            $batchId = (int) $conn->fetchOne(
                'INSERT INTO gestion_price_batch (body, mode, value, rounding, advice_id, changed, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) RETURNING id',
                [json_encode(array_diff_key($body, ['previewHash' => 1])), $in['mode'], $in['value'], $in['rounding'], $in['adviceId'], count($write), $user]
            );
            foreach ($write as $r) {
                $conn->executeStatement('INSERT INTO gestion_price_change (batch_id, product_id, variant_id, level, old_price, new_price) VALUES (?, ?, ?, ?, ?, ?)',
                    [$batchId, $r['productId'], $r['variantId'], $r['level'], $r['oldPrice'], $r['newPrice']]);
            }
            $advice = null;
            if ($in['adviceId'] !== null && ($body['markAdviceDone'] ?? true) !== false
                && $conn->fetchOne('SELECT status FROM gestion_advice WHERE id = ?', [$in['adviceId']]) === 'new') {
                $advice = $this->advices->setStatus($in['adviceId'], 'done', $user, null, $this->advices->siteLanguage(),
                    ['pricePercent' => $this->appliedPercent($write), 'productIds' => array_values(array_unique(array_column($write, 'productId')))]);
                $conn->executeStatement('UPDATE gestion_price_batch SET advice_marked = TRUE WHERE id = ?', [$batchId]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->emProvider->getEntityManager()->clear();
            throw $e instanceof LandingContentException ? new GestionConflictException('PRICE_REFUSED', $e->getMessage(), 422) : $e;
        }

        return ['batchId' => $batchId, 'changed' => count($write), 'rows' => $write, 'undoUntil' => (new \DateTimeImmutable('+' . self::UNDO_HOURS . ' hours'))->format(\DateTimeInterface::ATOM), 'advice' => $advice];
    }

    /**
     * Annulation dans les 24 h : remet les anciens prix par le même chemin ; tout ou rien (409 PRICE_EDITED_SINCE si un prix a été retouché),
     * ou seulement les lignes intactes (`onlyUntouched`). Le conseil marqué fait par ce lot repasse en new.
     *
     * @return array<string, mixed>
     */
    public function undo(mixed $batchId, bool $onlyUntouched, ?string $user): array
    {
        if (!is_int($batchId)) {
            throw new GestionValidationException(['batchId' => 'REQUIRED']);
        }
        $conn = $this->conn();
        $batch = $conn->fetchAssociative("SELECT *, (created_at < NOW() - INTERVAL '" . self::UNDO_HOURS . " hours') AS expired FROM gestion_price_batch WHERE id = ?", [$batchId]);
        if ($batch === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de prix introuvable.', 404, ['batchId' => $batchId]);
        }
        if ($batch['undone_at'] !== null) {
            throw new GestionConflictException('ALREADY_UNDONE', 'Ce lot est déjà annulé.', 409, ['batchId' => $batchId]);
        }
        if ($batch['expired']) {
            throw new GestionConflictException('UNDO_EXPIRED', sprintf('Annulation possible pendant %d h seulement.', self::UNDO_HOURS), 409, ['batchId' => $batchId]);
        }
        $changes = $conn->fetchAllAssociative('SELECT * FROM gestion_price_change WHERE batch_id = ? ORDER BY id', [$batchId]);
        $intact = [];
        $touched = [];
        foreach ($changes as $c) {
            $current = $this->currentPrice((string) $c['level'], (int) $c['product_id'], $c['variant_id'] === null ? null : (int) $c['variant_id']);
            $row = ['productId' => (int) $c['product_id'], 'variantId' => $c['variant_id'] === null ? null : (int) $c['variant_id'], 'level' => $c['level'], 'oldPrice' => (int) $c['old_price'], 'newPrice' => (int) $c['new_price'], 'currentPrice' => $current];
            $current === (int) $c['new_price'] ? $intact[] = $row : $touched[] = $row;
        }
        if ($touched !== [] && !$onlyUntouched) {
            throw new GestionConflictException('PRICE_EDITED_SINCE', 'Des prix ont été retouchés depuis : rien n\'est annulé (onlyUntouched pour annuler le reste).', 409, ['rows' => $touched]);
        }
        $conn->beginTransaction();
        try {
            foreach ($intact as $r) {
                $this->writePrice($r['level'], $r['level'] === 'product' ? $r['productId'] : (int) $r['variantId'], $r['oldPrice']);
            }
            $conn->executeStatement('UPDATE gestion_price_batch SET undone_at = NOW(), undone_by = ?, undo_skipped = ? WHERE id = ?', [$user, $touched === [] ? null : json_encode($touched), $batchId]);
            if ($batch['advice_marked'] && $batch['advice_id'] !== null) {
                $conn->executeStatement("UPDATE gestion_advice SET status = 'new', status_by = NULL, status_note = NULL, done_at = NULL, applied = NULL, applied_on = NULL, updated_at = NOW() WHERE id = ? AND status = 'done'", [$batch['advice_id']]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->emProvider->getEntityManager()->clear();
            throw $e;
        }

        return ['batchId' => $batchId, 'restored' => $intact, 'skipped' => $touched, 'adviceRestored' => (bool) $batch['advice_marked']];
    }

    /** @return list<array<string, mixed>> */
    public function batches(int $limit): array
    {
        $rows = $this->conn()->fetchAllAssociative("SELECT *, (created_at < NOW() - INTERVAL '" . self::UNDO_HOURS . " hours') AS expired FROM gestion_price_batch ORDER BY id DESC LIMIT " . max(1, min(100, $limit)));

        return array_map(fn ($b) => $this->batchRow($b), $rows);
    }

    public function batch(int $id): array
    {
        $b = $this->conn()->fetchAssociative("SELECT *, (created_at < NOW() - INTERVAL '" . self::UNDO_HOURS . " hours') AS expired FROM gestion_price_batch WHERE id = ?", [$id]);
        if ($b === false) {
            throw new GestionConflictException('BATCH_NOT_FOUND', 'Lot de prix introuvable.', 404, ['batchId' => $id]);
        }
        $changes = $this->conn()->fetchAllAssociative('SELECT c.*, p.name, sz.name AS size, co.name AS color FROM gestion_price_change c LEFT JOIN product p ON p.id = c.product_id'
            . ' LEFT JOIN product_variant pv ON pv.id = c.variant_id LEFT JOIN size sz ON sz.id = pv.size_id LEFT JOIN color co ON co.id = pv.color_id WHERE c.batch_id = ? ORDER BY c.id', [$id]);

        return $this->batchRow($b) + ['rows' => array_map(fn ($c) => ['productId' => (int) $c['product_id'], 'variantId' => $c['variant_id'] === null ? null : (int) $c['variant_id'], 'level' => $c['level'],
            'name' => $c['name'], 'variantLabel' => $c['level'] === 'variant' ? self::variantLabel($c['size'], $c['color']) : null, 'oldPrice' => (int) $c['old_price'], 'newPrice' => (int) $c['new_price']], $changes)];
    }

    /** Libellé d'une variante : « XL · Noir » (taille puis couleur, celles qui existent), null sans l'une ni l'autre */
    public static function variantLabel(?string $size, ?string $color): ?string
    {
        $parts = array_values(array_filter([trim((string) $size), trim((string) $color)], fn ($x) => $x !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Arrondi en cents, appliqué en dernier : ends_99 = le plus petit X,99 ≥ prix ; up_50 = 0,50 supérieur ; up_100 = dollar supérieur ; none = au cent.
     */
    public static function round(float $cents, string $rounding): int
    {
        $c = (int) round($cents);

        return match ($rounding) {
            'ends_99' => intdiv($c, 100) * 100 + 99 >= $c ? intdiv($c, 100) * 100 + 99 : intdiv($c, 100) * 100 + 199,
            'up_50' => (int) (ceil($c / 50) * 50),
            'up_100' => (int) (ceil($c / 100) * 100),
            default => $c,
        };
    }

    // ----- interne -----

    /** Écriture d'un prix par le chemin d'un article (mêmes règles, cache, marque d'exemple, journal d'audit que PATCH /api/products|product-variants/{id}) */
    private function writePrice(string $level, int $id, int $cents): void
    {
        $this->writeFields($level === 'product' ? 'products' : 'product-variants', $id, ['price' => $cents]);
    }

    /**
     * Écriture de champs d'un article ou d'une variante par le chemin de l'éditeur (P4, promotions P5) : validation, hooks, flush, invalidation du
     * cache, marque d'exemple, journal d'audit ; unité de travail vidée ensuite.
     *
     * @param array<string, mixed> $fields
     */
    public function writeFields(string $resource, int $id, array $fields): void
    {
        $entity = $this->editor->find($resource, $id);
        if ($entity === null) {
            throw new LandingContentException(404, 'Article introuvable : ' . $id);
        }
        ['values' => $values, 'errors' => $errors] = $this->editor->validate($resource, (object) $fields);
        if ($errors) {
            throw new LandingContentException(422, 'Prix refusé : ' . $errors[0]['message'], $errors);
        }
        $before = $this->editor->snapshot($resource, $entity, array_keys($values), 'fr');
        $this->editor->apply($resource, $entity, $values, 'fr');
        $after = $this->editor->snapshot($resource, $entity, array_keys($values), 'fr');
        $this->audit->record($resource, $id, 'update', $before, $after, array_keys($values), 'fr');
        // Chaque écriture est déjà enregistrée (flush) : on libère l'unité de travail, sinon chaque flush suivant repasse sur tous les articles
        // déjà chargés (500 lignes : 19 s au lieu de quelques secondes) ; l'invalidation du cache, collectée par le souscripteur, n'en dépend pas
        $this->emProvider->getEntityManager()->clear();
    }

    private function currentPrice(string $level, int $productId, ?int $variantId): ?int
    {
        $v = $level === 'product'
            ? $this->conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$productId])
            : $this->conn()->fetchOne('SELECT price FROM product_variant WHERE id = ?', [$variantId]);

        return $v === false || $v === null ? null : (int) round((float) $v);
    }

    /** @return array{mode: string, value: float, rounding: string, includeVariants: bool, productIds: ?list<int>, commandeId: ?int, adviceId: ?int} */
    private function input(array $body): array
    {
        $errors = [];
        $mode = $body['mode'] ?? null;
        if (!in_array($mode, self::MODES, true)) {
            $errors['mode'] = 'INVALID_MODE';
        }
        $value = $body['value'] ?? null;
        if (!is_int($value) && !(is_float($value) && is_finite($value))) {
            $errors['value'] = 'MUST_BE_NUMBER';
        } elseif ($mode === 'percent' && ($value < self::PERCENT_RANGE[0] || $value > self::PERCENT_RANGE[1])) {
            $errors['value'] = 'OUT_OF_RANGE';
        } elseif ($mode === 'coefficient' && ($value < self::COEFFICIENT_RANGE[0] || $value > self::COEFFICIENT_RANGE[1])) {
            $errors['value'] = 'OUT_OF_RANGE';
        }
        $rounding = $body['rounding'] ?? 'none';
        if (!in_array($rounding, self::ROUNDINGS, true)) {
            $errors['rounding'] = 'INVALID_ROUNDING';
        }
        $scopes = array_filter(['productIds', 'commandeId', 'adviceId'], fn ($k) => ($body[$k] ?? null) !== null);
        if ($scopes === []) {
            $errors['productIds'] = 'SCOPE_REQUIRED';
        } elseif (count($scopes) > 1) {
            $errors['productIds'] = 'SCOPE_AMBIGUOUS';
        }
        $productIds = null;
        if (isset($body['productIds'])) {
            $list = $body['productIds'];
            if (!is_array($list) || !array_is_list($list) || $list === []) {
                $errors['productIds'] = 'REQUIRED';
            } elseif (count($list) > self::MAX_PRODUCTS) {
                $errors['productIds'] = 'TOO_MANY';
            } else {
                foreach ($list as $i => $id) {
                    if (!is_int($id) || $id <= 0) {
                        $errors["productIds[$i]"] = 'INVALID_ID';
                    }
                }
                $productIds = array_values(array_unique(array_filter($list, 'is_int')));
            }
        }
        foreach (['commandeId', 'adviceId'] as $k) {
            if (isset($body[$k]) && (!is_int($body[$k]) || $body[$k] <= 0)) {
                $errors[$k] = 'INVALID_ID';
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Prix invalides');
        }

        return ['mode' => $mode, 'value' => (float) $value, 'rounding' => $rounding, 'includeVariants' => ($body['includeVariants'] ?? true) !== false,
            'productIds' => $productIds, 'commandeId' => $body['commandeId'] ?? null, 'adviceId' => $body['adviceId'] ?? null];
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<int, float>} articles du périmètre, coûts de la commande par article */
    public function scope(array $in): array
    {
        $conn = $this->conn();
        $lineCosts = [];
        if ($in['commandeId'] !== null) {
            if ($conn->fetchOne('SELECT 1 FROM commande WHERE id = ?', [$in['commandeId']]) === false) {
                throw new GestionValidationException(['commandeId' => 'COMMANDE_NOT_FOUND']);
            }
            foreach ($conn->fetchAllAssociative('SELECT l.product_id, SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(c.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0) AS cost'
                . ' FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE l.commande_id = ? AND l.unit_cost_cents IS NOT NULL GROUP BY l.product_id', [$in['commandeId']]) as $r) {
                $lineCosts[(int) $r['product_id']] = (float) $r['cost'];
            }
            $ids = array_map('intval', $conn->fetchFirstColumn('SELECT DISTINCT product_id FROM commande_line WHERE commande_id = ? ORDER BY 1', [$in['commandeId']]));
        } elseif ($in['adviceId'] !== null) {
            $subject = $conn->fetchOne('SELECT subject FROM gestion_advice WHERE id = ?', [$in['adviceId']]);
            if ($subject === false) {
                throw new GestionValidationException(['adviceId' => 'ADVICE_NOT_FOUND']);
            }
            $ids = array_values(array_map('intval', (json_decode((string) $subject, true) ?: [])['productIds'] ?? []));
            if ($ids === []) {
                throw new GestionValidationException(['adviceId' => 'ADVICE_WITHOUT_PRODUCTS']);
            }
        } else {
            $ids = $in['productIds'];
        }
        if (count($ids) > self::MAX_PRODUCTS) {
            throw new GestionValidationException(['productIds' => 'TOO_MANY']);
        }
        $rows = $ids === [] ? [] : $conn->fetchAllAssociative(
            'SELECT p.id, p.name, p.price, p.special_price, p.special_price_from, p.special_price_to, p.product_type, COALESCE(p.rental_enabled, false) AS rental, COALESCE(p.quantity, 0) AS quantity, ' . self::COST . ' AS cost'
            . ' FROM product p WHERE p.id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $found = array_column($rows, null, 'id');
        if ($in['productIds'] !== null) {
            $errors = [];
            foreach ($in['productIds'] as $i => $id) {
                if (!isset($found[$id])) {
                    $errors["productIds[$i]"] = 'PRODUCT_NOT_FOUND';
                }
            }
            if ($errors !== []) {
                throw new GestionValidationException($errors, 'Articles introuvables');
            }
        }
        $variants = [];
        foreach ($ids === [] ? [] : $conn->fetchAllAssociative(
            'SELECT pv.id, pv.product_id, pv.price, COALESCE(pv.stock_quantity, 0) AS stock, sz.name AS size, co.name AS color FROM product_variant pv'
            . ' LEFT JOIN size sz ON sz.id = pv.size_id LEFT JOIN color co ON co.id = pv.color_id WHERE pv.product_id IN (?) ORDER BY pv.id', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        ) as $v) {
            $variants[(int) $v['product_id']][] = $v;
        }
        $products = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $products[] = $found[$id] + ['variants' => $variants[$id] ?? []];
            }
        }

        return [$products, $lineCosts];
    }

    /** @return list<array<string, mixed>> ligne « article » (prix du produit, hérité par les variantes sans prix) et lignes des variantes à prix propre */
    private function rowsFor(array $p, array $in, ?float $lineCost, \DateTimeImmutable $today): array
    {
        $cost = $lineCost ?? ($p['cost'] === null ? null : (float) $p['cost']);
        $saleItem = $p['product_type'] === 'standard' && !in_array($p['rental'], [true, 't', 1, '1'], true);
        $todayKey = $today->format('Y-m-d');
        $special = $p['special_price'] !== null && (float) $p['special_price'] > 0
            && ($p['special_price_from'] === null || substr((string) $p['special_price_from'], 0, 10) <= $todayKey)
            && ($p['special_price_to'] === null || substr((string) $p['special_price_to'], 0, 10) >= $todayKey);
        $inheriting = array_values(array_filter($p['variants'], fn ($v) => $v['price'] === null));
        $own = array_values(array_filter($p['variants'], fn ($v) => $v['price'] !== null));
        $rows = [];
        $rows[] = $this->row($p, null, 'product', (string) $p['name'], (int) round((float) ($p['price'] ?? 0)), $cost, $p['variants'] === [] ? (int) $p['quantity'] : array_sum(array_map(fn ($v) => (int) $v['stock'], $inheriting)),
            $in, $saleItem, $special) + ['inheritingVariants' => count($inheriting)];
        if ($in['includeVariants']) {
            foreach ($own as $v) {
                $label = trim((string) $p['name'] . ' ' . trim(($v['size'] ?? '') . ' ' . ($v['color'] ?? '')));
                $rows[] = $this->row($p, (int) $v['id'], 'variant', $label, (int) $v['price'], $cost, (int) $v['stock'], $in, $saleItem, $special)
                    + ['variantLabel' => self::variantLabel($v['size'] ?? null, $v['color'] ?? null)];
            }
        }

        return $rows;
    }

    private function row(array $p, ?int $variantId, string $level, string $name, int $old, ?float $cost, int $stock, array $in, bool $saleItem, bool $special): array
    {
        $alerts = [];
        $new = $old;
        if (!$saleItem) {
            $alerts[] = 'NOT_A_SALE_ITEM';
        } elseif ($in['mode'] === 'percent') {
            if ($old <= 0) {
                $alerts[] = 'PRICE_MISSING';
            } else {
                $new = self::round($old * (1 + $in['value'] / 100), $in['rounding']);
            }
        } elseif ($cost === null) {
            $alerts[] = 'COST_UNKNOWN';
        } else {
            $new = self::round($cost * $in['value'], $in['rounding']); // Product::updatePrice() : prix affiché = coût × coefficient
        }
        $blocked = array_intersect($alerts, ['NOT_A_SALE_ITEM', 'PRICE_MISSING', 'COST_UNKNOWN']) !== [];
        if (!$blocked && $new === $old) {
            $alerts[] = 'NO_CHANGE';
        }
        if (!$blocked && $cost !== null && $new < $cost) {
            $alerts[] = 'BELOW_COST';
        }
        if (!$blocked && $old > 0 && abs($new - $old) / $old > self::BIG_CHANGE) {
            $alerts[] = 'BIG_CHANGE';
        }
        if ($special) {
            $alerts[] = 'SPECIAL_PRICE_ACTIVE';
        }
        $c = $cost === null ? null : (int) round($cost);
        $rate = fn (int $price) => $c === null || $price <= 0 ? null : round(($price - $c) / $price * 100, 2);

        return [
            'productId' => (int) $p['id'], 'variantId' => $variantId, 'level' => $level, 'name' => $name, 'oldPrice' => $old, 'newPrice' => $new, 'cost' => $c,
            'marginBefore' => $c === null ? null : $old - $c, 'marginAfter' => $c === null ? null : $new - $c, 'marginRateBefore' => $rate($old), 'marginRateAfter' => $rate($new),
            'stock' => $stock, 'changed' => !$blocked && $new !== $old, 'alerts' => $alerts,
        ];
    }

    /** Pourcentage appliqué pour le conseil : en mode coefficient, écart moyen pondéré par les anciens prix */
    private function appliedPercent(array $write): float
    {
        $old = array_sum(array_column($write, 'oldPrice'));
        $pct = $old > 0 ? (array_sum(array_column($write, 'newPrice')) - $old) / $old * 100 : 0.0;

        return round(max(self::PERCENT_RANGE[0], min(self::PERCENT_RANGE[1], $pct)), 1);
    }

    private static function hash(array $in, array $rows): string
    {
        return hash('sha256', (string) json_encode([$in, array_map(fn ($r) => [$r['productId'], $r['variantId'], $r['oldPrice'], $r['newPrice'], $r['alerts']], $rows)]));
    }

    private function batchRow(array $b): array
    {
        $created = new \DateTimeImmutable((string) $b['created_at']);
        $expired = (bool) $b['expired'];

        return [
            'id' => (int) $b['id'], 'mode' => $b['mode'], 'value' => (float) $b['value'], 'rounding' => $b['rounding'], 'adviceId' => $b['advice_id'] === null ? null : (int) $b['advice_id'],
            'changed' => (int) $b['changed'], 'createdBy' => $b['created_by'], 'createdAt' => $created->format(\DateTimeInterface::ATOM),
            'undoneAt' => $b['undone_at'] === null ? null : (new \DateTimeImmutable((string) $b['undone_at']))->format(\DateTimeInterface::ATOM), 'undoneBy' => $b['undone_by'],
            'undoable' => $b['undone_at'] === null && !$expired, 'undoBlockedBy' => $b['undone_at'] !== null ? 'ALREADY_UNDONE' : ($expired ? 'UNDO_EXPIRED' : null),
            'undoUntil' => $created->modify('+' . self::UNDO_HOURS . ' hours')->format(\DateTimeInterface::ATOM),
            'undoSkipped' => $b['undo_skipped'] === null ? null : json_decode((string) $b['undo_skipped'], true),
        ];
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
