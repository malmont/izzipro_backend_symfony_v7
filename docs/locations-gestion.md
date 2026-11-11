# Locations — application de gestion (lot LOC, 11/11/2026)

Onglet Location de l'application de gestion. Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, livré en cinq sous-lots :
LOC1 statistiques, LOC2 règles du guide, LOC3 promotion de location par article, LOC4 changement de grille, LOC5 projection. `ROLE_ADMIN`,
cents dans la devise du site, fuseau du magasin. Modèle des locations : `docs/boutique.md` (`booking_configuration`, grilles `rental_pack`
partagées par catégorie, réservations `booking` en UTC, prix figé dans la ligne de commande).

## 0. Règle du revenu (Q4, décision du 11/11/2026)

Le résultat de période (P2, `otherRevenue.rentals`) mesure **l'argent gardé** : une réservation annulée dont la commande n'est ni annulée ni
remboursée est une location non remboursable, son montant reste compté ; un remboursement passe par les retours et se déduit déjà. P2 ne change
pas. Les statistiques des locations comptent ce revenu de la même façon, mais **excluent les réservations annulées de l'occupation**.

## 1. Statistiques (LOC1)

`GET /api/gestion/locations/statistiques?periodId=|from=&to=&productIds=&locale=` (sans plage : la période en cours, sinon les 30 derniers jours ;
366 jours au plus ; `productIds` séparés par des virgules)

```
{ currency, range { type: period | custom | last30, from, to, days }, revenue, revenueByRentalDate, rentals, cancellations,
  products [{ productId, name, granularity, unit: hours | days, capacity, openingHours, hoursPerDay, occupancy, bookedTime, offeredTime,
              revenue, revenueByRentalDate, rentals, averageDuration, cancellations }],
  weekdays [{ weekday (1 lundi … 7 dimanche), label, occupancy, rentals }], highDays [], lowDays [], thresholds { peak, low }, caveats [] }
```

- `revenue` : **à la date de vente**, exactement `otherRevenue.rentals` de P2 (lignes avec réservation, hors taxes, commandes incomplètes et
  annulées exclues, retours déduits). Par article : la même règle, groupée par article réservé.
