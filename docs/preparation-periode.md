# Préparer une période entière — application de gestion (lot P6, 11/11/2026)

Idée du propriétaire, contrat validé par l'application (« new easymakemoney ») le 11/11/2026 avec la correction C1 : un assistant pour planifier la
période en cours ou une période à venir à partir des données réelles (estimation des ventes, achats de stock, voyage d'achat, frais), l'avis du
guide, puis « Valider » qui crée le budget par poste et le voyage. **Aucune écriture hors de « Valider »** (sauf la création d'une période manquante,
comme le budget). `ROLE_ADMIN`, montants en cents dans la devise du site.

Code : `Gestion/Services/GestionPreparationService` (estimations, simulation, validation), `GestionPreparationAdviceService` (candidats et
avis du guide, via `GestionSimulationCommentService::askGuide`), `Gestion/UseCase/GestionPreparationUseCase`,
`Gestion/Controller/GestionPreparationController`. Réutilise : calendrier des périodes, budgets B1a (`put`), voyages (`create`,
TRIP_OVERLAP), trésorerie B1b-1 (projection), coûts L0 et stock du simulateur B1b-2, avis G1b-2 (vérification des chiffres, cache et plafond partagé
de 10 avis par jour). Textes `translations/gestion.{fr,en}.yaml` (`preparation.*`). Test : `tests/Functional/Gestion/PreparationApiTest.php`.

Période visée : `periodId`, ou `date` (période qui la contient, créée au besoin) ; 404 `PERIOD_NOT_FOUND`, 409 `PERIOD_CLOSED`.

## 1. Estimer les ventes

`GET /api/gestion/preparation/estimations?periodId=|date=` → `{ period, days, lastYear { revenue, grossMargin, units, from, to } | null,
average { revenue, periods [{ id, name, revenue, days }] } | null, pace { revenue, last30Days }, trendGap, recommended { method, reason } }`.
- `lastYear` : ventes de la même période l'an dernier (mêmes règles que le résultat) ; `average` : moyenne par jour des **4 dernières périodes
  terminées** × jours ; `pace` : 30 derniers jours ÷ 30 × jours.
- Méthode conseillée : `lastYear` s'il existe (la saison), sinon `average` dès 2 périodes, sinon `pace` ; si le rythme des 30 derniers jours
  s'écarte de plus de **25 %** des mêmes jours l'an dernier, la raison le dit (`trendGap`). Le gérant choisit ou saisit son objectif (`custom`).

## 2. Plan simulé (aucune écriture)

`POST /api/gestion/preparation/simulation { periodId | date, sales { method: lastYear | average | pace | custom, amount? }, purchases [{ date, amount,
coefficient? }], trip? { startDate, endDate, place?, name?, budgetTotal? | budgetDaily?, currency?, exchangeRate? }, planned? { shipping?,
advertising?, other? }, locale? }`

- **C1** : les achats et le voyage se font souvent **avant** la période (voyage d'octobre pour décembre) : leurs dates vont d'aujourd'hui à la fin de
  la période visée (sinon `OUT_OF_RANGE`).
- Stock disponible (valeur de vente) = stock actuel (prix affiché, variantes) + Σ achats × coefficient (par défaut le coefficient de référence du
  site : lignes d'achat des 365 derniers jours, au moins 20, sinon 2). Ventes prévues plafonnées au disponible (`TARGET_EXCEEDS_STOCK`).
- Marge **au prorata du disponible** : part vendue sur le stock existant à son taux de marque au coût connu (L0 ; coût inconnu hors marge,
  `UNKNOWN_COST`), part vendue sur les achats à 1 − 1/coefficient. Le stock qui dort ne gonfle pas la marge. Conséquence : moins d'achats peut
  plafonner les ventes et baisser le résultat.
- Frais de la période visée : `trip` (si le voyage y tombe), `shipping`, `advertising`, `other`, chacun au moins le réel déjà engagé.
- Réponse : `{ period, currency, estimations, plan: { sales { target, method, planned, capped }, grossMargin, tauxMarque, expenses { byCategory,
  total, tripInOtherPeriod }, result, breakEven, fixedCosts, resultAfterFixed, breakEvenAfterFixed, purchases { total, saleValue, coefficient, referenceCoefficient }, stockStart, stockEnd, cash },
  lastYear { revenue, grossMargin, result } | null, delta, planned: [{ period { id, name, start, end }, target, purchases, trip, shipping,
  advertising, other, mode: replace | add }], trip, previewHash, caveats }`.
- **Prévu par période** (C1, règle de classement par date du propriétaire) : chaque achat prévu dans la période de sa date, les frais du voyage dans
  la période de son début, les autres postes dans la période visée. Période visée : `mode: replace` (comme le `PUT` du budget) ; autres périodes :
  `mode: add` (achats et voyage **ajoutés** au prévu existant, autres postes intacts).
- Lot FF2 : `fixedCosts` (frais fixes de la période visée au prorata, `docs/frais-fixes-gestion.md`), `resultAfterFixed` et
  `breakEvenAfterFixed` en variante, remarque `FIXED_COSTS_INCLUDED` ; `result` et `breakEven` ne changent pas.
- `tripInOtherPeriod` : le voyage tombe dans une autre période ; ses frais n'entrent pas dans le résultat de la période visée (ils comptent dans
  celle du voyage). Les achats faits avant restent dans le stock disponible des ventes visées.
- **Trésorerie** (`cash`, null sans solde : `NO_CASH_BALANCE`) : d'aujourd'hui à la fin de la période visée, projection B1b-1 + achats à leur date
  + frais du voyage jour par jour + reste des autres postes et ventes restantes étalés également sur les jours de la période (`SALES_SPREAD_EVENLY`) ;
  ventes en TTC approché au taux réel des taxes des 90 derniers jours (`TAXES_ESTIMATED`), sinon hors taxes (`TAXES_EXCLUDED`). `{ start, low,
  end, belowThresholdFrom, threshold, taxRate, days }` ; le point bas tombe souvent avant la période.
  Forme exacte (`GestionPreparationService::cashPath`), montants en cents de la devise du site, dates `AAAA-MM-JJ` au jour du magasin.
  **Ce sont des objets, pas des nombres** :
  - `start` : `{ date, amount }`, toujours présent : le solde projeté d'aujourd'hui.
  - `low` : `{ date, amount }`, le solde le plus bas du parcours et son jour ; `end` : `{ date, amount }`, le solde du dernier jour.
    Les deux sont `null` seulement quand il n'y a aucun jour à projeter (période visée déjà terminée).
  - `days` : `[{ date, in, out, balance }]`, un élément par jour d'aujourd'hui à la fin de la période visée, toujours présent (tableau
    vide dans le même cas) : `in` les entrées du jour, `out` les sorties du jour (les deux positives ou nulles), `balance` le solde en
    fin de jour (peut être négatif).
  - `belowThresholdFrom` : la première date où le solde passe sous le seuil, sinon `null` ; `threshold` : le seuil réglé (entier,
    toujours présent) ; `taxRate` : le taux de taxes appliqué aux ventes (décimal, 0,14975 pour 14,975 %), `null` sans vente taxée
    sur 90 jours.
- `previewHash` : entrées, objectif, stock et coûts, solde de départ, prévus existants des périodes concernées ; jamais l'heure.
- Erreurs 422 : `sales.method` `INVALID_METHOD`, `sales.amount` `REQUIRED` | `MUST_BE_INTEGER_CENTS`, `purchases` `MUST_BE_LIST` | `TOO_MANY` (50),
  `purchases[i].date` `INVALID_DATE` | `OUT_OF_RANGE`, `purchases[i].amount` `MUST_BE_POSITIVE`, `purchases[i].coefficient` `OUT_OF_RANGE` (1 à 20),
  `trip.*` (`INVALID_DATE`, `OUT_OF_RANGE`, `BEFORE_START`, `REQUIRED`, `MUST_BE_INTEGER_CENTS`, `EXCHANGE_RATE_REQUIRED`), `planned.*` `MUST_BE_INTEGER_CENTS`.

## 3. Valider (la seule écriture)

`POST /api/gestion/preparation/valider { …même corps…, previewHash }` → 201 `{ period, planned, budget, trip }`.
- Plan recalculé : 409 `PRICES_CHANGED { simulation }` si l'empreinte diffère.
- Une transaction, tout ou rien : budget de chaque période concernée par `GestionBudgetService::put` (mêmes gardes : 409 `PERIOD_CLOSED`) — période
  visée remplacée, autres périodes : achats et voyage ajoutés au prévu existant ; voyage créé par `GestionTripService::create` (règles des voyages,
  409 `TRIP_OVERLAP` annule aussi les budgets ; rattachement des dépenses par la date comme d'habitude). Les achats prévus ne créent aucune commande.

## 4. Avis du guide

`POST /api/gestion/preparation/avis?locale= { …même corps… }` → `{ simulation, candidates, comment { recommended, title, text, textSource, rejected? },
ai { status }, cached }`.
- Candidats, chacun repassé dans la simulation : `myPlan` ; `lastYearSales` (objectif de l'an dernier, s'il existe) ; `cashSafe` (achats datés
  jusqu'au point bas réduits, les derniers d'abord, juste assez pour remonter au seuil) ; `enoughStock` (achats mis à l'échelle pour que le disponible
  couvre l'objectif **+ 20 %**). Chacun : `{ key, label, purchases, sales, grossMargin, result, cashLow, stockEndSaleValue, planned, delta { result,
  purchases, cashLow } }` (écarts avec mon plan).
- Avis des règles : `cashSafe` si la trésorerie passe sous le seuil ; sinon `enoughStock` si l'objectif dépasse le stock ou si l'on achète plus que
  le besoin (stock actuel suffisant : « aucun achat n'est nécessaire », `preparation.comment.stock_none`) ; sinon `myPlan`.
- IA, vérification des chiffres, cache (même plan, même jour) et plafond **partagé avec G1b-2** (10 avis par jour, 429 `COMMENT_LIMIT` avec l'avis
  des règles) : comme `docs/guide-ia-gestion.md` § 9 ; consigne propre (ici, moins d'achats peut baisser le résultat).
