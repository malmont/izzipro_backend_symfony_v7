# L'année de l'entreprise et l'exercice du site — application de gestion (lot AN + EX, 11/11/2026)

Besoin du propriétaire : un onglet Statistiques › Année pour suivre l'exercice mois par mois et savoir, une fois l'exercice fini, si l'entreprise
est rentable et ce qui lui manque. Contrats validés par l'application (« new easymakemoney ») le 11/11/2026, dont l'exercice réglable par site
(EX, décision du propriétaire). `ROLE_ADMIN`, cents dans la devise du site, fuseau du magasin, lecture seule (sauf le cache de l'avis de l'IA).

Code : `Gestion/Services/GestionYearService` (séries, projection, rentabilité, leviers, exercice terminé, avis),
`GestionFiscalYearService` (exercice), `Gestion/UseCase/GestionYearUseCase`, `GestionFiscalYearUseCase`,
`Gestion/Controller/GestionStatisticsController` (routes `/api/gestion/statistiques/annee*`), `GestionFiscalYearController`.
Test : `tests/Functional/Gestion/YearApiTest.php`.

## 1. Exercice du site (EX)

- Réglage `entreprise.fiscal_year_start_month` (1 à 12, null = 1, année civile) : `GET /api/gestion/reglages/exercice` → `{ startMonth, label,
  current { startYear, from, to, label } }` ; `PUT { startMonth }` → même forme ; 422 `startMonth` `REQUIRED` | `MUST_BE_INTEGER` |
  `OUT_OF_RANGE` ; journal d'audit `gestion-reglages-exercice`. Pas dans le registre de la console de pilotage (décision du 11/11/2026).
- Exercice = 12 mois à partir du 1er du mois choisi, désigné par son **année de début** ; libellé « 2026 » s'il commence en janvier,
  « 2026-2027 » sinon. Changer le réglage ne réécrit rien : tout est recalculé à la lecture (instantanés de stock, périodes fermées intacts).
- S1 (`docs/statistiques-gestion.md`) : `range=year` = l'exercice en cours, du 1er du mois de début à aujourd'hui (inchangé avec janvier).
- Périodes : les trimestres de la gestion sont **saisonniers** (mars–mai, juin–août, septembre–novembre, décembre–février). Mois de début
  alignés : trimestres → mars, juin, septembre, décembre ; semestres → janvier, juillet ; mois → tous. Sinon, une période déborde : `periods[]`
  la marque `partial` (avec `overlapDays` et `days`), son résultat P2 reste entier (P2 ne se découpe pas), remarque
  `FISCAL_YEAR_NOT_ALIGNED_WITH_PERIODS` avec `alignedMonths`. Les chiffres mois par mois, les cumuls et la rentabilité, calculés au jour, sont
  justes quel que soit le découpage. Aucune modification des périodes dans ce lot.

## 2. Route de l'exercice

`GET /api/gestion/statistiques/annee?year=&pricePercent=&locale=` (exercice en cours par défaut ; `year` de 2000 à l'année de début de l'exercice
en cours, sinon 422 `INVALID_YEAR` ; `pricePercent` de 1 à 30, 5 par défaut, sinon 422 `OUT_OF_RANGE`)

```
{ currency, year, status: in_progress | finished, today, fiscalYear { startMonth, from, to, label },
  months[12], cumulative[12], lastYear { year, label, from, to, months[12], cumulative[12] } | null, periods[], totals,
  profitability { tauxMarque, projection, breakEven, gap }, levers[] | null, finished | null, caveats[] }
```

- Mois dans l'ordre de l'exercice, chacun `{ month, year, from, to, elapsed: true | partial | false, sales, grossMargin, grossMarginRate,
  otherRevenue { rentals, subscriptions, total }, variableCosts { shipping, advertising, notes, trip, total }, fixedCosts,
  resultBeforeOtherRevenue, result }` ; un mois à venir : valeurs nulles. `cumulative` : cumul depuis le premier mois de l'exercice,
  forme **plate** `{ month, sales, grossMargin, otherRevenue, variableCosts, fixedCosts, result }` où `otherRevenue` et `variableCosts` sont des
  **totaux** (nombres), pas les objets détaillés d'un mois ; un mois à venir : valeurs nulles.
- Règles (aucune deuxième) : ventes et marge par `PeriodResultCalculator::dailySales` (P2 : hors taxes, retours retirés, incomplètes et annulées
  exclues, marge au coût connu) ; frais variables à leur date comme P2 : port d'achat (date de la commande fournisseur), publicité (date de
  début), notes de frais hors voyage, notes de voyage (`trip_id`) ; les achats de stock ne sont pas une charge (ils sont dans le coût des
  ventes) ; frais fixes au prorata des jours (FF1).
