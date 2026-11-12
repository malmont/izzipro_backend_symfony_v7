# « Ma journée » — application de gestion (lot JOUR, 13/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 13/11/2026. Une route de lecture donne l'état du jour pour l'accueil de
l'application mobile et du web. **Tout est calculé ici** : les applications n'additionnent rien.

Code : `Gestion/Services/GestionDayService`, `Gestion/UseCase/GestionDayUseCase`, `Gestion/Controller/GestionDayController`.
Test : `tests/Functional/Gestion/UnifiedStatisticsApiTest::testDayOverviewMatchesStatisticsDeskAndSameHourAverage`.

## Route

`GET /api/gestion/journee` — `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion (`app.management`), lecture seule,
cents dans la devise du site, jour et heures au fuseau du magasin. Route ajoutée : aucune autre ne change.

```
{ date, timezone, currency, generatedAt,
  sales: { total, orders, byChannel: [{ channel: "ecommerce" | "pos" | "mobile_app", total, orders }],
           vsSameWeekdayAverage: { percent | null, average, weeks, asOf } },
  receipts: { total, byMethod: [{ method: "cash" | "card", total }] },
  toShip: { count, oldestAt | null },
  support: { open, overdue } | null,
  cash: { balance, balanceDate, lowestIn14Days: { amount, date }, state: "ok" | "warning" | "critical" } | null,
  stock: { lowCount },
  screens: { sales, toShip, support, cash, stock } }
```

## Règles

- **`sales`** : les règles des statistiques unifiées (`docs/statistiques-gestion.md`).
  - `total` et `orders` sont exactement les indicateurs `revenue` et `orders` de `GET /api/gestion/statistiques?range=today` : hors
    taxes, retours en négatif, commandes incomplètes et annulées exclues, cartes-cadeaux exclues.
  - `byChannel` donne toujours les trois canaux, à 0 s'il n'y a rien ; chacun égale la même route avec `source=`.
- **`vsSameWeekdayAverage`** : les ventes d'aujourd'hui comparées à la moyenne du même jour de semaine sur les 4 semaines précédentes,
  chaque jour **arrêté à la même heure** du magasin.
  - `asOf` : cette heure (`HH:MM`) ; `average` : la moyenne en cents ; `weeks` : 4.
  - `percent` = (aujourd'hui − moyenne) ÷ moyenne × 100, arrondi à 0,1 ; `null` si la moyenne vaut 0.
- **`receipts`** : l'encaissé du jour, **taxes comprises** (ce qui entre en caisse), par `GestionCashService::receiptsSplit` : `cash`
  (espèces) et `card` (le reste). À ne pas confondre avec `sales.total`, hors taxes.
- **`toShip`** : la définition de « À traiter › À expédier » d'EasyAdmin (`OrderDeskService::TO_SHIP`) : commande hors caisse, payée
  ou en préparation (statut 3 ou 4), avec une adresse de livraison, pas encore expédiée. `count` compte tout (sans le plafond de la
  liste) ; `oldestAt` est la date de la plus ancienne.
- **`support`** : `null` si le module Assistance n'est pas accordé au site. `open` : demandes non fermées ; `overdue` : parmi elles,
  celles dont l'échéance de réponse est passée. La route est réservée aux administrateurs.
- **`cash`** : `null` tant qu'aucun solde n'est saisi. Mêmes valeurs que l'alerte de trésorerie (`GestionCashService::alert`,
  `docs/tresorerie-gestion.md`).
- **`stock.lowCount`** : le nombre de la vignette « stock bas » (`HomeTilesService::lowStock`, `docs/vignettes-gestion.md`).
- **`screens`** : codes d'écran, vocabulaire des vignettes : `statistiques`, `commandes.a_expedier` (pas d'écran dans l'application
  mobile : « à faire sur le site »), `assistance`, `tresorerie`, `stock.low`.

## Vitesse

Sans cache. Mesuré sur les données de demo le 13/11/2026 : environ 45 ms par appel (96 ms au premier), pour une cible de 300 ms.
`generatedAt` date la réponse. Si un cache devenait nécessaire : 60 secondes par site, avec les étiquettes des composants lus
(commandes, trésorerie, produits, tickets), que toute écriture vide aussitôt.
