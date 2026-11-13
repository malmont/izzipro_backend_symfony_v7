<?php

namespace App\Gestion\Sequences;

use App\Gestion\Services\Conversation\Action\ActionCatalog;

/**
 * Catalogue déclaratif du guide des séquences (lot IA-SEQ, 13/11/2026, `docs/sequences-gestion.md`) : les suites d'étapes que le gérant
 * mène, dont le serveur calcule l'avancement à partir des données, jamais d'une case cochée.
 *
 * Deux sortes :
 * - `once`, à faire une fois : une séquence par groupe du cheminement de mise en route accordé au site (`SiteReadinessService`). Ses
 *   étapes, leurs critères et leurs textes sont ceux du cheminement, lus tels quels : rien n'est recopié ici, sauf la référence de
 *   l'action de la gestion qui fait avancer une étape (`ONCE_ACTIONS`).
 * - `recurring`, qui revient, avec ses instances (`RECURRING`) : chaque étape déclare son critère (une méthode de `SequenceFacts`, une
 *   lecture de constat), si elle est indispensable, son écran et la référence de l'action qui la fait avancer.
 *
 * Ajouter une étape = une entrée ici, sa méthode de constat et ses textes (`translations/gestion.{fr,en}.yaml`, `sequences.*`). Le
 * garde-fou de `SequencesApiTest` vérifie que chaque étape a les trois. `preparable` (la conversation sait-elle préparer l'action) vaut
 * vrai pour la facture (`PREPARABLE_ACTIONS`, lot IA-FACT-1) et pour toute action du catalogue du fil (`ActionCatalog::KEYS`, lot IA-ACT).
 */
final class SequenceCatalog
{
    public const KIND_ONCE = 'once';
    public const KIND_RECURRING = 'recurring';

    public const PERIOD_PREPARE = 'period.prepare';
    public const FISCAL_YEAR_CLOSE = 'fiscal_year.close';
    public const HISTORY_RECOVERY = 'history.recovery';

    /** Préfixe de la clé d'une séquence à faire une fois : `setup.` + le groupe du cheminement sans son préfixe `app.` ou `module.` */
    public const ONCE_PREFIX = 'setup.';

    /** Groupes du cheminement traduits en anglais (lot LEX) ; les autres restent en français, la séquence porte `translated: false` */
    public const TRANSLATED_GROUPS = ['app.management', 'module.support', 'ai'];

    /** Écran de l'application de gestion (web et mobile, même code) ; les autres valeurs de `where` sont celles du cheminement */
    public const WHERE_APP = 'app';

    /**
     * Séquences qui reviennent. Par étape : `fact` (méthode de `SequenceFacts`), `blocking` (indispensable), `optional` (facultative :
     * jamais « à faire »), `screen` (code d'écran de l'application) et `action` (référence de l'action qui la fait avancer).
     *
     * `actionParams` : les paramètres à donner à l'ouverture du fil de l'action (lot IA-ACT-2) ; `actionInstanceParams` : ceux qui
     * viennent de l'instance (la période), passés seulement quand ils ont une valeur.
     *
     * @var array<string, array{steps: array<string, array{fact: string, blocking: bool, optional?: bool, screen: string, action: string, actionParams?: array<string, mixed>, actionInstanceParams?: list<string>}>}>
     */
    public const RECURRING = [
        self::PERIOD_PREPARE => ['steps' => [
            // retouche du 13/11/2026 (web) : « Préparer » ouvre le budget de CETTE période par le fil (`budget.put`, avec `periodId` de l'instance)
            'budget' => ['fact' => 'periodBudget', 'blocking' => true, 'screen' => 'periode.preparation', 'action' => 'budget.put', 'actionInstanceParams' => ['periodId']],
            'trip' => ['fact' => 'periodTrip', 'blocking' => false, 'optional' => true, 'screen' => 'voyages', 'action' => 'trip.create'],
            'cash_balance' => ['fact' => 'recentCashBalance', 'blocking' => false, 'screen' => 'tresorerie', 'action' => 'cash.balance.create'],
            'fixed_costs' => ['fact' => 'fixedCostsEntered', 'blocking' => false, 'screen' => 'frais-fixes', 'action' => 'cash.movement.create', 'actionParams' => self::FIXED_COST_PARAMS],
            'purchase_order' => ['fact' => 'periodPurchaseOrder', 'blocking' => false, 'screen' => 'commandes', 'action' => 'purchase.create'],
        ]],
        self::FISCAL_YEAR_CLOSE => ['steps' => [
            'periods_closed' => ['fact' => 'yearPeriodsClosed', 'blocking' => true, 'screen' => 'periodes', 'action' => 'period.close'],
            'purchase_costs' => ['fact' => 'yearPurchaseCosts', 'blocking' => true, 'screen' => 'articles', 'action' => 'product.cost.set'],
            'reconciliation' => ['fact' => 'yearReconciliation', 'blocking' => false, 'screen' => 'tresorerie.rapprochements', 'action' => 'cash.balance.create'],
            'fixed_costs' => ['fact' => 'yearFixedCosts', 'blocking' => false, 'screen' => 'frais-fixes', 'action' => 'cash.movement.create', 'actionParams' => self::FIXED_COST_PARAMS],
        ]],
        self::HISTORY_RECOVERY => ['steps' => [
            'needed' => ['fact' => 'recoveryNeeded', 'blocking' => true, 'screen' => 'reprise', 'action' => 'recovery.simulate'],
            'applied' => ['fact' => 'recoveryApplied', 'blocking' => true, 'screen' => 'reprise', 'action' => 'recovery.apply'],
            'purchase_costs' => ['fact' => 'recoveryPurchaseCosts', 'blocking' => false, 'screen' => 'articles', 'action' => 'product.cost.set'],
        ]],
    ];

