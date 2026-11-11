# Simulateur de période — application de gestion (lot B1b-2, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, sur `docs/propositions-pilotage.md` de l'application : « le simulateur
part des données en cours (stock restant valorisé, ventes de la même période l'an dernier, rythme de vente) et montre, quand on bouge un
budget, un coefficient ou une promotion : ventes prévues, marge, résultat, point mort, trésorerie après achats. Rien n'est enregistré tant que
le gérant ne valide pas. » L'IA (G1) commentera et proposera une répartition sans rien calculer elle-même.

Code : `Services/GestionService/GestionSimulationService`, `UseCase/GestionUseCase/GestionSimulationUseCase`,
`Controller/GestionController/GestionSimulationController` ; s'appuie sur `PeriodResultCalculator` (réalisé), `GestionBudgetService` (prévu
et réel par poste, `docs/budgets-gestion.md`), `GestionCashService::cashUntil` (trésorerie, `docs/tresorerie-gestion.md`). Textes
`translations/gestion.{fr,en}.yaml` (`simulation.caveats.*`). Aucune table. Test : `tests/Functional/Gestion/SimulationApiTest.php`.

## 1. Route

`POST /api/periodes/{id}/simulation?locale=` (`ROLE_ADMIN`) : POST pour porter les leviers, **aucune écriture** (vérifié par test).

```
{ pricePercent?: number,      // −90 à +300, 0 par défaut : coefficient global sur le prix de vente
  promotion?: { percent, scope: "all" | "products", productIds?: [] } | null,   // percent 0 à 90, après pricePercent
  advertisingDelta?: int,     // cents signés, ajoutés à la publicité de la période
  planned?: { purchases?, trip?, shipping?, advertising?, other? } }            // remplace le prévu enregistré, pour la simulation seulement
```

- Erreurs 422 par champ : `pricePercent` / `promotion.percent` `MUST_BE_NUMBER` | `OUT_OF_RANGE` ; `promotion` `MUST_BE_OBJECT` ;
  `promotion.scope` `INVALID_SCOPE` ; `promotion.productIds` `REQUIRED` (scope `products` sans liste) | `TOO_MANY` (500 au plus) ;
  `promotion.productIds[i]` `INVALID_ID` | `PRODUCT_NOT_FOUND` ; `advertisingDelta` `MUST_BE_INTEGER_CENTS` ; `planned` `MUST_BE_OBJECT` ;
  `planned.<x>` `INVALID_CATEGORY` | `MUST_BE_INTEGER_CENTS`.
- 404 `NOT_A_PERIOD` (collection manuelle) ; **409 `PERIOD_CLOSED`** (période fermée : plus rien à décider).

## 2. Réponse

```
{ period: { id, name, start, end, daysTotal, daysElapsed, daysRemaining }, currency,
  levers: { pricePercent, promotion, advertisingDelta, planned },          // leviers compris
  method: "pace" | "lastYear" | "none",
  lastYear: { revenue, grossMargin, units } | null,                        // même période l'an dernier, pour comparer
  base: SCÉNARIO, simulated: SCÉNARIO, delta: SCÉNARIO numérique,
  plannedToSave: { lines: [{ category, planned }] },                       // prêt pour PUT /api/periodes/{id}/budget
  caveats: [{ code, text }] }

SCÉNARIO = { sales: { realized, projected, total }, units: { realized, projected },
  cogs, unknownCostRevenue, grossMargin, tauxMarque,
  expenses: { byCategory: [{ category, label, amount }], total }, result,
  breakEven: { revenue, reached } | null,
  projectedSales: { units, revenue },
  stockEnd: { units, costValue, saleValue },
  cashAfterPurchases: int | null }
```

- **Réalisé** : le résultat de période existant (`docs/periodes-gestion.md` § 3), jours écoulés, sans coupure (période ouverte). **Il ne bouge
  jamais** : `pricePercent` et `promotion` ne touchent que les ventes restantes (`delta.sales.realized` vaut toujours 0).
