<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Entity\Collections;
use App\Gestion\Analysis\CoefficientSuggestion;
use App\Gestion\Pricing\PriceRounding;
use App\Gestion\Services\GestionPriceService;
use App\Gestion\Services\GestionPriceSettingsService;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `price.set` (lot IA-ACT-5, 13/11/2026, `docs/conversation-gestion.md` § 13) : changer le prix de vente d'un ensemble d'articles, mené
 * par le fil. Tout passe par `GestionPriceService` (lot P4) : son aperçu, son application par le chemin d'écriture d'un article, son lot
 * et son annulation. L'action n'écrit rien elle-même.
 *
 * Portée (une seule) : une liste d'articles, une commande, les articles achetés dans une période ou dans une collection d'achats, une
 * catégorie de la boutique. Mode : coefficient sur le coût, pourcentage sur le prix actuel, ou prix saisi (liste d'articles seulement,
 * jamais arrondi). Arrondi : celui du site par défaut.
 *
 * Garde-fous du lot P4 : une ligne qui passerait SOUS SON COÛT ou qui bougerait de PLUS DE 50 % est tenue à l'écart tant que le gérant
 * n'a pas répondu « appliquer quand même » à la question qui les liste. Un article sans coût (mode coefficient), sans prix (mode
 * pourcentage) ou en location est exclu et nommé.
 */