    /**
     * Étapes du cheminement qu'une action de la gestion fait avancer : étape => référence de l'action. Les autres étapes portent la
     * référence par défaut `readiness.<clé de l'étape>` (à faire sur l'écran indiqué).
     */
    public const ONCE_ACTIONS = [
        'management.fiscal_year' => 'fiscal_year.set',
        'management.periods_calendar' => 'periods.calendar.set',
        'management.purchase_cost' => 'product.cost.set',
        'management.suppliers' => 'supplier.create',
        'management.cash' => 'cash.balance.create',
        'management.fixed_costs' => 'cash.movement.create',
        'management.first_purchase' => 'document.invoice.confirm',
    ];

    /** Une étape « frais fixes » ouvre le mouvement prévu comme un frais fixe mensuel (lot IA-ACT-2) ; le gérant peut changer les deux réponses */
    public const FIXED_COST_PARAMS = ['fixedCost' => true, 'recurrence' => 'monthly'];

    /** Paramètres d'ouverture du fil de l'action d'une étape du cheminement */
    public const ONCE_ACTION_PARAMS = [
        'management.fixed_costs' => self::FIXED_COST_PARAMS,
    ];

    /** Paramètres de l'écran de l'application pour une étape du cheminement : la liste des articles sans coût */
    public const ONCE_SCREEN_PARAMS = [
        'management.purchase_cost' => ['filter' => 'missing-cost'],
    ];

    /** Codes d'écran de l'application de gestion émis par le guide (ceux du cheminement et ceux des séquences qui reviennent) */
    public const APP_SCREENS = [
        'periodes', 'exercice', 'articles', 'fournisseurs', 'tresorerie', 'frais-fixes', 'commandes', 'notifications', 'assistance-reglages',
        'periode.preparation', 'periode.budget', 'voyages', 'tresorerie.rapprochements', 'annee', 'reprise',
    ];

    /** Clé de la séquence à faire une fois d'un groupe du cheminement : `app.management` → `setup.management`, `ai.help` → `setup.ai.help` */
    public static function onceKey(string $group): string
    {
        return self::ONCE_PREFIX . preg_replace('/^(app|module)\./', '', $group);
    }

    /** Actions que la conversation de la gestion sait préparer (aperçu, empreinte, confirmation, annulation) : la facture depuis le lot IA-FACT-1 */
    public const PREPARABLE_ACTIONS = ['document.invoice.confirm'];

    /**
     * Une étape FAITE dont l'action est préparable : peut-on refaire le geste ? (demande du web, 13/11/2026). `true` : le guide rend
     * `action.again: { label }` (texte `sequences.again.<clé avec _>`) ; `false` : `again: null`, il n'y a rien à refaire. Toute action
     * préparable a ici une réponse explicite : un garde-fou de `SequencesApiTest` le vérifie.
     */
    public const AGAIN = [
        'document.invoice.confirm' => true, 'expense.create' => true, 'supplier.create' => true, 'shipping.create' => true, 'budget.put' => true,
        'cash.balance.create' => true, 'cash.movement.create' => true, 'trip.create' => true, 'fiscal_year.set' => true, 'periods.calendar.set' => true,
        'product.cost.set' => true,
        'period.close' => false, // étape faite = toutes les périodes sont fermées : rien à clôturer de plus
    ];

    public static function preparable(string $action): bool
    {
        return in_array($action, self::PREPARABLE_ACTIONS, true) || in_array($action, ActionCatalog::KEYS, true); // une seule liste de clés : le catalogue des actions du fil
    }

    public static function actionOf(string $readinessStepKey): string
    {
        return self::ONCE_ACTIONS[$readinessStepKey] ?? 'readiness.' . $readinessStepKey;
    }
}