- **Ventes restantes**, jours de demain (ou du début d'une période à venir) à la fin de la période :
  - `pace` : par variante, unités nettes vendues (ventes − retours) sur les 30 jours complets avant aujourd'hui ÷ 30 × jours restants,
    **plafonnées au stock** de la variante ;
  - `lastYear` : sans aucune vente sur 30 jours, les unités vendues l'an dernier aux mêmes jours, plafonnées au stock ;
  - `none` : ni l'un ni l'autre, ventes restantes à 0.
  - Valeur : prix moyen réellement obtenu sur la fenêtre de référence (hors taxes, net des remises, comme le résultat) × leviers. Coût :
    moyenne pondérée des lignes d'achat de l'article (règle L0) au coût connu, sinon prix d'achat de la fiche ; sinon **coût inconnu** : la
    vente compte dans `unknownCostRevenue` et reste hors des marges. Locations, abonnements et lignes libres exclus.
  - **À quantités vendues égales** : un prix ou une promotion ne change pas le nombre de ventes (remarque `SAME_QUANTITIES`).
  - Promotions datées en cours ou à venir (lot P5, `docs/promotions-gestion.md` § 6) : la part des ventes restantes d'une variante sans prix
    propre qui tombe pendant la promotion de son article est valorisée à prix moyen × (prix promotionnel ÷ prix normal) ; remarque
    `PROMOTIONS_INCLUDED { productIds }`.
- `grossMargin` = (ventes − ventes au coût inconnu) − coût des articles vendus ; `tauxMarque` = marge ÷ ventes au coût connu, en % (2
  décimales, `null` sans vente au coût connu) ; `result` = marge − `expenses.total`.
- `expenses` : postes `trip`, `shipping`, `advertising`, `other` du budget, chacun **au plus grand du prévu et du réel** à ce jour
  (`advertising` simulé + `advertisingDelta`, jamais sous 0). `purchases` n'est pas une charge du résultat.
- `breakEven.revenue` : ventes au coût connu nécessaires pour couvrir les frais = frais ÷ taux de marque (arrondi au cent supérieur) ;
  `reached` : déjà atteint par les ventes au coût connu du scénario ; `null` si la marge est nulle ou négative.
- Lot FF2 (11/11/2026, `docs/frais-fixes-gestion.md`) : chaque scénario gagne, en variante, `fixedCosts` (frais fixes de la période au
  prorata), `resultAfterFixed` = `result` − `fixedCosts` et `breakEvenAfterFixed` (même formule avec frais + frais fixes) ; remarque
  `FIXED_COSTS_INCLUDED` quand il y en a. `result` et `breakEven` ne changent pas.
- `stockEnd` : stock en vente (articles standard hors location, stock > 0) moins les ventes restantes ; `costValue` au coût courant
  (coût inconnu compté 0), `saleValue` au **prix affiché** (variante, sinon article ; TTC si le site affiche ses prix taxes comprises) ×
  leviers. Les achats encore prévus n'y sont pas (remarque `PLANNED_PURCHASES_NOT_IN_STOCK`).
- `cashAfterPurchases` (`null` sans solde saisi) : trésorerie connue à la fin de la période (`cashUntil` : solde + mouvements prévus −
  dépenses saisies + encaissements réels depuis le solde) + ventes restantes du scénario (hors taxes) − reste à acheter (prévu `purchases` −
  réel, au moins 0) − reste des frais prévus par poste (au moins 0, `advertisingDelta` compris pour le simulé).
- `delta` = simulé − base, même forme ; seuls les nombres restent (`reached` et les libellés sont omis ; `category` reste pour repérer une
  ligne) ; `null` si l'un des deux vaut `null`.
- `plannedToSave` : les cinq postes du prévu simulé (`planned` du corps, sinon le prévu enregistré) ; « valider » = l'application l'envoie au
  `PUT` du budget. `advertisingDelta` est un levier, pas un prévu : il n'y est pas ajouté. Le coefficient par article viendra avec la route
  « appliquer le coefficient » (plus tard) ; le simulateur ne modifie aucun prix.
- `caveats` : `SAME_QUANTITIES` (un levier de prix est posé), `UNKNOWN_COST`, `LAST_YEAR_USED`, `NO_SALES_PACE`, `NO_CASH_BALANCE`,
  `STALE_BALANCE`, `TAXES_EXCLUDED` (avec une trésorerie), `PLANNED_PURCHASES_NOT_IN_STOCK`.
