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

Code : `Services/GestionService/GestionRentalStatisticsService`, `UseCase/GestionUseCase/GestionRentalUseCase`,
`Controller/GestionController/GestionRentalController`. Test : `tests/Functional/Gestion/RentalStatisticsApiTest.php`.

## 2. Lots

| Lot | Contenu |
|---|---|
| **LOC1** | statistiques : revenu à la date de vente et par date de location, occupation par article et par jour de la semaine ; livré le 11/11/2026 |
| LOC2 | règles du guide RENT1 (jours pleins), RENT2 (jours creux en semaine), RENT3 (article qui ne se loue plus), mesure après application |
| LOC3 | promotion de location par article (`rental_promotion`, lue par le calcul commun boutique et caisse) : plan détaillé avant le code, feu vert du propriétaire avant la mise en ligne |
| LOC4 | changement de grille (aperçu, `SHARED_GRID` et `allowShared`, annulation 24 h) |
| LOC5 | projection des locations de la période |
