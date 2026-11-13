<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Entity\Collections;
use App\Gestion\Services\GestionProductCostService;
use App\Gestion\Services\MissingCostQuery;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\PeriodResultCalculator;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `product.cost.set` : poser le coût d'achat de la fiche d'UN article. Le prix de vente n'est pas touché : il reste une décision du
 * gérant, jamais un effet de bord (`GestionProductCostService`). L'aperçu montre le coût avant et après, la marge qui en résulte, et
 * l'effet sur les chiffres : les ventes des périodes OUVERTES qui passent de « sans coût connu » à valorisées, et le coût des articles
 * vendus de la période en cours, avant et après, aux règles du résultat de période. Les périodes fermées sont figées, l'aperçu le dit.
 * Annulation : remettre le coût d'avant (vide compris), si le coût n'a pas changé depuis ; elle est écrite au journal comme la pose.
 */
class ProductCostSetAction extends AbstractGestionAction
{
    public const LISTED = 50;

    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly GestionProductCostService $costs, private readonly MissingCostQuery $missing, private readonly PeriodResultCalculator $calculator, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'product.cost.set';
    }

    public function build(array $answers, string $locale): array
    {
        // Articles proposés : ceux sans coût (même règle que le guide et la liste des articles sans coût), par nom ; un autre article peut être donné par son identifiant
        $options = array_map(fn (array $a) => ['value' => $a['id'], 'label' => trim((string) $a['name']) !== '' ? (string) $a['name'] : '#' . $a['id'], 'code' => $a['code'], 'price' => $a['price']],
            $this->missing->page(null, null, null, self::LISTED, 0)['items']);
        $issues = isset($answers['productId']) && $this->product((int) $answers['productId']) === null ? [['path' => 'productId', 'code' => 'PRODUCT_NOT_FOUND']] : [];

        return ['questions' => [
            $this->question('productId', true, ['type' => 'choice'], $options, $answers['productId'] ?? null, $locale),
            $this->question('cost', true, ['type' => 'money', 'currency' => $this->site()], [], $answers['cost'] ?? null, $locale),
        ], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($question['id'] === 'productId') {
            return is_int($value) && $this->product($value) !== null ? $value : $this->fail($index, 'PRODUCT_NOT_FOUND');
        }

        return $this->cents($value, $index);
    }

    public function preview(array $answers, string $locale): array
    {
        $id = (int) $answers['productId'];
        $cost = (int) $answers['cost'];
        $product = $this->product($id) ?? [];
        $price = isset($product['price']) && $product['price'] !== null ? (int) round((float) $product['price']) : null;
        $before = $this->costs->current($id);
        $includesTax = in_array($this->conn()->fetchOne('SELECT prices_include_tax FROM entreprise ORDER BY id LIMIT 1'), [true, 't', 1, '1'], true);
        // Effet sur les chiffres, aux règles du résultat de période, en lecture seule : périodes ouvertes commencées, et période en cours
        $today = $this->calendar->today();
        $valued = 0;
        $open = 0;
        foreach ($this->em()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD], ['startDateCollection' => 'DESC'], 24) as $period) {
            if ($period->isDel() || $this->calendar->isClosed($period) || $period->getStartDateCollection()?->format('Y-m-d') > $today->format('Y-m-d')) {
                continue;
            }
            ++$open;
            [$from, $to] = [\DateTimeImmutable::createFromInterface($period->getStartDateCollection()), \DateTimeImmutable::createFromInterface($period->getEndDateCollection())];
            $valued += $this->calculator->costFigures($from, $to, [$id])['unknownItems'] - $this->calculator->costFigures($from, $to, [$id], [$id => $cost])['unknownItems'];
        }
        $current = $this->calendar->findByDate($today);
        $currentFigures = null;
        if ($current !== null && !$this->calendar->isClosed($current)) {
            [$from, $to] = [\DateTimeImmutable::createFromInterface($current->getStartDateCollection()), \DateTimeImmutable::createFromInterface($current->getEndDateCollection())];
            $all = $this->calculator->costFigures($from, $to)['cogs'];
            $delta = $this->calculator->costFigures($from, $to, [$id], [$id => $cost])['cogs'] - $this->calculator->costFigures($from, $to, [$id])['cogs'];
            $currentFigures = ['id' => (int) $current->getId(), 'name' => PeriodCalendar::displayName($current, $locale), 'cogsBefore' => $all, 'cogsAfter' => $all + $delta];
        }
        $effectText = $this->tr('conversation.actions.product_cost_set.effect.' . ($valued > 0 ? 'some' : 'none'), $locale, ['%count%' => (string) $valued])
            . ($currentFigures === null ? '' : ' ' . $this->tr('conversation.actions.product_cost_set.effect.current', $locale, ['%period%' => $currentFigures['name'],
                '%before%' => self::amount($currentFigures['cogsBefore'], $this->site(), $locale), '%after%' => self::amount($currentFigures['cogsAfter'], $this->site(), $locale)]))
            . ' ' . $this->tr('conversation.actions.product_cost_set.effect.closed', $locale);

        return ['product' => ['id' => $id, 'name' => $product['name'] ?? null, 'code' => $product['code'] ?? null], 'currency' => $this->site(),
            'cost' => ['before' => $before, 'after' => $cost],
            'price' => $price, // inchangé par cette action
            'margin' => $price === null || $price <= 0 ? null : ['amount' => $price - $cost, 'rate' => round(($price - $cost) / $price * 100, 2), 'pricesIncludeTax' => $includesTax],
            'effect' => ['openPeriods' => $open, 'salesValued' => $valued, 'currentPeriod' => $currentFigures, 'closedPeriodsFrozen' => true, 'text' => $effectText]];
    }

    public function rows(array $preview, string $locale): array
    {
        $money = ['currency' => $preview['currency']];
        $margin = $preview['margin'];

        return array_values(array_filter([
            $this->row('productId', $locale, trim((string) $preview['product']['name']) !== '' ? $preview['product']['name'] : '#' . $preview['product']['id'], 'text'),
            $this->row('cost', $locale, $preview['cost']['after'], 'money', $money + ($preview['cost']['before'] === null ? [] : ['before' => $preview['cost']['before']])),
            $preview['price'] === null ? null : $this->row('price', $locale, $preview['price'], 'money', $money),
            $margin === null ? null : $this->row('margin', $locale, $margin['amount'], 'money', $money),
            $margin === null ? null : $this->row('marginRate', $locale, number_format($margin['rate'], 2, $locale === 'en' ? '.' : ',', '') . ' %'
                . ($margin['pricesIncludeTax'] ? ' ' . $this->tr('conversation.actions.product_cost_set.marginRate.tax', $locale) : ''), 'text'),
            $this->row('effect', $locale, $preview['effect']['text'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $done = $this->costs->set((int) $answers['productId'], (int) $answers['cost']); // 404 PRODUCT_NOT_FOUND ; journal products.purchasePrice ; prix de vente intact

        return ['result' => ['productId' => (int) $answers['productId'], 'cost' => $done['after']], 'undo' => ['productId' => (int) $answers['productId'], 'before' => $done['before'], 'after' => $done['after']]];
    }

    public function blockers(array $undo): array
    {
        if ($this->product((int) $undo['productId']) === null) {
            return [['code' => 'COST_CHANGED', 'message' => 'L\'article n\'existe plus.']];
        }

        return $this->costs->current((int) $undo['productId']) === (int) $undo['after'] ? [] : [['code' => 'COST_CHANGED', 'message' => 'Le coût d\'achat de cet article a été modifié depuis.']];
    }

    public function undo(array $undo): array
    {
        $this->costs->set((int) $undo['productId'], $undo['before'] === null ? null : (int) $undo['before']); // même service : au journal, prix de vente intact

        return ['restored' => ['productId' => (int) $undo['productId'], 'cost' => $undo['before']]];
    }

    /** @return array<string, mixed>|null nom, code et prix de vente de l'article (lecture seule) */
    private function product(int $id): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT name, code, price FROM product WHERE id = ?', [$id]);

        return $row === false ? null : $row;
    }
}