- **Résultat de l'entreprise** = marge + autres revenus − frais variables − frais fixes ; `resultBeforeOtherRevenue` = marge − frais variables
  − frais fixes (l'addition se refait).
- `periods[]` : périodes de gestion qui recoupent l'exercice, `{ id, name, start, end, status, started, revenue, grossMargin, net, netAfterFixed,
  otherRevenue, frozen, partial, overlapDays, days }` lues dans le résultat P2 (instantané d'une période fermée, sans rien écrire). `net` et
  `netAfterFixed` sont ceux de P2, **sans** les autres revenus ; `otherRevenue` est donné à côté.
- `totals` : exercice écoulé (jusqu'à aujourd'hui), mêmes champs qu'un mois, plus `from`, `to`.

## 3. Rentabilité

- `tauxMarque` : marge ÷ ventes au coût connu × 100, **en %**, arrondi à 0,1, sur l'exercice écoulé.
- `projection` (exercice en cours) : réalisé + reste estimé jour par jour, `{ …mêmes champs, method, reason, months[] }`. Ventes et autres
  revenus : `lastYear` (mêmes dates l'exercice précédent, s'il y a des ventes), sinon `pace` (rythme des 30 derniers jours), sinon `none` ;
  marge restante = ventes × taux de marque ÷ 100. Frais variables, par jour : budget prévu B1a de la période qui couvre le jour (réparti sur
  ses jours), sinon le même mois l'exercice précédent s'il a des frais, sinon le rythme des 90 derniers jours. Frais fixes : FF1. Chaque
  mois projeté `{ month, from, to, …, sources { sales, variableCosts: budget | lastYear | pace } }` (source majoritaire du mois).
- **Point mort** : `breakEven.sales` = (frais variables + frais fixes − autres revenus) ÷ (`tauxMarque` ÷ 100), arrondi au cent supérieur, sur la
  projection (sur l'exercice pour un exercice terminé) ; `reached` ; null si le taux de marque est ≤ 0.
- `gap` (null si le résultat est ≥ 0) : `margin` manquante, `sales` = margin ÷ (`tauxMarque` ÷ 100), `perRemainingMonth { margin, sales,
  remainingMonths }` (mois restants = jours restants × 12 ÷ jours de l'exercice).
- Remarques : `PROJECTION_LAST_YEAR` | `PROJECTION_PACE` | `NO_PROJECTION`, `PARTIAL_MONTH`, `UNKNOWN_COST`, `FIXED_COSTS_PRORATED`,
  `PERIOD_FROZEN_BEFORE_FF1`, `FISCAL_YEAR_NOT_ALIGNED_WITH_PERIODS`.

## 4. Leviers chiffrés (exercice en cours)

`levers[]` triés par effet décroissant, chacun `{ key, label, detail, effect (cents), coversGap, … }`, libellés dans la langue demandée :
- `price` : +x % sur les ventes restantes estimées, **à quantités égales**, tout en marge ; `percent`, `requiredPercent` (hausse qui comblerait
  l'écart = gap.margin ÷ ventes restantes).
- `dormantStock` : somme des effets des conseils SLOW_STOCK (R2) et END_CLEARANCE (R9) actifs de la période en cours, sans recalcul ;
  `advices`, `productIds`.
- `fixedCostsWeight` : `weight` = frais fixes ÷ marge × 100 ; effet d'une baisse de 10 % des frais fixes restants ; `topCategories` (2).
- `worstPeriod` : période commencée au résultat le plus bas (net après frais fixes + autres revenus), sinon le mois le plus bas ; effet = ce
  qu'elle a coûté sous zéro. Il montre d'où vient l'écart. La catégorie la plus déficitaire viendra avec S2.

## 5. Exercice terminé

`finished { bilan { …, profitable }, bestMonths[3], worstMonths[3], bestPeriod, worstPeriod, hindsight { priceRequiredPercent (hausse qui aurait
donné un résultat ≥ 0 à quantités égales), fixedCostsCutRequired, salesRequired (point mort) } }`.

`POST /api/gestion/statistiques/annee/avis?year=&locale=` → `{ year, comment { title, text, lessons [2 ou 3], textSource }, ai { status },
cached }` ; exercice en cours : 409 `YEAR_NOT_FINISHED`. Règles de G1 et P6 : chiffres vérifiés (titre, texte et leçons), `cleanText`, texte
de repli des règles (avec 2 leçons), plafond **partagé** de 10 avis par jour (429 `COMMENT_LIMIT` avec l'avis des règles). Cache dans
`gestion_simulation_comment`, rangé sous une période existante de l'exercice (sans en créer ; sans période : avis des règles, `ai.status`
`rules_only`), empreinte `GestionYearService::cacheKey` préfixée « year: » avec l'exercice et son mois de début, réponse marquée `kind: year` :
jamais confondue avec un avis du simulateur (G1b-2) ou de la préparation (« prep: »), dans un sens comme dans l'autre (test).

## 6. Performance

Base de test, 40 000 commandes et 120 000 lignes sur deux exercices (générées puis annulées) : 1,2 à 1,45 s. Les deux requêtes groupées par jour
(exercice et précédent) dominent, avec le résultat P2 des périodes ouvertes. Sous le seuil convenu de 1,5 s ; au-delà, cache court à proposer.

Schéma : `entreprise.fiscal_year_start_month` (Version20261218100000, `scripts/migrate_all_v2_gestion_exercice.sh`).
