# Trésorerie saisie — application de gestion (lot B1b-1, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, sur `docs/propositions-pilotage.md` de l'application : « le backend
ne connaît pas le solde bancaire. La trésorerie est donc saisie par le gérant, datée, et une liaison bancaire pourra venir plus tard. »
Tout est additif. `ROLE_ADMIN` (`access_control` `/api/gestion`), montants en cents dans la devise du site. Code d'écran : `tresorerie`.
Le simulateur (lot B1b-2) lira cette trésorerie pour sa « trésorerie après achats ».

Code : `Services/GestionService/GestionCashService`, `UseCase/GestionUseCase/GestionCashUseCase`, `Controller/GestionController/GestionCashController`,
entités `GestionCashBalance` (`gestion_cash_balance`), `GestionCashMovement` (`gestion_cash_movement`), `GestionCashSetting` (`gestion_cash_setting`),
lues et écrites en SQL, jamais en cache applicatif (`CacheTags::NO_CACHE_READ`). Vignettes dans `HomeTilesService`, notification dans
`GestionNotificationService`. Textes `translations/gestion.{fr,en}.yaml` (`cash.*`, `tiles.cash_low`, `tiles.cash_first_balance`,
`notifications.cash_critical`). Migration `Version20261208100000`, script `scripts/migrate_all_v2_gestion_tresorerie.sh`.
Test : `tests/Functional/Gestion/CashApiTest.php`.

## 1. Soldes saisis

- `GET /api/gestion/tresorerie/soldes?limit=50` (200 au plus) → `[{ id, date, amount, note, ageDays, createdAt, updatedAt }]`, du plus récent au plus ancien.
- `POST /api/gestion/tresorerie/soldes { date, amount, note? }` → 201, ou **200 avec `replaced: true`** quand un solde existe déjà à cette
  date : il est remplacé (une ligne par date). `amount` en cents **signés** (un découvert est négatif) : le solde à la **fin** de la journée.
- `PATCH /api/gestion/tresorerie/soldes/{id}` (champs à changer) ; 409 `BALANCE_DATE_TAKEN { balanceId }` vers une date déjà saisie.
- `DELETE /api/gestion/tresorerie/soldes/{id}` → 204.
- Erreurs : 404 `BALANCE_NOT_FOUND` ; 422 par champ `REQUIRED`, `INVALID_DATE`, `DATE_IN_FUTURE`, `MUST_BE_INTEGER_CENTS`.
- **Solde de référence** : le dernier dont la date tombe au plus tard aujourd'hui (jour du magasin).

## 2. Mouvements prévus

- `GET /api/gestion/tresorerie/mouvements?category=&locale=` → `[{ id, label, category, categoryLabel, direction, amount, startDate, recurrence, endDate, notes, nextDate }]`.
- `POST /api/gestion/tresorerie/mouvements { label, category, direction?, amount, startDate (ou date), recurrence?, endDate?, notes? }` → 201.
- `PATCH /api/gestion/tresorerie/mouvements/{id}`, `DELETE …/{id}` → 204.
- `category` : `rent` (Loyer) | `salaries` (Salaires) | `insurance` (Assurances) | `subscription` (Abonnements) | `loan` (Prêt) | `tax`
  (Taxes et impôts) | `other` (Autres). `direction` : `out` (défaut) | `in` (un prêt reçu, un remboursement de taxe).
- `recurrence` : `none` (défaut) | `weekly` | `monthly` | `quarterly` | `yearly` ; mensuel, trimestriel, annuel le même jour du mois que
  `startDate`, **le dernier jour du mois quand il est plus court** (31 → 30, 28 ou 29 ; 29 février → 28 février les autres années).
  `endDate` (incluse) est ignorée pour un mouvement ponctuel. `nextDate` : prochaine date à partir d'aujourd'hui, `null` s'il n'y en a plus.
- Devise du site seulement. Une occurrence passée est supposée payée.
- Erreurs : 404 `MOVEMENT_NOT_FOUND` ; 422 `REQUIRED`, `INVALID_CATEGORY`, `INVALID_DIRECTION`, `INVALID_RECURRENCE`, `MUST_BE_POSITIVE`,
  `MUST_BE_INTEGER_CENTS`, `BEFORE_START` (fin avant début), `INVALID_DATE`.
- **Salaires** : l'administrateur voit chaque ligne. Un lecteur sans `ROLE_ADMIN` (aucun aujourd'hui : toutes ces routes sont `ROLE_ADMIN`)
  reçoit les salaires regroupés en une ligne `{ id: null, grouped: true, category: "salaries", label: "Salaires", amount, amountPeriodDays: 30, count }`,
  `amount` = total des 30 prochains jours. Le guide IA (G1) ne recevra que les totaux par catégorie.
- Les mouvements prévus restent saisis à la main : le gérant n'y recopie pas ce qu'il saisit déjà dans l'application (commandes, notes,
  publicité), compté à part (§ 4), donc pas de double compte.

## 3. Réglages

- `GET /api/gestion/tresorerie/reglages` → `{ threshold, includeEstimatedSales }` (0 et faux par défaut).
- `PUT /api/gestion/tresorerie/reglages { threshold?, includeEstimatedSales? }` → même forme ; un champ absent est gardé ; 422
  `MUST_BE_INTEGER_CENTS`, `MUST_BE_BOOLEAN`. `threshold` en cents signés (négatif = découvert autorisé).

## 4. Projection

`GET /api/gestion/tresorerie?days=90&locale=` (`days` de 14 à 365 ; 422 `MUST_BE_INTEGER` / `OUT_OF_RANGE`) →