class PriceSetAction extends AbstractGestionAction
{
    /** Lignes de l'aperçu rendues au plus (les totaux portent sur toutes) */
    public const ROWS = 200;
    /** Articles exclus ou tenus à l'écart nommés au plus */
    public const LISTED = 50;
    /** Modes dans l'ordre où le gérant les lit : le coefficient d'abord, c'est le geste courant de la gestion */
    private const MODES = ['coefficient', 'percent', 'fixed'];
    private const SCOPE_KEYS = ['productIds', 'commandeId', 'periodId', 'collectionId', 'categoryId'];
    private const BLOCKING = ['NOT_A_SALE_ITEM', 'COST_UNKNOWN', 'PRICE_MISSING'];
    private const RISKY = ['BELOW_COST', 'BIG_CHANGE'];

    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly GestionPriceService $prices, private readonly GestionPriceSettingsService $settings, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'price.set';
    }

    public function build(array $answers, string $locale): array
    {
        $scope = is_array($answers['scope'] ?? null) ? $answers['scope'] : null;
        $mode = in_array($answers['mode'] ?? null, GestionPriceService::MODES, true) ? $answers['mode'] : null;
        $list = $scope !== null && isset($scope['productIds']);
        $t = fn (string $id, array $params = []) => $this->tr("conversation.actions.price_set.$id", $locale, $params);

        // Portées proposées : les périodes commencées les plus récentes, les collections d'achats, les catégories de la boutique.
        // Une liste d'articles ou une commande se donnent à l'ouverture du fil (`params.scope`).
        $options = [];
        $today = $this->calendar->today()->format('Y-m-d');
        foreach ($this->em()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD], ['startDateCollection' => 'DESC'], 12) as $period) {
            if (!$period->isDel() && $period->getStartDateCollection()?->format('Y-m-d') <= $today && count($options) < 6) {
                $options[] = ['value' => ['periodId' => (int) $period->getId()], 'label' => $t('scope.period', ['%name%' => PeriodCalendar::displayName($period, $locale)]), 'group' => 'period'];
            }
        }
        foreach ($this->conn()->fetchAllAssociative("SELECT id, nom_collection FROM collections WHERE kind = 'manual' AND del = false ORDER BY id DESC LIMIT 10") as $c) {
            $options[] = ['value' => ['collectionId' => (int) $c['id']], 'label' => $t('scope.collection', ['%name%' => (string) $c['nom_collection']]), 'group' => 'collection'];
        }
        foreach ($this->conn()->fetchAllAssociative('SELECT id, name FROM categories ORDER BY LOWER(name), id LIMIT 50') as $c) {
            $options[] = ['value' => ['categoryId' => (int) $c['id']], 'label' => $t('scope.category', ['%name%' => (string) $c['name']]), 'group' => 'category'];
        }
        $questions = [$this->question('scope', true, ['type' => 'choice'], $options, $scope, $locale)];

        $modes = array_values(array_filter(self::MODES, fn (string $m) => $m !== 'fixed' || $list)); // un prix saisi : pour une liste d'articles seulement
        $questions[] = $this->question('mode', true, ['type' => 'choice'], array_map(fn (string $m) => ['value' => $m, 'label' => $t("mode.options.$m")], $modes), $mode, $locale);

        $site = $this->settings->defaultCoefficient();
        [$input, $valueOptions] = match ($mode) {
            'coefficient' => [['type' => 'number', 'min' => GestionPriceService::COEFFICIENT_RANGE[0], 'max' => GestionPriceService::COEFFICIENT_RANGE[1]],
                [['value' => $site, 'label' => $this->tr('prices.coefficient.site', $locale, ['%coefficient%' => CoefficientSuggestion::number($site, $locale)])]]],
            'percent' => [['type' => 'number', 'min' => GestionPriceService::PERCENT_RANGE[0], 'max' => GestionPriceService::PERCENT_RANGE[1], 'unit' => 'percent'], []],
            'fixed' => [['type' => 'money', 'currency' => $this->site()], []],
            default => [['type' => 'number'], []],
        };
        $questions[] = $this->question('value', true, $input, $valueOptions, $answers['value'] ?? null, $locale, [], false);
        $questions[count($questions) - 1]['text'] = $t('value.text_' . ($mode ?? 'any'));

        if ($mode !== null && $mode !== 'fixed') {
            // Arrondi : celui du site, proposé ; le gérant peut en choisir un autre pour ce geste
            $rounding = in_array($answers['rounding'] ?? null, PriceRounding::MODES, true) ? $answers['rounding'] : null;
            $questions[] = $this->question('rounding', true, ['type' => 'choice'], array_map(fn (string $m) => ['value' => $m, 'label' => $t("rounding.options.$m")], PriceRounding::MODES),
                $rounding ?? $this->settings->rounding(), $locale, [], $rounding === null);
        }

        $issues = [];
        if ($scope !== null && $mode !== null && isset($answers['value'])) {
            try {
                $rows = $this->prices->preview($this->body($answers))['rows'];
                $risky = array_values(array_filter($rows, fn (array $r) => $r['changed'] && array_intersect($r['alerts'], self::RISKY) !== []));
                if ($risky !== []) {
                    // UNE question, qui liste les lignes à risque : les tenir à l'écart (proposé) ou appliquer quand même
                    $force = in_array($answers['force'] ?? null, ['exclude', 'apply'], true) ? $answers['force'] : null;
                    $question = $this->question('force', true, ['type' => 'choice'], [['value' => 'exclude', 'label' => $t('force.options.exclude')], ['value' => 'apply', 'label' => $t('force.options.apply')]],
                        $force ?? 'exclude', $locale, ['%count%' => (string) count($risky)], $force === null);
                    $questions[] = $question + ['count' => count($risky), 'rows' => array_map(fn (array $r) => self::line($r), array_slice($risky, 0, self::LISTED))];
                }
                $force = ($answers['force'] ?? null) === 'apply';
                if (array_filter($rows, fn (array $r) => self::written($r, $force)) === []) {
                    $issues[] = ['path' => 'scope', 'code' => 'NOTHING_TO_APPLY']; // tous inchangés, exclus ou tenus à l'écart
                }
            } catch (GestionValidationException $e) {
                foreach ($e->fields as $path => $code) {
                    $issues[] = ['path' => in_array($path, ['mode', 'value', 'rounding'], true) ? $path : 'scope', 'code' => $code];
                }
            }
        }

        return ['questions' => $questions, 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'scope':
                if (!is_array($value) || count($value) !== 1 || !in_array((string) array_key_first($value), self::SCOPE_KEYS, true)) {
                    $this->fail($index, 'INVALID_VALUE');
                }
                $key = (string) array_key_first($value);
                $scope = [$key => $key === 'productIds' ? (is_array($value[$key]) ? array_values($value[$key]) : $value[$key]) : $value[$key]];
                try {
                    // le contrôle de la portée est celui du service des prix : existence, taille, identifiants
                    [$products] = $this->prices->scope($this->checked($scope, $index) + ['productIds' => null, 'commandeId' => null, 'adviceId' => null]);
                } catch (GestionValidationException $e) {
                    $this->fail($index, (string) (array_values($e->fields)[0] ?? 'INVALID_VALUE'));
                }
                if ($products === []) {
                    $this->fail($index, 'SCOPE_EMPTY');
                }

                return $scope;
            case 'mode':
                if (!in_array($value, GestionPriceService::MODES, true)) {
                    $this->fail($index, 'INVALID_MODE');
                }
                if ($value === 'fixed' && is_array($answers['scope'] ?? null) && !isset($answers['scope']['productIds'])) {
                    $this->fail($index, 'FIXED_NEEDS_LIST');
                }

                return $value;
            case 'value':
                if (!is_int($value) && !(is_float($value) && is_finite($value))) {
                    $this->fail($index, 'MUST_BE_NUMBER');
                }
                $mode = $answers['mode'] ?? null;
                if ($mode === 'fixed') {
                    return is_int($value) && $value > 0 && $value <= GestionPriceService::FIXED_MAX ? $value : $this->fail($index, is_int($value) && $value > 0 ? 'OUT_OF_RANGE' : 'INVALID_AMOUNT');
                }
                $range = $mode === 'coefficient' ? GestionPriceService::COEFFICIENT_RANGE : ($mode === 'percent' ? GestionPriceService::PERCENT_RANGE : null);
                if ($range !== null && ($value < $range[0] || $value > $range[1])) {
                    $this->fail($index, 'OUT_OF_RANGE');
                }

                return is_int($value) ? $value : round($value, 2);
            case 'rounding':
                return in_array($value, PriceRounding::MODES, true) ? $value : $this->fail($index, 'INVALID_ROUNDING');
            default: // force
                return in_array($value, ['exclude', 'apply'], true) ? $value : $this->fail($index, 'INVALID_VALUE');
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $body = $this->body($answers);
        $p = $this->prices->preview($body);
        $force = ($answers['force'] ?? null) === 'apply';
        $written = $excluded = $held = [];
        $unchanged = $promotions = $units = 0;
        $before = $after = $valueBefore = $valueAfter = 0;
        foreach ($p['rows'] as $r) {
            $writes = self::written($r, $force);
            if ($writes) {
                $written[] = $r;
                $promotions += in_array('SPECIAL_PRICE_ACTIVE', $r['alerts'], true) ? 1 : 0;
            } elseif (($reason = array_values(array_intersect($r['alerts'], self::BLOCKING))[0] ?? null) !== null) {
                $excluded[] = ['productId' => $r['productId'], 'variantId' => $r['variantId'], 'name' => $r['name'], 'reason' => $reason];
            } elseif ($r['changed']) {
                $held[] = self::line($r);
            } else {
                ++$unchanged;
            }
            // Marge potentielle du stock : stock × (prix − coût), sur les lignes au coût connu ; « après » ne change que pour les lignes écrites
            if ($r['cost'] !== null && !in_array('NOT_A_SALE_ITEM', $r['alerts'], true) && $r['stock'] > 0) {
                $new = $writes ? $r['newPrice'] : $r['oldPrice'];
                $units += $r['stock'];
                $before += $r['stock'] * ($r['oldPrice'] - $r['cost']);
                $after += $r['stock'] * ($new - $r['cost']);
                $valueBefore += $r['stock'] * $r['oldPrice'];
                $valueAfter += $r['stock'] * $new;
            }
        }
        $rate = fn (int $margin, int $value): ?float => $value > 0 ? round($margin / $value * 100, 2) : null;
        $t = fn (string $id, array $params = []) => $this->tr("conversation.actions.price_set.$id", $locale, $params);
        $notice = $t('notice.visible') . ($promotions > 0 ? ' ' . $t('notice.promotions', ['%count%' => (string) $promotions]) : '') . ($p['pricesIncludeTax'] ? ' ' . $t('notice.tax') : '');

        return [
            'scope' => $this->scopeLabel($answers['scope'], $locale), 'mode' => $body['mode'], 'value' => $body['value'], 'rounding' => $body['mode'] === 'fixed' ? null : $body['rounding'],
            'currency' => $this->site(), 'pricesIncludeTax' => $p['pricesIncludeTax'],
            'summary' => ['products' => $p['summary']['products'], 'rows' => $p['summary']['rows'], 'written' => count($written), 'unchanged' => $unchanged, 'excluded' => count($excluded), 'heldBack' => count($held), 'promotions' => $promotions],
            'stockMargin' => ['units' => $units, 'before' => ['amount' => $before, 'rate' => $rate($before, $valueBefore)], 'after' => ['amount' => $after, 'rate' => $rate($after, $valueAfter)]],
            'lines' => array_map(fn (array $r) => self::line($r), array_slice($written, 0, self::ROWS)),
            'more' => count($written) > self::ROWS ? ['count' => count($written) - self::ROWS, 'total' => count($written)] : null,
            'excluded' => array_slice($excluded, 0, self::LISTED), 'heldBack' => array_slice($held, 0, self::LISTED),
            'notice' => $notice,
            'pricesHash' => $p['previewHash'], // toutes les lignes, au-delà des 200 rendues : un prix qui bouge change l'empreinte du fil
        ];
    }

    public function rows(array $preview, string $locale): array
    {
        $t = fn (string $id, array $params = []) => $this->tr("conversation.actions.price_set.$id", $locale, $params);
        $money = ['currency' => $preview['currency']];
        $number = fn (float $v) => CoefficientSuggestion::number($v, $locale);
        $what = match ($preview['mode']) {
            'coefficient' => $t('rows.coefficient', ['%value%' => $number((float) $preview['value'])]),
            'percent' => $t('rows.percent', ['%value%' => ($preview['value'] > 0 ? '+' : '') . $number((float) $preview['value'])]),
            default => $t('rows.fixed', ['%value%' => self::amount((int) $preview['value'], $preview['currency'], $locale)]),
        };
        $margin = $preview['stockMargin'];
        $percent = fn (?float $rate) => $rate === null ? '—' : number_format($rate, 2, $locale === 'en' ? '.' : ',', '') . ' %';

        return array_values(array_filter([
            $this->row('scope', $locale, $preview['scope']['label'], 'text'),
            $this->row('mode', $locale, $what, 'text'),
            $preview['rounding'] === null ? null : $this->row('rounding', $locale, $t('rounding.options.' . $preview['rounding']), 'text'),
            $this->row('written', $locale, $preview['summary']['written'], 'number'),
            $margin['units'] === 0 ? null : $this->row('stockMargin', $locale, $margin['after']['amount'], 'money', $money + ['before' => $margin['before']['amount']]),
            $margin['units'] === 0 ? null : $this->row('stockMarginRate', $locale, $percent($margin['after']['rate']), 'text', ['before' => $percent($margin['before']['rate'])]),
            $preview['summary']['heldBack'] === 0 ? null : $this->row('heldBack', $locale, $preview['summary']['heldBack'], 'number'),
            $preview['summary']['excluded'] === 0 ? null : $this->row('excluded', $locale, $preview['summary']['excluded'], 'number'),
            $this->row('notice', $locale, $preview['notice'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $body = $this->body($answers);
        $force = ($answers['force'] ?? null) === 'apply';
        // l'application recalcule l'aperçu et écrit dans une transaction, par le chemin d'un article ; son lot porte l'annulation
        $done = $this->prices->apply($body + ['previewHash' => $this->prices->preview($body)['previewHash'], 'allowBelowCost' => $force, 'allowBigChange' => $force, 'markAdviceDone' => false], $user);

        return ['result' => ['batchId' => $done['batchId'], 'changed' => $done['changed'], 'undoUntil' => $done['undoUntil']], 'undo' => ['batchId' => $done['batchId']]];
    }

    public function blockers(array $undo): array
    {
        $state = $this->prices->undoState((int) $undo['batchId']);
        if (!$state['found']) {
            return [['code' => 'PRICE_CHANGED', 'message' => 'Le lot de prix de cette action n\'existe plus.']];
        }
        if ($state['undone']) {
            return [['code' => 'ALREADY_UNDONE', 'message' => 'Ces prix ont déjà été remis depuis l\'écran des prix.']];
        }
        if ($state['touched'] === []) {
            return [];
        }
        $names = array_values(array_unique(array_map(fn (array $r) => (string) ($r['name'] ?? '#' . $r['productId']), $state['touched'])));

        return [['code' => 'PRICE_CHANGED', 'message' => sprintf(count($names) === 1 ? 'Le prix d\'un article a été modifié depuis : %2$s%3$s.' : 'Le prix de %1$d articles a été modifié depuis : %2$s%3$s.', count($names), implode(', ', array_slice($names, 0, 5)), count($names) > 5 ? '…' : ''),
            'products' => array_slice($state['touched'], 0, self::LISTED)]];
    }

    public function undo(array $undo): array
    {
        $done = $this->prices->undo((int) $undo['batchId'], false, null); // même service : anciens prix remis par le chemin d'un article, tout ou rien

        return ['restored' => ['batchId' => (int) $undo['batchId'], 'prices' => count($done['restored'])]];
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /** Une ligne est-elle écrite ? Changée, et sans risque sauf accord du gérant */
    private static function written(array $r, bool $force): bool
    {
        return $r['changed'] && ($force || array_intersect($r['alerts'], self::RISKY) === []);
    }

    /** @return array<string, mixed> ligne d'aperçu : ancien et nouveau prix, coût, marge par unité, stock, alertes */
    private static function line(array $r): array
    {
        return ['productId' => $r['productId'], 'variantId' => $r['variantId'], 'name' => $r['name'], 'oldPrice' => $r['oldPrice'], 'newPrice' => $r['newPrice'], 'cost' => $r['cost'],
            'marginBefore' => $r['marginBefore'], 'marginAfter' => $r['marginAfter'], 'marginRateBefore' => $r['marginRateBefore'], 'marginRateAfter' => $r['marginRateAfter'],
            'stock' => $r['stock'], 'alerts' => array_values(array_diff($r['alerts'], ['NO_CHANGE']))];
    }

    /**
     * Corps de la demande au service des prix, d'après les réponses.
     *
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    private function body(array $answers): array
    {
        $mode = (string) $answers['mode'];
        $rounding = $mode === 'fixed' ? 'none' : (in_array($answers['rounding'] ?? null, PriceRounding::MODES, true) ? $answers['rounding'] : $this->settings->rounding());

        return ['mode' => $mode, 'value' => $answers['value'], 'rounding' => $rounding] + (is_array($answers['scope'] ?? null) ? $answers['scope'] : []);
    }

    /**
     * Portée contrôlée dans sa forme (entiers positifs) avant d'être donnée au service des prix.
     *
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    private function checked(array $scope, int $index): array
    {
        $key = (string) array_key_first($scope);
        $ids = $key === 'productIds' ? $scope[$key] : [$scope[$key]];
        if (!is_array($ids) || $ids === [] || array_filter($ids, fn ($id) => !is_int($id) || $id <= 0) !== []) {
            $this->fail($index, $key === 'productIds' && $ids === [] ? 'REQUIRED' : 'INVALID_ID');
        }
        if ($key === 'productIds') {
            $known = [];
            foreach ($this->conn()->fetchFirstColumn('SELECT id FROM product WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) as $found) {
                $known[(int) $found] = true;
            }
            if (array_filter($ids, fn (int $id) => !isset($known[$id])) !== []) {
                $this->fail($index, 'PRODUCT_NOT_FOUND');
            }
        }

        return $scope;
    }

    /** @return array{type: string, id: ?int, count: ?int, label: string} la portée en clair */
    private function scopeLabel(array $scope, string $locale): array
    {
        $key = (string) array_key_first($scope);
        $t = fn (string $id, array $params = []) => $this->tr("conversation.actions.price_set.scope.$id", $locale, $params);
        $conn = $this->conn();
        $name = fn (string $sql) => (string) ($conn->fetchOne($sql, [$scope[$key]]) ?: '#' . $scope[$key]);
        if ($key === 'productIds') {
            return ['type' => 'products', 'id' => null, 'count' => count($scope[$key]), 'label' => $t('products', ['%count%' => (string) count($scope[$key])])];
        }
        if ($key === 'periodId') {
            $period = $this->em()->find(Collections::class, (int) $scope[$key]);

            return ['type' => 'period', 'id' => (int) $scope[$key], 'count' => null, 'label' => $t('period', ['%name%' => $period !== null ? PeriodCalendar::displayName($period, $locale) : '#' . $scope[$key]])];
        }

        return match ($key) {
            'commandeId' => ['type' => 'commande', 'id' => (int) $scope[$key], 'count' => null, 'label' => $t('commande', ['%name%' => $name('SELECT name FROM commande WHERE id = ?')])],
            'collectionId' => ['type' => 'collection', 'id' => (int) $scope[$key], 'count' => null, 'label' => $t('collection', ['%name%' => $name('SELECT nom_collection FROM collections WHERE id = ?')])],
            default => ['type' => 'category', 'id' => (int) $scope[$key], 'count' => null, 'label' => $t('category', ['%name%' => $name('SELECT name FROM categories WHERE id = ?')])],
        };
    }
}
