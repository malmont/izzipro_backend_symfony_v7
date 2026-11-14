# Statistiques unifiées — application de gestion (lot S1, 11/11/2026 ; sections : lot S2-a, 14/11/2026)

Refonte des Statistiques décidée avec le propriétaire, contrat validé par l'application (« new easymakemoney ») le 11/11/2026. Une route, fiable et
comparable, **une seule règle** : celle du résultat de période P2 (`docs/periodes-gestion.md`). Les anciennes routes de statistiques
(`chiffre-affaires`, `nombre-commandes`, `panier-moyen`, `carrier/statistics`, `payments/statistics`, `stock-value`, `stock-evolution`) restent
intactes : la caisse en lit certaines. `ROLE_ADMIN`, cents dans la devise du site, fuseau du magasin, lecture seule.

Code : `Gestion/Services/GestionStatisticsService` (plages, indicateurs, séries, libellés), `PeriodResultCalculator::dailySales` (agrégats
par jour du magasin, même requête que P2, filtre de source), `GestionStockSnapshotService` (valeur et instantané du stock),
`Gestion/UseCase/GestionStatisticsUseCase`, `Gestion/Controller/GestionStatisticsController`. Test :
`tests/Functional/Gestion/UnifiedStatisticsApiTest.php`. Sections (§ 6) : `Gestion/Services/GestionStatisticsSections`, test
`tests/Functional/Gestion/StatisticsSectionsApiTest.php`.

## 1. Route

`GET /api/gestion/statistiques?from=&to=|periodId=|range=today|7d|month|year&source=all|ecommerce|pos|mobile_app&locale=&sections=`

`sections` (lot S2, § 6) ajoute des sections à la réponse ; **sans lui, la réponse est celle de S1, clé pour clé**.

| Plage | Plage choisie | Plage précédente | Même plage l'an dernier |
|---|---|---|---|
| `today` | aujourd'hui | hier | même jour |
| `7d` | les 7 derniers jours, aujourd'hui compris | les 7 jours d'avant | mêmes dates |
| `month` (par défaut) | du 1er à aujourd'hui | du 1er du mois précédent au même jour (borné à sa fin : 31 mars → 28 février) | mêmes dates |
| `year` | l'exercice en cours : du 1er du mois de début (réglage du site, janvier par défaut, `docs/annee-gestion.md` § 1) à aujourd'hui | `null` (seule la comparaison à l'an dernier) | mêmes dates |
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
| **S2-a** | sections `payments` (encaissé, moyens de paiement, clôtures de caisse) et `expenses` (notes de frais, port d'achat, publicité ; frais fixes à côté) ; codé le 14/11/2026 |
| S2-b | sections `stock` (mouvements, courbe de valeur depuis le 11/11/2026), `shipping` (port facturé, par transporteur) et `taxes` (par taxe) |
| S3 | « Pourquoi ? » par l'IA, export CSV et PDF |
| S4 | analyses : articles, catégories, heures fortes, rotation du stock, remises, clients en nombres (nommé « S2 » avant le 14/11/2026) |
| à part | marge sur le port : port facturé moins coût réel des étiquettes. Le coût n'est pas enregistré par étiquette (`shipping_label` n'a pas de montant) : il faut d'abord le garder |
| AN | l'année de l'entreprise (statistiques › Année) |

## 6. Sections : les anciens écrans de statistiques dans la route unifiée (lot S2)

Décision du propriétaire du 14/11/2026 : les 5 anciens écrans de statistiques de l'application (Caisse, Paiements, Stock, Frais, Total
frais) sont remplacés par des volets de l'écran unifié. Un ancien écran n'est retiré que quand toute son information utile a une place ici.

`sections=payments,expenses` (une ou plusieurs, séparées par des virgules ; casse et espaces tolérés ; ordre de la réponse fixe). La
réponse gagne `sections { <nom>: section }`. Chaque section suit la plage, la plage précédente, l'an dernier et la langue de la demande ;
ses indicateurs ont la forme de ceux de S1 (`value`, `unit`, `previous`, `lastYear`, `changeVsPrevious`, `changeVsLastYear`,
`changeUnit`, `spark`) ; son graphique a les mêmes tranches que celui de S1. Tout est calculé par le serveur. Une valeur inconnue, ou une
section pas encore livrée (`stock`, `shipping`, `taxes` : S2-b) : 422 `VALIDATION_FAILED`, `fields.sections` = `INVALID_SECTION`.
`appliesSource` dit si le filtre `source` a un sens pour la section : l'application grise son sélecteur de canal quand il est faux.
Un nombre rond sort en entier dans le JSON (`100`, pas `100.0`), comme dans S1.

### `payments` : l'encaissé (remplace les écrans Paiements et Caisse)

```
{ indicators { received, refunded, net },
  breakdown [{ method: "card" | "cash" | "paypal" | "other", label, received, refunded, net, share, previousNet, lastYearNet }],
  cashDesk { closings, discrepancy, discrepancyCount, bankDeposits, movements { deposits, withdrawals, fundDeposits, fundWithdrawals } } | null,
  chart { granularity, points [{ key, from, to, received, refunded, net, lastYear { received, refunded, net } }] },
  caveats [{ code, text, amount? }], appliesSource: true }
```

