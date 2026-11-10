# Budgets par poste et voyages d'achat — application de gestion (lot B1a, 11/11/2026)

Contrat validé par l'application (« new easymakemoney »), recalé sur `docs/propositions-pilotage.md` de l'application (décisions du
10/11/2026) : « avant ou pendant une période, le gérant répartit son budget : stock, voyage, frais de port, publicité, autres ; l'écran compare
le prévu au réel ». Tout est additif : les routes et calculs existants ne changent pas, les lectures des notes de frais et des commandes gagnent
seulement `tripId`. `ROLE_ADMIN`. Trésorerie saisie : `docs/tresorerie-gestion.md` (lot B1b-1) ; le simulateur (B1b-2) viendra ensuite.

Code : `Services/GestionService/GestionBudgetService`, `GestionTripService`, `UseCase/GestionUseCase/GestionBudgetUseCase`,
`GestionTripUseCase`, `Controller/GestionController/GestionBudgetController`, `GestionTripController`, `EventListener/TripAutoAssignListener`,
entités `GestionBudgetLine` (`gestion_budget_line`), `GestionTrip` (`gestion_trip`), colonnes `trip_id` / `trip_source` de `note_de_frais` et
`commande`. Textes `translations/gestion.{fr,en}.yaml` (`budget.*`, `tiles.budget_over`, `tiles.trip_budget`, `notifications.trip_budget`).
Migration `Version20261207100000`, script `scripts/migrate_all_v2_gestion_budgets.sh`. Test : `tests/Functional/Gestion/BudgetAndTripsApiTest.php`.

## 1. Budget par poste d'une période

`GET /api/periodes/{id}/budget?locale=` →

```
{ period: { id, name, periodKey }, currency, globalBudget,
  lines: [{ category, label, planned, actual, remaining, ratio, status, details: [{ key, label, actual }] }],
  total: { planned, actual, remaining, ratio, status } }
```

- `category`, toujours les cinq dans cet ordre ; `actual` dans la devise du site (taux de la commande, comme G6) :
  - `purchases` (Stock) : commandes fournisseurs datées dans la période, avec ou sans voyage (= `investment.purchases` du résultat) ;
  - `trip` (Voyage) : notes de frais datées dans la période et rattachées à un voyage ; `details` par type de note (`type:<id>`) ;
  - `shipping` (Frais de port) : comme `expenses.shipping` ;
  - `advertising` (Publicité) : campagnes classées dans la période ; `details` par campagne (`campaign:<id>`) ;
  - `other` (Autres frais) : les autres notes de frais ; `details` par type de note.
  - Σ `actual` = `investment.purchases` + `expenses.total` du résultat de période.
