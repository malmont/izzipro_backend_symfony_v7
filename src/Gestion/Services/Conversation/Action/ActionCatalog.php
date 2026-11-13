<?php

namespace App\Gestion\Services\Conversation\Action;

/**
 * Catalogue des actions que le fil sait préparer (lot IA-ACT, 13/11/2026) : une LISTE BLANCHE. Une action absente d'ici ne s'ouvre pas
 * (422), et la phrase libre ne peut jamais en ouvrir une. Les clés sont celles du guide des séquences (`SequenceCatalog`), une seule
 * liste : une action devient `preparable` dans le guide en entrant ici. Ordre : celui de la fréquence d'usage.
 */
class ActionCatalog
{
    /** Clés du catalogue, dans l'ordre (lecture sans instancier les actions) */
    public const KEYS = ['expense.create', 'supplier.create', 'shipping.create', 'budget.put', 'cash.balance.create', 'cash.movement.create', 'trip.create', // lot IA-ACT-2
        'fiscal_year.set', 'periods.calendar.set', 'product.cost.set', // lot IA-ACT-3
        'period.close']; // lot IA-ACT-4

    /** @var array<string, GestionAction> */
    private array $actions = [];

    public function __construct(ExpenseCreateAction $expense, SupplierCreateAction $supplier, ShippingCreateAction $shipping, BudgetPutAction $budget,
        CashBalanceCreateAction $cashBalance, CashMovementCreateAction $cashMovement, TripCreateAction $trip,
        FiscalYearSetAction $fiscalYear, PeriodsCalendarSetAction $periodsCalendar, ProductCostSetAction $productCost, PeriodCloseAction $periodClose)
    {
        foreach ([$expense, $supplier, $shipping, $budget, $cashBalance, $cashMovement, $trip, $fiscalYear, $periodsCalendar, $productCost, $periodClose] as $action) {
            $this->actions[$action::key()] = $action;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function get(string $key): GestionAction
    {
        return $this->actions[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->actions);
    }
}