- `revenueByRentalDate` : le montant de chaque ligne réparti au prorata du temps loué dans la plage (une location qui déborde n'apporte que sa part).
- Occupation = temps loué ÷ temps offert × 100, plafonnée à 100 % (`OCCUPANCY_CAPPED` si une location déborde des heures offertes), réservations
  annulées exclues. Unité : granularité `days` → **jours** (temps offert = capacité × jours ; une location de N × 24 h occupe ses N premiers
  jours : 10 h → 10 h le lendemain = 1 jour) ; sinon → **heures** (capacité × heures d'ouverture par jour ; sans heures d'ouverture, 24 h et
  remarque `NO_OPENING_HOURS` avec ses articles). Capacité = `booking_configuration.stock_quantity` (par article, pas par variante).
- `rentals`, `averageDuration` (dans l'unité de l'article) : locations commencées dans la plage, hors annulées ; `cancellations` : annulées
  commencées dans la plage.
- Par jour de la semaine : moyenne des occupations des articles ce jour-là (les unités diffèrent) ; `rentals` : locations commencées ce jour-là.
  `highDays` : occupation ≥ 80 % ; `lowDays` : < 15 % (seuils des règles RENT1 et RENT2, LOC2).
- Remarques : `REVENUE_AT_SALE_DATE`, `CANCELLED_EXCLUDED_FROM_OCCUPANCY`, `NO_OPENING_HOURS`, `OCCUPANCY_CAPPED`.
- Erreurs : 422 `from` / `to` `REQUIRED` | `INVALID_DATE`, `to` `BEFORE_START` | `RANGE_TOO_LONG`, `productIds` `INVALID_ID` ; 404 `PERIOD_NOT_FOUND`.

Code : `Gestion/Services/GestionRentalStatisticsService`, `Gestion/UseCase/GestionRentalUseCase`,
`Gestion/Controller/GestionRentalController`. Test : `tests/Functional/Gestion/RentalStatisticsApiTest.php`.

## 2. Règles du guide (LOC2)

Dans `AdviceRules` (constantes `RENT_*`), calculées sur les statistiques de LOC1 (occupation hors annulées, revenu par date de location), aux
moments `selling` et `end` de la période ; `docs/guide-ia-gestion.md` § 2. Écran d'action : `locations` `{ productId, weekdays?, percent? }`.
- RENT1 `RENTAL_PEAK_DAYS` : sur les 8 dernières semaines, un jour de la semaine occupé à **80 %** ou plus avec au moins **4** locations ce
  jour-là → hausse ciblée de **10 %** ces jours-là. Effet = temps loué moyen par semaine ces jours × nombre de ces jours restants dans la période ×
  tarif obtenu (revenu par date de location ÷ temps loué) × 10 % ; confiance medium. `params.weekdays` (1 lundi … 7 dimanche).
- RENT2 `RENTAL_LOW_WEEKDAYS` : sur 30 jours, article occupé sous **25 %** et du lundi au jeudi sous **15 %** → promotion de **20 %** en semaine.
  Seulement pour un article loué au moins une fois sur la fenêtre (un article jamais loué relève de RENT3). Effet (potentiel) = capacité libre du
  lundi au jeudi restants × tarif obtenu × 80 % ; confiance low. Au plus 3 conseils par règle.
- RENT3 `RENTAL_IDLE` : aucune location depuis **60** jours, article louable depuis plus de 60 jours → baisse du tarif ou mise en avant. Effet =
  revenu de location de l'article sur la même fenêtre l'an dernier (par date de location), sinon 0 ; confiance low.
- Mesure après application (G1b-1, `AdviceMeasure::RENTAL_KINDS`) : revenu de location de l'article **par date de location**, 14 jours avant et
  après ; vignette « advice-result » comprise.
- Test : `tests/Functional/Gestion/RentalAdviceTest.php`.

## 3. Promotion de location par article (LOC3)

Plan validé par l'application le 11/11/2026 ; **mis en ligne le 11/11/2026 sur feu vert du propriétaire** (91c1b09e, procédure du § 3.4).

### 3.1 Règle du prix

- Table `rental_promotion` (article, `percent`, jours du magasin `start_date` … `end_date` inclus et leurs bornes UTC `starts_at` … `ends_at`
  exclusive, `weekdays`, lot) et journal `rental_promotion_batch`. Lue par `Services/Booking/RentalPromotionService::activeFor()` dans
  `CartQuoteCalculator::rental()`, le seul calcul du prix d'une location : devis de la boutique (`/api/cart/quote`), paiement Stripe
  (`PrepareCheckoutUseCase`), devis de la caisse (`/api/pos/quote`), lignes de commande web et caisse (prix figé).
- Une location reçoit la promotion si son **jour de début**, lu au fuseau de la boutique (`BookingClock`), est dans la fenêtre et coché
  (un départ à 22 h le vendredi est un vendredi, même si c'est samedi en UTC). Remise = `round(rate × units × percent / 100)`, jamais sur le
  supplément passagers ni la caution. Sans promotion qui s'applique : ligne strictement identique (aucune clé de plus).
- Ligne du devis : `booking.promotion { id, percent, amount, catalogUnitPrice }`. Fiche de l'article (`ProductCommerceDto`, donc boutique,
  catalogue de la caisse `/api/pos/rentals`, applications) : `rental.promotion { percent, startDate, endDate, startsAt, endsAt, weekdays }`, la
  promotion en cours sinon la prochaine, clé absente sinon ; depuis le 11/11/2026, `rental.promotions` = toutes les promotions en cours et à
  venir (même forme, triées par début puis création ; plusieurs coexistent sur des jours distincts ou se suivent), `rental.promotion` restant
  la première ; clés absentes sans promotion. Créer ou terminer une promotion vide le cache de l'article (`CacheTags`).
- Remises de caisse F1 : elles ne s'appliquent pas aux lignes de location (règle existante) ; sur un ticket mixte, la location garde son prix
  promotionnel et la remise F1 porte sur les articles vendus.

### 3.2 Routes de gestion (`ROLE_ADMIN`)

- `POST /api/gestion/locations/promotions/apercu { productIds | adviceId, percent (5 à 90, entier), startDate? (aujourd'hui par défaut),
  endDate (90 jours au plus), weekdays? (1 à 7, tous par défaut) }` → `{ percent, startDate, endDate, days, weekdays, adviceId, items [{ productId,
  name, status: ok | NOT_RENTABLE | PROMOTION_OVERLAP, conflicts [], example { durationType, rate, discounted } | null }], counts { ok,
  notRentable, overlap }, warnings [BIG_DISCOUNT au-delà de 50 %], previewHash }`. Aucune écriture. Avec `adviceId` (conseil RENT1 à RENT3) :
  articles, pourcentage et jours du conseil par défaut.
- `POST …/appliquer { …même corps…, previewHash }` → 201 `{ batch, advice }` ; tout ou rien : 409 `PRICES_CHANGED { preview }`, 409
  `PROMOTION_OVERLAP { preview }` (même article, fenêtres et jours cochés qui se recoupent), 422 `NOT_RENTABLE { preview }` ; conseil du guide
  marqué fait (date de début).
- `POST …/terminer { batchId }` → le lot : une promotion pas encore commencée ne s'appliquera jamais (`status: cancelled`), une promotion en
  cours s'arrête à la fin de la journée du magasin (une location qui commence aujourd'hui la garde) ; 404 `BATCH_NOT_FOUND`, 409 `ALREADY_ENDED`.
  Les commandes déjà passées ne changent jamais. Depuis la correction du 11/11/2026, `endDate` suit `endsAt` (invariant : `endsAt` = minuit du
  magasin le lendemain de `endDate`) : terminée en cours → `endDate` = aujourd'hui ; annulée avant son début → plage vide `startsAt = endsAt`,
  `endDate` = `startDate` − 1 jour. Le lot garde ses dates prévues (historique) avec `endedAt`. Rattrapage des lignes déjà terminées : `end_date` =
  jour du magasin de `ends_at` − 1 (deux lignes sur demo, aucune ailleurs).