- `ratio` = actual ÷ planned (2 décimales), `null` si planned = 0 ; `status` : `none` (sans prévu), `ok`, `warning` (≥ 80 %), `over` (> 100 %).
- `total.planned` = somme des postes ; `globalBudget` = budget global existant (`PATCH /api/periodes/{id}`), indépendant des postes.
- `PUT /api/periodes/{id}/budget { lines: [{ category, planned }] }` → même forme. Les postes absents gardent leur prévu ; `planned` 0 l'efface ;
  le budget global n'est pas touché. 422 `VALIDATION_FAILED` : `lines[n].category` `INVALID_CATEGORY` / `DUPLICATE_CATEGORY`,
  `lines[n].planned` `MUST_BE_INTEGER_CENTS` (rien n'est écrit) ; 409 `PERIOD_CLOSED` ; 404 `NOT_A_PERIOD` pour une collection manuelle.
- `POST /api/periodes/{id}/budget/copier { fromPeriodId? }` → même forme ; remplace tout le prévu par celui de la source (par défaut la période
  qui contient la même date un an plus tôt) ; 404 `BUDGET_SOURCE_NOT_FOUND` (source absente ou sans prévu) ; 409 `PERIOD_CLOSED`.
- Vignette `budget-over` (alerte, priorité 70) : un poste de la période en cours en `over` ; écran `periode.budget` `{ periodId }` ; `figures`
  `[{ category, label, planned, actual }]`.

## 2. Voyage d'achat

`{ id, name, place, startDate, endDate, days, budgetTotal, budgetDaily, budgetDailyEntered, budgetScope, currency, exchangeRate, siteCurrency,
budgetTotalSite, budgetDailySite, notes, createdAt }`

- `budgetDaily` facultatif : par défaut `budgetTotal` ÷ jours (arrondi bas) ; `budgetDailyEntered` dit s'il a été saisi.
- Devise du voyage et taux comme G6 / A1 (`EXCHANGE_RATE_REQUIRED`, `MUST_BE_POSITIVE`, `INVALID_CURRENCY`) ; `…Site` = dans la devise du site.
- `budgetScope` : `expenses` (défaut, décision du propriétaire du 10/11/2026 : le budget du voyage couvre les notes de frais — déplacement,
  hôtel, repas) | `all` (achats de marchandises compris ; option gardée, réglable par voyage). Stock et frais restent séparés partout ailleurs.
- Deux voyages ne se chevauchent pas : 409 `TRIP_OVERLAP` `{ tripId }`.

| Route (`/api/gestion/voyages`) | Rôle |
|---|---|
| `GET ?from=&to=&page=&limit=` | voyages qui touchent l'intervalle, récents d'abord ; `X-Total-Count`, `X-Page`, `X-Limit` |
| `POST`, `GET /{id}`, `PATCH /{id}`, `DELETE /{id}` | 201 / 200 / 200 / 204 ; 404 `TRIP_NOT_FOUND` ; supprimer libère les éléments rattachés (sauf périodes fermées) |
| `GET /{id}/suivi` | suivi (ci-dessous), `currency` du site à la racine |
| `POST /rattacher { tripId \| null, noteIds?, commandeIds? }` | choix du gérant (§ 3) → `{ tripId, notes: [{ id, tripId }], commandes: [...] }` |

Suivi : `{ trip, budgetScope, inProgress, elapsedDays, days: [{ date, purchases, expenses, spent, budget }], totals: { purchases, expenses,
spent, budget, remaining, allowanceToDate }, today: { date, spent, remaining } | null, averageDaily, projectedTotal, status, overBy }`

- `purchases` = lignes d'achat des commandes rattachées × taux de la commande ; `expenses` = notes rattachées ; `spent` = expenses
  (`expenses`) ou purchases + expenses (`all`). Un élément rattaché à la main hors des dates compte dans les totaux.
- `allowanceToDate` = budget journalier × jours écoulés (au plus le total) ; `status` : `over` si spent > allowanceToDate (voyage commencé) ou
  > budget total, `warning` à 80 %, sinon `ok` ; `overBy` = dépassement ; `projectedTotal` = moyenne par jour écoulé × jours du voyage.
- Vignette `trip-budget` (alerte, priorité 75) et notification `trip_budget` (passage quotidien de 10 h, avant le stock bas,
  `docs/notifications-gestion.md`) : voyage en cours en `over` ; écran `voyages.suivi` `{ tripId }`.

## 3. Rattachement (`tripId`)

- `trip_id` + `trip_source` (`auto` | `manual`) sur `note_de_frais` et `commande` ; `tripId` rendu par les lectures existantes.
- Automatique (`TripAutoAssignListener`, tout chemin d'écriture : routes, confirmation d'un document lu, EasyAdmin) : à la création d'une note ou
  commande et quand sa date change, rattachée au voyage qui couvre sa date (ou détachée si elle en sort), sauf choix du gérant. Créer un voyage
  ou changer ses dates rattache les éléments **libres** (`trip_id` et `trip_source` nuls) de l'intervalle et détache les éléments `auto` qui en
  sortent. Les commandes de reprise d'historique restent hors voyage.
- Choix du gérant : `POST /api/gestion/voyages/rattacher` → `manual`, jamais déplacé ensuite par la date ; `tripId` null détache. Un élément
  détaché par le gérant (`trip_id` nul, `trip_source` manual) n'est **jamais** rattaché de nouveau automatiquement.
- Périodes fermées en lecture seule (décision du propriétaire) : `rattacher` refuse tout l'appel si un élément est dans une période fermée
  (409 `PERIOD_CLOSED` `{ noteIds, commandeIds }`) ; le rattachement automatique les ignore sans erreur ; la suppression d'un voyage les laisse.
  Raison : changer un `tripId` déplace un montant entre `other` et `trip` dans le budget d'une période.
- 422 : `noteIds[n]` `NOTE_NOT_FOUND`, `commandeIds[n]` `COMMANDE_NOT_FOUND`, `noteIds` `REQUIRED` (liste vide), `tripId` `REQUIRED`.