- **Règle : celle de « Ma journée » et de la trésorerie** (`GestionCashService::receiptsByDay`). Ce qui entre, **taxes comprises**, daté
  par la **date de la commande** ; ventes, locations, abonnements et cartes-cadeaux vendues ; la part payée par carte-cadeau, avoir ou
  coupon du fabricant n'en fait pas partie ; commandes incomplètes et annulées exclues. `received` = les ventes, `refunded` = les
  retours, `net` = `received − refunded`. Sur « aujourd'hui », `net` égale `receipts.total` de « Ma journée » au cent près.
  L'ancienne route (`payments/statistics`) date par la date du paiement : ses chiffres peuvent différer d'un jour à l'autre.
- **`breakdown`** : les lignes de paiement **réussies** des mêmes commandes, par moyen (`payment_method` : 1 `card`, 2 `cash`,
  3 `paypal` ; un moyen ajouté plus tard sort en `method_<id>` avec son nom). `share` = part du net, en % ; `previousNet` et
  `lastYearNet` pour comparer. Ce que les lignes de paiement n'expliquent pas (une vente sans paiement enregistré) sort dans une
  ligne **`other`** (`received` et `refunded` à `null`), avec la remarque `PAYMENTS_UNMATCHED` et son montant : **les moyens additionnés
  font toujours le net**. « Ma journée » range en « carte » tout ce qui n'est pas en espèces : `card + paypal + other` ici.
- **`cashDesk`** : les clôtures de caisse de la plage (rapports de fermeture, `cash_register_report`, datés par leur heure de
  fermeture) : `closings` (nombre), `discrepancy` (somme des écarts compté − attendu, signée), `discrepancyCount` (clôtures avec un
  écart), `bankDeposits` (dépôts en banque), chacun avec ses comparaisons ; `movements` : mouvements de fond hors ventes de la plage
  (dépôts, retraits, ajouts et retraits de fond, le vocabulaire du rapport de caisse). Rendu pour `source=all` et `source=pos` ;
  `null` pour les autres canaux. **La liste des transactions de la caisse ouverte n'est pas reprise** : c'est un outil de caisse (rapport
  X), pas une statistique.
- Remarques : `PAYMENTS_BY_ORDER_DATE` (toujours), `PAYMENTS_UNMATCHED` (`amount`).

### `expenses` : les frais de l'entreprise (remplace l'écran Frais)

```
{ indicators { total, notes, purchaseShipping, advertising },
  breakdown [{ kind: "notes" | "purchaseShipping" | "advertising", label, amount, share }],
  notesByType [{ typeId | null, name, amount, share }], campaigns [{ id, name, amount, startDate }],
  fixedCosts { value, unit, previous, lastYear, changeVsPrevious, changeVsLastYear, changeUnit, byCategory, days, includedInTotal: false } | null,
  chart { granularity, points [{ key, from, to, total, notes, purchaseShipping, advertising, lastYear { total } }] },
  caveats [{ code, text }], appliesSource: false }
```

- **Règle : celle du résultat de période.** Notes de frais datées dans la plage ; frais de port des commandes fournisseurs datées dans
  la plage (au taux de change de la commande) ; publicité des campagnes dont la date de début est dans la plage. Les totaux viennent des
  requêtes mêmes du résultat de période (`PurchaseMetricsCalculator::expensesBetween`, `PeriodResultCalculator::advertisingBetween`) :
  **sur un `periodId`, `total` égale `expenses.total` du résultat de période au cent près**, composante par composante.
- `notesByType` : les notes par type de frais, du plus gros au plus petit, `share` en % des notes ; `campaigns` : les campagnes comptées.
- **Frais fixes : à côté, HORS du total** (`includedInTotal: false`). Ils sont répartis au prorata des jours
  (`GestionFixedCostService::between`) et ne sont déduits que dans le résultat après frais fixes. Remarques
  `FIXED_COSTS_OUTSIDE_TOTAL`, puis celles des frais fixes (`FIXED_COSTS_PRORATED`, `FIXED_COSTS_MAY_OVERLAP_NOTES`).
- Le canal ne s'applique pas (ce sont des dépenses) : avec un `source`, mêmes chiffres et remarque `SOURCE_NOT_APPLICABLE`.
- Graphique : chaque frais à son jour ; pour un port d'achat en devise, le point est arrondi (l'indicateur fait foi).
- L'ancienne route (`frais/total`) n'applique pas le taux de change au port d'achat et ne compte pas la publicité.

### Routes anciennes : gardées telles quelles

`caisse/transactions?asList=1`, `payments/statistics`, `stock-evolution`, `stock-value`, `frais/total`, `carrier/statistics`,
`taxes/monthly` ne sont pas touchées. Appelants au 14/11/2026 : l'application mobile de gestion les appelle toutes les 7 (elle passera
aux volets) ; le web de gestion n'en appelle aucune ; la caisse a le droit de lire `payments/statistics/pos`. Leur retrait est une
décision à part, route par route, quand le journal du serveur ne montre plus d'appel.