- `GET /api/gestion/locations/promotions` (en cours et à venir) ; `GET …/promotions/lots?limit=` ; chaque promotion `{ id, batchId, productId,
  name, percent, startDate, endDate, startsAt, endsAt, weekdays, status: upcoming | active | ended | cancelled, endedAt }`.
- Erreurs 422 : `percent` `REQUIRED` | `MUST_BE_INTEGER` | `OUT_OF_RANGE`, `startDate` `INVALID_DATE` | `DATE_IN_PAST`, `endDate` `REQUIRED` |
  `INVALID_DATE` | `BEFORE_START` | `TOO_LONG`, `weekdays` `INVALID_WEEKDAYS`, `productIds` `SCOPE_REQUIRED` | `REQUIRED` | `TOO_MANY` (50) |
  `INVALID_ID` | `NOT_FOUND`, `adviceId` `INVALID_ID` | `NOT_A_RENTAL_ADVICE`.

### 3.3 Tests

`tests/Functional/Gestion/RentalPromotionApiTest.php` : devis boutique et caisse identiques (tableau entier) quand une promotion ne s'applique
pas ; remise et clés ; jour de début au fuseau (départ à 22 h) ; promotion qui commence plus tard ; paiement Stripe ; vente en caisse figée ;
ticket mixte avec remise F1 au centime ; aperçu sans écriture, empreinte, chevauchement, non louable, fin, lots, fiche.

### 3.4 Mise en ligne et retour arrière

