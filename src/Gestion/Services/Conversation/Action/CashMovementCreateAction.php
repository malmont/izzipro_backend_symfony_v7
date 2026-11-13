<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Gestion\Entity\GestionCashMovement;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionFixedCostService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `cash.movement.create` : saisir un mouvement prévu de trésorerie, dont un frais fixe (libellé, sens, catégorie, montant, date de début,
 * récurrence, date de fin, frais fixe, notes). Sans réponse à « frais fixe », la règle de la catégorie s'applique : l'aperçu dit laquelle,
 * et ce qu'elle donne. Annulation : retirer le mouvement s'il n'a pas changé.
 */
class CashMovementCreateAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency, private readonly GestionCashService $cash)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'cash.movement.create';
    }

    public function build(array $answers, string $locale): array
    {
        $option = fn (string $group, string $value) => ['value' => $value, 'label' => $this->tr("conversation.actions.cash_movement_create.options.$group.$value", $locale)];
        $categories = array_map(fn (string $c) => ['value' => $c, 'label' => $this->tr("cash.categories.$c", $locale)], GestionCashMovement::CATEGORIES);
        $yesNo = [['value' => true, 'label' => $this->tr('conversation.actions.cash_movement_create.options.fixed.yes', $locale)], ['value' => false, 'label' => $this->tr('conversation.actions.cash_movement_create.options.fixed.no', $locale)]];
        $issues = [];
        if (isset($answers['startDate'], $answers['endDate']) && $answers['endDate'] < $answers['startDate']) {
            $issues[] = ['path' => 'endDate', 'code' => 'BEFORE_START'];
        }

        return ['questions' => [
            $this->question('label', true, ['type' => 'text'], [], $answers['label'] ?? null, $locale),
            $this->question('direction', true, ['type' => 'choice'], array_map(fn (string $d) => $option('direction', $d), GestionCashMovement::DIRECTIONS), $answers['direction'] ?? 'out', $locale, [], !isset($answers['direction'])),
            $this->question('category', true, ['type' => 'choice'], $categories, $answers['category'] ?? null, $locale),
            $this->question('amount', true, ['type' => 'money', 'currency' => $this->site()], [], $answers['amount'] ?? null, $locale),
            $this->question('startDate', true, ['type' => 'date'], [], $answers['startDate'] ?? null, $locale),
            $this->question('recurrence', true, ['type' => 'choice'], array_map(fn (string $r) => $option('recurrence', $r), GestionCashMovement::RECURRENCES), $answers['recurrence'] ?? 'none', $locale, [], !isset($answers['recurrence'])),
            $this->question('endDate', false, ['type' => 'date'], [], $answers['endDate'] ?? null, $locale),
            $this->question('fixedCost', false, ['type' => 'choice'], $yesNo, $answers['fixedCost'] ?? null, $locale),
            $this->question('notes', false, ['type' => 'text'], [], $answers['notes'] ?? null, $locale),
        ], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'label':
                return $this->text($value, $index);
            case 'direction':
                return is_string($value) && in_array($value, GestionCashMovement::DIRECTIONS, true) ? $value : $this->fail($index, 'INVALID_DIRECTION');
            case 'category':
                return is_string($value) && in_array($value, GestionCashMovement::CATEGORIES, true) ? $value : $this->fail($index, 'INVALID_CATEGORY');
            case 'amount':
                return $this->cents($value, $index);
            case 'startDate':
                return self::day($value) ?? $this->fail($index, 'INVALID_DATE');
            case 'recurrence':
                return is_string($value) && in_array($value, GestionCashMovement::RECURRENCES, true) ? $value : $this->fail($index, 'INVALID_RECURRENCE');
            case 'endDate':
                $day = self::day($value) ?? $this->fail($index, 'INVALID_DATE');

                return isset($answers['startDate']) && $day < $answers['startDate'] ? $this->fail($index, 'BEFORE_START') : $day;
            case 'fixedCost':
                return is_bool($value) ? $value : $this->fail($index, 'MUST_BE_BOOLEAN');
            default:
                return $this->text($value, $index, 2000);
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $v = $this->values($answers);
        $regular = $v['direction'] === 'out' && $v['recurrence'] !== 'none'; // une entrée ou un mouvement ponctuel n'est jamais un frais fixe
        $byRule = GestionFixedCostService::defaultFixed($v['category'], $v['direction'], $v['recurrence']);
        $fixed = $regular && ($v['fixedCost'] ?? $byRule);
        $source = !$regular ? 'never' : ($v['fixedCost'] !== null ? 'choice' : 'rule');
        $category = $this->tr('cash.categories.' . $v['category'], $locale);

        return ['label' => $v['label'], 'direction' => $v['direction'], 'category' => $v['category'], 'categoryLabel' => $category, 'amount' => $v['amount'], 'currency' => $this->site(),
            'startDate' => $v['startDate'], 'recurrence' => $v['recurrence'], 'endDate' => $v['recurrence'] === 'none' ? null : $v['endDate'], 'notes' => $v['notes'],
            'monthlyEquivalent' => GestionFixedCostService::monthlyEquivalent($v['amount'], $v['recurrence']),
            // le gérant voit la décision avant de confirmer : son choix, la règle de la catégorie, ou « jamais » (entrée, mouvement ponctuel)
            'fixedCost' => ['value' => $fixed, 'source' => $source,
                'text' => $this->tr('conversation.actions.cash_movement_create.fixed.' . ($fixed ? 'yes' : 'no') . '_' . $source, $locale, ['%category%' => $category])]];
    }

    public function rows(array $preview, string $locale): array
    {
        $option = fn (string $group, string $value) => $this->tr("conversation.actions.cash_movement_create.options.$group.$value", $locale);

        return array_values(array_filter([
            $this->row('label', $locale, $preview['label'], 'text'),
            $this->row('direction', $locale, $option('direction', $preview['direction']), 'text'),
            $this->row('category', $locale, $preview['categoryLabel'], 'text'),
            $this->row('amount', $locale, $preview['amount'], 'money', ['currency' => $preview['currency']]),
            $this->row('startDate', $locale, $preview['startDate'], 'date'),
            $this->row('recurrence', $locale, $option('recurrence', $preview['recurrence']), 'text'),
            $preview['endDate'] === null ? null : $this->row('endDate', $locale, $preview['endDate'], 'date'),
            $this->row('fixedCost', $locale, $preview['fixedCost']['text'], 'text'),
            $preview['notes'] === null ? null : $this->row('notes', $locale, $preview['notes'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $v = $this->values($answers);
        $created = $this->cash->createMovement($v, $user, 'fr'); // mêmes contrôles que la route ; journal gestion-tresorerie-mouvements

        return ['result' => ['movementId' => $created['id'], 'fixedCost' => $created['fixedCost']], 'undo' => ['id' => $created['id']] + $this->stored((int) $created['id'])];
    }

    public function blockers(array $undo): array
    {
        $row = $this->stored((int) $undo['id']);
        if ($row === null) {
            return [['code' => 'MOVEMENT_CHANGED', 'message' => 'Le mouvement créé par cette action n\'existe plus.']];
        }

        // comparaison stricte : « non » choisi (false) et « pas de choix » (null) ne sont pas le même mouvement
        return $row === array_diff_key($undo, ['id' => true]) ? [] : [['code' => 'MOVEMENT_CHANGED', 'message' => 'Le mouvement a été modifié depuis sa création.']];
    }

    public function undo(array $undo): array
    {
        if ($this->stored((int) $undo['id']) === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Le mouvement n\'existe plus.');
        }
        $this->cash->deleteMovement((int) $undo['id'], 'fr');

        return ['removed' => ['movement' => (int) $undo['id']]];
    }

    /** Paramètres complets : les réponses, sinon les valeurs proposées (sortie, ponctuel) */
    private function values(array $answers): array
    {
        return ['label' => (string) $answers['label'], 'direction' => $answers['direction'] ?? 'out', 'category' => (string) $answers['category'], 'amount' => (int) $answers['amount'],
            'startDate' => (string) $answers['startDate'], 'recurrence' => $answers['recurrence'] ?? 'none', 'endDate' => $answers['endDate'] ?? null,
            'fixedCost' => $answers['fixedCost'] ?? null, 'notes' => $answers['notes'] ?? null];
    }

    /** @return array<string, mixed>|null ce que le mouvement porte en base, pour constater un changement */
    private function stored(int $id): ?array
    {
        $r = $this->conn()->fetchAssociative('SELECT label, category, direction, amount_cents, start_date, recurrence, end_date, notes, fixed_cost FROM gestion_cash_movement WHERE id = ?', [$id]);

        return $r === false ? null : ['label' => (string) $r['label'], 'category' => (string) $r['category'], 'direction' => (string) $r['direction'], 'amount' => (int) $r['amount_cents'],
            'startDate' => substr((string) $r['start_date'], 0, 10), 'recurrence' => (string) $r['recurrence'], 'endDate' => $r['end_date'] === null ? null : substr((string) $r['end_date'], 0, 10),
            'notes' => $r['notes'], 'fixedCost' => $r['fixed_cost'] === null ? null : (bool) $r['fixed_cost']];
    }
}
