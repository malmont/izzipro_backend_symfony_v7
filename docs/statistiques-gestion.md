# Statistiques unifiées — application de gestion (lot S1, 11/11/2026)

Refonte des Statistiques décidée avec le propriétaire, contrat validé par l'application (« new easymakemoney ») le 11/11/2026. Une route, fiable et
comparable, **une seule règle** : celle du résultat de période P2 (`docs/periodes-gestion.md`). Les anciennes routes de statistiques
(`chiffre-affaires`, `nombre-commandes`, `panier-moyen`, `carrier/statistics`, `payments/statistics`, `stock-value`, `stock-evolution`) restent
intactes : la caisse en lit certaines. `ROLE_ADMIN`, cents dans la devise du site, fuseau du magasin, lecture seule.

Code : `Services/GestionService/GestionStatisticsService` (plages, indicateurs, séries, libellés), `PeriodResultCalculator::dailySales` (agrégats
par jour du magasin, même requête que P2, filtre de source), `GestionStockSnapshotService` (valeur et instantané du stock),
`UseCase/GestionUseCase/GestionStatisticsUseCase`, `Controller/GestionController/GestionStatisticsController`. Test :
`tests/Functional/Gestion/UnifiedStatisticsApiTest.php`.

## 1. Route

`GET /api/gestion/statistiques?from=&to=|periodId=|range=today|7d|month|year&source=all|ecommerce|pos|mobile_app&locale=`

| Plage | Plage choisie | Plage précédente | Même plage l'an dernier |
|---|---|---|---|
| `today` | aujourd'hui | hier | même jour |
| `7d` | les 7 derniers jours, aujourd'hui compris | les 7 jours d'avant | mêmes dates |
| `month` (par défaut) | du 1er à aujourd'hui | du 1er du mois précédent au même jour (borné à sa fin : 31 mars → 28 février) | mêmes dates |
| `year` | du 1er janvier à aujourd'hui | `null` (seule la comparaison à l'an dernier) | mêmes dates |
| `from` & `to` (366 jours au plus), `periodId` | les dates | même durée juste avant | mêmes dates |

L'an dernier : mêmes dates décalées d'un an, un 29 février devient le 28. Erreurs 422 `VALIDATION_FAILED` : `range` `INVALID_RANGE`, `source`
`INVALID_SOURCE`, `from` / `to` `REQUIRED` | `INVALID_DATE`, `to` `BEFORE_START` | `RANGE_TOO_LONG`, `periodId` `RANGE_TOO_LONG` ; 404 `PERIOD_NOT_FOUND`.

## 2. Réponse

```
{ currency, source, range { type, from, to, days, granularity }, previous { from, to } | null, lastYear { from, to },
  comparison { currentLabel, previousLabel | null, lastYearLabel },          // « 1-11 oct. », « 1-11 nov. 2025 » ; en anglais « Oct 1-11 »
  indicators { revenue, grossMargin, grossMarginRate, orders, averageBasket, returnRate, stockValue },
  otherRevenue { rentals, subscriptions },
  chart { granularity, points [{ key, from, to, revenue, grossMargin, orders, lastYear { revenue, grossMargin, orders } }] },
  caveats [{ code, text, amount? }] }
```

Chaque indicateur : `{ value, unit: money | count | percent, previous, lastYear, changeVsPrevious, changeVsLastYear, changeUnit, spark }`.
- `change` = (valeur − comparaison) ÷ |comparaison| × 100, arrondi à 0,1, `changeUnit` `percent` ; pour un taux (`grossMarginRate`,
  `returnRate`) : écart en **points** (`changeUnit` `points`) ; `null` quand la comparaison manque ou vaut 0 (pourcentage).
- `spark` : les valeurs des points du graphique pour l'indicateur (petite courbe) ; absent pour les autres revenus.

Définitions (règles de P2 : articles hors taxes, prix TTC ramenés au hors taxes, remises dans le prix de ligne, commandes incomplètes et annulées
exclues, cartes-cadeaux exclues) :
- `revenue` : ventes d'articles nettes des retours.
- `grossMargin` : marge des ventes au coût connu (coût moyen des lignes d'achat à la date de la vente, sinon prix d'achat) ; le chiffre
  d'affaires au coût inconnu reste hors marge (`UNKNOWN_COST` avec son montant). `grossMarginRate` : taux de marque = marge ÷ ventes au coût connu.
- `orders` : ventes avec au moins un article (retours non comptés).
- `averageBasket` : **ventes brutes hors taxes ÷ ventes**, retours exclus du numérateur (un retour ne fait pas baisser le panier du jour).
- `returnRate` : **montant** des retours ÷ ventes brutes × 100 ; `returns { amount, orders }` à côté.
- `stockValue` : valeur de vente du stock **aujourd'hui** (règle de `stock_value_cents`, B1, par variante) et `costValue` au coût connu ;
  `previous` et `lastYear` lus dans les instantanés quotidiens (§ 4) à la date de fin de chaque plage, `null` tant qu'il n'y en a pas
  (`STOCK_VALUE_NOW_ONLY`). `snapshots { previous, lastYear }` donne les instantanés utilisés.
- `otherRevenue.rentals` et `.subscriptions` : locations et abonnements comme P2, avec plage précédente et an dernier, sans courbe.

Graphique : granularité `day` jusqu'à 62 jours, `week` (lundi à dimanche du magasin, clé ISO `2026-W45`, bornée à la plage) jusqu'à 190, `month`
(clé `2026-11`) au-delà ; points vides à 0 ; la courbe s'arrête à aujourd'hui ; chaque point porte l'an dernier sur les mêmes dates décalées.

Remarques : `UNKNOWN_COST` (`amount`), `STOCK_VALUE_NOW_ONLY`, `PARTIAL_DAY` (la plage contient aujourd'hui), `NO_LAST_YEAR_DATA`.

## 3. Performance

Trois requêtes groupées par jour du magasin (plage, précédente, an dernier) et la valeur du stock ; pas de cache. Mesure du 11/11/2026 sur la
base de test (366 jours, `source=all`, 20 000 commandes et 60 000 lignes générées puis annulées) : 0,48 à 0,52 s ; plan : parcours des
commandes par index, puis lignes, réservation, variante, article et coût moyen L0 par ligne (environ la moitié du temps, même calcul que P2).
Seuil convenu : au-delà de 1,5 s, cache court (5 min, par site et par plage) à proposer avant livraison.

## 4. Instantané quotidien du stock

`gestion_stock_snapshot (snapshot_date, sale_value_cents, cost_value_cents)` : un par jour du magasin, pris par le passage quotidien de 10 h
(`app:gestion:notifications:envoyer`, timer `gestion-notifications` déjà en place depuis le lot N1), jamais remplacé (rejouer le passage ne
change rien) ; une erreur n'empêche jamais les notifications. Il permettra de comparer la valeur du stock dans le temps sans reconstruction
par les mouvements. Schéma : Version20261217100000, `scripts/migrate_all_v2_gestion_stock_snapshot.sh`.

## 5. Lots

| Lot | Contenu |
|---|---|
| **S1** | route unifiée, plages et comparaisons, séries, valeur du stock et instantané quotidien ; livré le 11/11/2026 |
| S2 | analyses : articles, catégories, heures fortes, rotation du stock, moyens de paiement et remises, clients en nombres |
| S3 | « Pourquoi ? » par l'IA, export CSV et PDF |
| AN | l'année de l'entreprise (statistiques › Année) |