```
{ currency, settings: { threshold, includeEstimatedSales },
  balance: { id, date, amount, note, ageDays, … } | null,
  estimatedDailyReceipts: { amount, windowDays: 30, from, to, orders, estimated: true },
  alertSeries: "projection" | "projectionWithSales",
  projection: { days: [{ date, in, out, balance, opening? }], low: { date, amount }, low14: { date, amount }, belowThresholdFrom, end: { date, amount } } | null,
  projectionWithSales: { days: [{ date, in, out, receipts, receiptsEstimated, balance, opening? }], …, estimated: true, receiptsTotal } | null,
  status: "no_balance" | "ok" | "warning" | "critical",
  byCategory: [{ category, label, in, out }, …, { category: "recorded", label: "Dépenses saisies", in: 0, out, details: { purchases, shipping, notes, advertising } }],
  caveats: [{ code, text }] }
```

- **Jours** : du jour du solde de référence (`opening: true`, son montant) jusqu'à aujourd'hui + `days` − 1. Ce qui est daté **le jour du
  solde** est supposé déjà compris dans le solde saisi ; chaque jour suivant : `balance` = veille + `in` − `out` (+ `receipts`).
- `in` : mouvements prévus entrants. `out` : mouvements prévus sortants **+ dépenses saisies dans l'application**, à leur date (la date de la
  dépense sert de date de paiement ; une dépense datée dans le futur compte à sa date) :
  - commandes fournisseurs : lignes × coût × taux de la commande, même règle que les tableaux de bord (`commande_line`, sinon prix d'achat de
    la fiche, sinon prix de vente) ; **commandes de reprise d'historique exclues** (ce n'est pas une dépense) ;
  - leurs frais de port (× taux), datés comme la commande ;
  - notes de frais ;
  - campagnes de publicité, à leur date de début (× taux de la régie).
- `projection` ne devine rien. `projectionWithSales` ajoute les **encaissements nets** :
  - jours passés (du lendemain du solde à hier) : les encaissements **réels** de chaque jour ;
  - à partir d'aujourd'hui : la **moyenne** des 30 jours complets avant aujourd'hui (`estimatedDailyReceipts`), `receiptsEstimated: true`.
  - Encaissement net d'une commande client (jour du magasin, commandes incomplètes et annulées exclues) : ventes, locations, abonnements et
    cartes-cadeaux vendues au **total TTC** (taxes, livraison et arrondi des espèces compris), **moins la part payée par carte-cadeau, avoir ou
    coupon du fabricant** (`order.gift_card_applied`, `credit_applied`, `manufacturer_coupon_applied`) ; un retour retire son total, sauf la
    part rendue en avoir. Approximation connue : la part d'un retour recréditée sur une carte-cadeau compte comme remboursée.
- `low` : point bas à partir d'aujourd'hui ; `low14` : point bas d'aujourd'hui aux 13 jours suivants ; `belowThresholdFrom` : premier jour
  (à partir d'aujourd'hui) sous le seuil ; `end` : dernier jour.
- `alertSeries` : `projectionWithSales` si `includeEstimatedSales`, sinon `projection`. `status`, sur `low14` de cette série :
  - `critical` : sous le seuil **et** sous 0 ;
  - `warning` : sous le seuil, encore positif ou nul ;
  - `ok` sinon. Seuil 0 (défaut) : passer sous 0 est critique, `warning` n'arrive pas ; seuil négatif : rien tant que le découvert autorisé
    n'est pas atteint.
- Sans solde : 200, `balance`, `projection` et `projectionWithSales` à `null`, `status: "no_balance"`.
- `byCategory` : totaux des mouvements prévus et des dépenses saisies sur les jours de la projection (après le jour du solde).
- `caveats` : `STALE_BALANCE` (solde de plus de 7 jours), `RECORDED_EXPENSES_INCLUDED` (toujours), `TAXES_INCLUDED` (toujours : les
  encaissements comprennent les taxes, à prévoir en mouvement `tax`), `NO_SALES_HISTORY` (aucune commande sur les 30 jours).

## 5. Vignettes et notification

- Vignette **`cash-low`** (alert, priorité 85, au-dessus du stock bas) quand `status` vaut `warning` ou `critical` : « Le solde prévu
  descend à X le JJ mois. » ; `sources` : solde saisi, seuil ; `figures` : `[{ date, amount, threshold, status, series }]` ; écran
  `tresorerie` `{}` ; signature = date du point bas + statut (écartée 7 jours, revient plus tôt si l'un change).
- Vignette **`cash-first-balance`** (first_steps, priorité 20) : aucun solde saisi, et au moins un mouvement prévu ou une période en cours
  (masquée sur un compte tout neuf) ; écran `tresorerie` `{}`.
- Notification **`cash_critical`** (disponible depuis B1b-1) : au passage quotidien de 10 h du magasin, **en premier** (avant `trip_budget` et
  le stock bas), dès `warning` ; une par jour et par gérant comme les autres ; **pas de répétition** de la même alerte pendant 7 jours, sauf si
  le point bas avance ou se creuse ; `subject` `{ date, amount, status }` ; écran `tresorerie` `{}`. Coupable par gérant.

## 6. Lots

| Lot | Contenu |
|---|---|
| **B1b-1** | soldes, mouvements prévus, réglages, projection en deux séries avec dépenses saisies et encaissements réels, vignettes `cash-low` / `cash-first-balance`, notification `cash_critical` ; livré le 11/11/2026 |
| B1b-2 | simulateur de période (aucune écriture), trésorerie après achats à partir de cette fiche |
| Plus tard | liaison bancaire ; « marquer payé » ou « sauter une occurrence » si besoin ; frais fixes du résultat appuyés sur les mouvements prévus |