Mise en ligne (sur feu vert du propriétaire) : 1) `scripts/migrate_all_v2_gestion_promotions_location.sh` (toutes les bases ; tables vides,
sans effet tant qu'aucune promotion n'est créée) ; 2) copie des fichiers du lot dans l'arbre vivant puis `cache:clear` et `lint:container`
(dans la même minute que l'étape 1) ; 3) contrôle : devis d'une location de demo identique avant / après, routes en 401 sans jeton.
Retour arrière : terminer les lots créés (`…/terminer`) puis remettre les fichiers du commit précédent et `cache:clear` ; les tables peuvent
rester (inertes sans le code) ou être supprimées (`down` de la migration). Les commandes déjà passées gardent leur prix.

## 4. Changement de grille (LOC4)

Une grille (`rental_pack`) est rattachée à des **catégories** : la changer change le prix de tous les articles louables de ces catégories. Les
réservations déjà faites gardent leur prix (figé dans la ligne de commande). Pour un seul article sur une grille partagée : une promotion de
location par article (LOC3). Code `Gestion/Services/GestionRentalGridService` ; test `tests/Functional/Gestion/RentalGridApiTest.php`.

- `GET /api/gestion/locations/grilles` → `[{ id, name, rates { hour, halfDay, day, week, month } (cents, null = pas de tarif), products [{ productId,
  name }] }]` (articles louables des catégories de la grille).
- `POST /api/gestion/locations/grilles/apercu { rentalPackId, rates { hour?, halfDay?, day?, week?, month? } | percent (−50 à 200, entier, non nul),
  rounding? (none | dollar) }` → `{ pack { id, name }, before, after, changes [{ type, before, after, percent }], products, shared, warnings
  [SHARED_GRID, BIG_CHANGE au-delà de 30 %], previewHash }`. **Aucune écriture.** `rates` : seuls les tarifs donnés changent (`null` retire un
  tarif) ; `percent` : tous les tarifs existants, arrondis au cent ou au dollar.
- `POST …/grilles/appliquer { …même corps…, previewHash, allowShared?, adviceId? }` → 201 le changement. 409 `PRICES_CHANGED { preview }` ;
  **409 `SHARED_GRID { products, preview }`** si la grille sert à plus d'un article et que `allowShared` n'est pas `true` ; 422 `NOTHING_TO_CHANGE`.
  Écriture par l'entité (comme le questionnaire `/admin/location/configurer`) : cache du site vidé, journal d'audit `rental_pack` (`source:
  gestion`) ; anciens tarifs gardés dans `gestion_rental_grid_change`.
- `POST …/grilles/annuler { changeId }` → le changement (`undoneAt`) : dans les **24 h** (`undoUntil`) et si la grille n'a pas été retouchée
  depuis ; 404 `CHANGE_NOT_FOUND`, 409 `ALREADY_UNDONE` | `UNDO_EXPIRED` | `GRID_EDITED_SINCE { current }`.
- `GET …/grilles/modifications?limit=` → `[{ id, pack, before, after, productIds, adviceId, createdBy, createdAt, undoUntil, undoneAt, undoneBy }]`.
- Erreurs 422 : `rentalPackId` `REQUIRED` | `INVALID_ID`, `rates` `RATES_OR_PERCENT` | `MUST_BE_OBJECT` | `AT_LEAST_ONE_RATE`, `rates.<type>`
  `UNKNOWN_RATE` | `MUST_BE_POSITIVE_INTEGER_CENTS`, `percent` `MUST_BE_INTEGER` | `OUT_OF_RANGE`, `rounding` `INVALID_ROUNDING` ; 404 `PACK_NOT_FOUND`.
- Schéma : `gestion_rental_grid_change` (Version20261220100000, `scripts/migrate_all_v2_gestion_grilles_location.sh`).

## 5. Lots

| Lot | Contenu |
|---|---|
| **LOC1** | statistiques : revenu à la date de vente et par date de location, occupation par article et par jour de la semaine ; livré le 11/11/2026 |
| **LOC2** | règles du guide RENT1 (jours pleins), RENT2 (jours creux en semaine), RENT3 (article qui ne se loue plus), mesure après application ; livré le 11/11/2026 |
| **LOC3** | promotion de location par article (§ 3) : mise en ligne le 11/11/2026 sur feu vert du propriétaire (91c1b09e) |
| **LOC4** | changement de grille (§ 4) : aperçu, `SHARED_GRID` et `allowShared`, annulation 24 h ; livré le 11/11/2026 |
| LOC5 | projection des locations de la période |
