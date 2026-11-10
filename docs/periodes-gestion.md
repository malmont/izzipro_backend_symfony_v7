# Périodes de gestion : cadrage backend (10/11/2026)

Demande de l'application de gestion (session « new easymakemoney », règles validées par le propriétaire le 09/11/2026 et
précisées le 10/11/2026). « Collection » devient « Période » à l'affichage seulement : les routes `collections` gardent leur
nom, les collections existantes (dites **manuelles**) restent telles quelles, tout est **additif**, aucune autre application
n'est touchée. Contrat envoyé à l'application avant le code de chaque lot (`docs/gestion-mise-a-niveau.md` § 7 pour le reste).

## 1. Décisions du propriétaire (10/11/2026)

1. **Trimestres par défaut** : printemps 01/03–31/05, été 01/06–31/08, automne 01/09–30/11, hiver 01/12–fin février ;
   réglable par site (2 semestres, ou par mois).
2. **Périodes et collections manuelles coexistent**, mais leurs totaux **ne s'additionnent jamais** : une commande peut être
   dans une collection manuelle et comptée dans la période de sa date, à condition qu'aucun total ne mélange les deux.
3. **Locations et abonnements** : reconnus **à la date de la vente**, sans étalement, mais **exclus du résultat de période**,
   qui ne compte que les ventes d'articles ; renvoyés **à part** (« autres revenus », affichage discret, bilan sur le web) ;
   le coût de l'article loué reste un investissement (correction du propriétaire, 10/11/2026).
4. **Clôture au premier accès après l'échéance, sans tâche planifiée** : l'état fermé se calcule d'après la date ; **toute
   écriture datée dans une période clôturée est refusée (409)**, commandes, frais de port, notes, rattachements et
   détachements compris, même si personne n'a encore ouvert l'application ; réouverture manuelle seulement.
5. **Commande classée par sa date**, sans choix de collection à la création (`POST /api/commandes`).
6. **Rattachement tardif** d'un article déjà vendu dans une période clôturée : les chiffres figés ne changent pas ; le coût ne
   compte que pour les ventes suivantes.
7. **Troisième étage, plus tard, facultatif** : résultat après frais fixes du trimestre (à cadrer quand le propriétaire le demandera).

## 1 bis. Revue de l'application (10/11/2026), retenue dans le contrat P1

- 409 `PERIOD_CLOSED { periodId, periodKey }` sur toutes les écritures datées dans une période fermée (`PeriodGuard`) ;
  `DELETE /api/commandes/{id}` passe d'API Platform à une route dédiée (même chemin, 204) pour porter la garde.
- `POST /api/commandes` et `POST /api/notes-de-frais` sans identifiant : classement par la date, période créée si manquante.
- `DELETE /api/collections/{id}` sur une période → 409 `PERIOD_NOT_DELETABLE` ; `PATCH /api/periodes/{id} { budget }` et
  `PATCH /api/collections/{id} { budget, nomCollection }` → 200 ; 409 si fermée.
- Aucun trou : à la lecture, toutes les périodes manquantes sont créées depuis la plus ancienne activité du site (24 mois au plus).
- Index unique sur `period_key` (périodes) et création idempotente (`INSERT … ON CONFLICT DO NOTHING`), test de double création.
- Changement de fréquence effectif à la fin de la période en cours, sans chevauchement.
- Jours civils et échéance (fin + 15 jours) dans le fuseau du site (`StoreClock`). `GET /api/collections` n'est consommée que par
  l'application de gestion.

## 2. Modèle

- Une **période** est une ligne de `collections` avec `kind = 'period'` (colonnes additives : `kind` `manual` | `period`,
  `period_key` `2026-Q2` | `2026-S1` | `2026-M07`, `reopened_at`, `closed_at`). Dates : `start_date_collection` /
  `end_date_collection` (jours civils du magasin, `StoreClock`), budget facultatif (0 par défaut, modifiable), propriétaire : premier
  administrateur du site. Noms : « Printemps 2026 », « Été 2026 », « Automne 2026 », « Hiver 2026-2027 » ; « Semestre 1 2026 » ;
  « Juillet 2026 ».
- **Calendrier par site** : réglage `gestion.periods` du registre du site (`FeatureCatalog`, stockage
  `entreprise.purchasePeriodFrequency`, `quarterly` (défaut) | `semester` | `monthly`), modifiable par `PUT /api/site/features`
  et la fiche Entreprise d'EasyAdmin. Un changement de fréquence vaut pour les périodes **à venir** ; les périodes existantes
  gardent leurs dates.
- **Création automatique à la lecture** (`GET /api/collections`, `GET /api/periodes`, `GET /api/periodes/courante`, tableaux
  de bord) : la période courante et la précédente sont créées si elles manquent (`PeriodCalendar`, idempotent, par
  `period_key`). Aucune tâche planifiée.
- **État d'une période** (calculé) : `open` tant que `aujourd'hui ≤ fin + 15 jours` ou après une réouverture manuelle ;
  `closed` sinon (`closedAt` = fin + 15 jours, ou la date de la clôture manuelle). `Collections::isEffectivelyClosed()` remplace
  `getIsClosed()` dans toutes les gardes d'écriture (commandes, produits, frais de port, notes de frais, rattachement).
- **Classement par date**, à la lecture, jamais par copie : une commande fournisseur appartient à la période qui contient sa
  `date` ; une note de frais à celle de sa `date` ; un frais de port suit sa commande ; une vente (commande client) à celle de
  son `order_date` (fuseau du magasin). Une vente après la fin d'une période compte dans la suivante. Une commande ou une
  note dont la date tombe dans une période fermée est refusée : 409 `PERIOD_CLOSED { periodId, periodKey }`.
  `POST /api/collections/{id}/commandes` sur une période rattache aussi explicitement (`collections_id`) ; sur une
  collection manuelle, la commande reste comptée dans la période de sa date (décision 2).
- **Chiffres figés** : à la première lecture d'une période fermée, ses statistiques (`collection_statistiques`, et
  `commande_statistiques` de ses commandes) sont calculées et enregistrées ; les lectures suivantes servent le figé.
  **Réouverture manuelle** : `POST /api/dashboard/collection/{id}/reopen` (ROLE_ADMIN) → `reopened_at`, le figé est gardé en
  historique, la période redevient ouverte jusqu'à une clôture manuelle (`close`) ; la clôture automatique ne la referme pas.

## 3. Tableaux de bord et résultat

- **Collection manuelle** : inchangé (ses commandes par `collections_id`).
- **Période** : `GET /api/dashboard/collection/{id}` calcule **par date** (commandes datées dans la période, notes datées dans
  la période), mêmes clés qu'aujourd'hui.
- **Combiné** `GET /api/dashboard/collections-combined?kind=manual|period` : `manual` par défaut (comportement actuel sur les
  collections manuelles seulement) ; `period` = somme des périodes. Jamais les deux ensemble (décision 2).
- **Résultat de période** `GET /api/periodes/{id}/resultat` (alias `GET /api/collections/{id}/resultat` ; 404 `NOT_A_PERIOD`
  pour une collection manuelle), devise du site, cents entiers, taux en % à 2 décimales ou `null` sans dénominateur (contrat P2
  validé par l'application le 10/11/2026, cinq corrections intégrées) :

```
{
  period: { id, name, periodKey, start, end, status, closedAt, reopenedAt },
  currency, frozen, frozenAt,
  sales:        { revenue, orders, items, returns: { amount, orders, items }, unknownCostRevenue, shippingBilled,
                  bySource: { ecommerce, pos, mobile_app } },
  otherRevenue: { rentals, subscriptions, total },
  cogs:         { amount, items, unknownCostItems },
  expenses:     { shipping, notes, other: [], total },
  result:       { gross, net, tauxMarge, tauxMarque, netRate },
  investment:   { purchases, shipping, total }
}
```

  - `sales` : commandes clients datées dans la période (`order_date` au fuseau du magasin), types vente (1) et retour (2) ;
    cartes-cadeaux (type 3), commandes incomplètes (statut 1) et annulées (statut 7) exclues. **Ventes d'articles seulement** :
    les lignes de location (réservation liée) et les commandes d'abonnement (`order.subscription_id`) sont exclues de
    `revenue` et de `cogs` et renvoyées dans `otherRevenue`, hors `result` (décision 3 corrigée). `revenue` = ventes − retours,
    **hors taxes** (lignes TTC ramenées au prorata de la taxe de la commande) et **nettes des remises** (les lignes sont
    enregistrées nettes ; le guide IA s'appuiera sur cette valeur). `returns` détaille les retours ; un retour compte dans la
    période de sa date, même pour une vente d'une période précédente. `unknownCostRevenue` = part de `revenue` dont le coût est
    inconnu. `shippingBilled` = livraison facturée au client, hors `revenue`. `orders` = commandes de vente avec au moins une
    ligne d'article.
  - `cogs` (coût des articles vendus) : Σ quantité × coût unitaire des lignes au coût connu ; **un retour retire son coût au même
    coût unitaire** (l'article revient en stock). Depuis le lot L0 (`docs/lignes-achat-gestion.md` § 4), le coût unitaire est la moyenne
    pondérée des lignes d'achat de l'article dont la commande est datée au plus tard le jour de la vente (coupure inchangée). Coût unitaire = coût de la commande fournisseur du produit ramené par son taux
    (G6) si le rattachement est antérieur à la **coupure** (ci-dessous), sinon `purchase_price` du produit, **sinon coût
    inconnu** : la ligne est comptée dans `unknownCostItems` (articles vendus) et exclue de `cogs`, jamais valorisée au prix de
    vente. `items` = articles vendus au coût connu.
  - `expenses` : frais de port des commandes fournisseurs datées dans la période + notes de frais datées dans la période ;
    `other` est extensible (`{ kind, label, amount }`) : depuis le lot A1, `{ kind: "advertising", label: "Publicité", amount, campaigns }`
    (campagnes classées à leur date de début, `docs/publicite-gestion.md`) ; vide sinon ; les frais fixes viendront de la même façon.
  - `result` : les ventes au coût inconnu sont **exclues des marges** (décision du propriétaire) : `gross` = (revenue −
    unknownCostRevenue) − cogs ; `net` = gross − expenses.total ; `tauxMarge` = gross / cogs ; `tauxMarque` = gross / (revenue −
    unknownCostRevenue) ; `netRate` = net / (revenue − unknownCostRevenue) ; `null` quand le dénominateur est nul (l'application
    affiche un tiret ; 0 % et « pas de donnée » ne disent pas la même chose).
  - `investment` : commandes fournisseurs datées dans la période (coût × quantité × taux) + leurs frais de port ; informatif,
    ce n'est pas une charge du résultat (le coût de l'article n'entre qu'à la vente).
  - **Figé à la première lecture** d'une période fermée (`period_result`, `frozen: true`, `frozenAt`) ; **coupure des coûts** :
    les rattachements horodatés (`product.commande_attached_at`, posé par `rattacher` et par la création d'un produit dans une
    commande) postérieurs à la fermeture effective (clôture manuelle, sinon échéance = fin + 15 jours) sont ignorés, quelle que
    soit la date de la première lecture ; le coût d'un rattachement tardif ne compte que pour les ventes suivantes (décision 6).
    Une période **rouverte** se calcule sans coupure (les rattachements faits pendant la réouverture comptent), son résultat
    figé est effacé, et sa nouvelle clôture devient la coupure. Période ouverte : calcul à chaque lecture, cache 60 s invalidé
    par toute écriture (commandes clients, commandes fournisseurs, notes, produits, collection).
  - Combiné annuel `GET /api/periodes/resultats?from=&to=` : plus tard, après P3 (usage web).

## 4. Reprise d'historique (site qui arrive après coup)

Contrat P3 validé par l'application le 10/11/2026 (cinq corrections intégrées), **livré le 10/11/2026**. Un site qui arrive avec
des ventes mais sans commandes fournisseurs donne un coût aux articles déjà vendus, pour que les résultats des périodes passées
(§ 3) aient un coût des articles vendus. Routes `ROLE_ADMIN`, devise du site, cents (`Controller/GestionController/RepriseController`,
`UseCase/GestionUseCase/RepriseHistoriqueUseCase`, `Services/GestionService/RepriseHistoriqueService`).

- **Créneau** : périodes **fermées**, du début de la période contenant (aujourd'hui − `months` mois) au début de la période
  courante ; `months` 1..24 (profondeur du calendrier), défaut 12.
- **Articles vendus** : lignes d'articles des commandes clients du créneau (mêmes règles que le résultat : ventes et retours,
  statuts incomplète / annulée et cartes-cadeaux exclus, locations et abonnements exclus), quantité nette > 0, classés par
  chiffre d'affaires hors taxes décroissant ; `firstPeriodKey` = période de la première vente.
- **Coût par article, dans l'ordre** : `costs` du corps, puis `purchase_price` de la fiche (rattaché automatiquement, sans
  l'envoyer dans `costs`), puis estimation `prix de vente / estimateCoefficient` (marquée estimée, `costSource: "estimated"`,
  `product.commande_cost_estimated`), sinon coût inconnu (hors reprise). Un article déjà rattaché à une commande fournisseur
  n'est jamais touché ni remplacé : `skipped: [{ productId, code: "ALREADY_ATTACHED" }]` quand il figure dans `costs`.
  Les articles jamais vendus restent hors reprise (« À classer », lot à cadrer plus tard).
- `POST /api/gestion/reprise/simulation { months?, estimateCoefficient?, page?, limit? }` → rien n'est écrit :
  `{ from, to, months, estimateCoefficient, periods: [{ periodKey, name, start, end, exists, status }], sales: { revenue, orders,
  items, unknownCostItems }, products: [{ productId, name, code, quantitySold, revenue, firstSoldAt, firstPeriodKey, costKnown,
  costSource: "commande" | "purchasePrice" | "estimated" | null, unitCost }], productsTotal, page, limit (défaut 100, plafond 200),
  investment: { known, estimated } }` ; les totaux portent sur tout le créneau, `products` est paginé.
- `POST /api/gestion/reprise/appliquer { months?, estimateCoefficient?, costs?: [{ productId, purchasePrice }] }` → 201 état
  (forme du `GET`) : périodes manquantes du créneau créées (fermées par la date) ; une **commande de reprise** par période de
  première vente (`commande.kind = "reprise"`, exposé dans les lectures de commandes ; nom « Reprise d'historique — <période> »,
  fournisseur « Reprise d'historique » créé s'il manque, type Autre, date = début de la période, budget = Σ coût × quantité,
  figée) ; article rattaché avec sa **quantité vendue sur la ligne** (`product.commande_quantity`, lue par les calculs d'achat
  et l'export à la place du stock quand elle est posée), coût, rattachement horodaté au début de la période (donc compté par
  toutes les périodes suivantes) ; résultats figés (`period_result`) des périodes du créneau effacés, recalculés à la lecture
  suivante ; `gestion_reprise` (une seule reprise active par site : 409 `REPRISE_ACTIVE`) ; journal `content_audit_log`
  (`gestion-reprise`, `apply`). 422 `VALIDATION_FAILED` (`months` `OUT_OF_RANGE`, `estimateCoefficient` `MUST_BE_POSITIVE`,
  `costs[i].productId` `INVALID_ID` / `PRODUCT_NOT_FOUND`, `costs[i].purchasePrice` `MUST_BE_INTEGER_CENTS`) : rien n'est écrit.
- **Aucun effet sur la vente** (priorité absolue du propriétaire, testé) : appliquer et annuler ne modifient jamais le prix de
  vente, le prix d'achat de la fiche, le stock (`quantity`), les variantes ni la visibilité d'un produit.
- `POST /api/gestion/reprise/annuler` → 200 `{ cancelled, commandesRemoved, productsDetached, periodsRemoved }` : commandes de
  reprise supprimées (produits détachés : commande, coût, quantité de ligne, horodatage et marque estimée effacés ; statistiques
  figées de la commande effacées), périodes créées par la reprise restées sans commande, sans note et sans budget supprimées,
  résultats figés du créneau effacés ; 404 `REPRISE_NOT_FOUND`. N'efface que ce que la reprise a posé.
- `GET /api/gestion/reprise` → `{ active, appliedAt, months, from, to, estimateCoefficient, periods: [{ id, periodKey, name,
  created }], commandes: [{ id, periodKey, periodName, productCount, estimatedCount, investment }], skipped }` ; `active: false`
  et le reste `null` sans reprise.
- `currency` (devise du site) à la racine des quatre réponses (`GestionCurrencyResponseSubscriber`, demande de l'application du 10/11/2026).
- Résultat de période (§ 3) : `cogs.estimatedItems` et `cogs.estimatedAmount` (part estimée, déjà comprise dans `cogs.amount`)
  pour afficher « dont X estimé ».
- Schéma : migration `Version20261130100000`, script `scripts/migrate_all_v2_gestion_reprise.sh` (`commande.kind`,
  `product.commande_quantity`, `product.commande_cost_estimated`, table `gestion_reprise`). Test :
  `tests/Functional/Gestion/RepriseHistoriqueApiTest.php`.

## 5. Routes (ROLE_ADMIN, additives)

| Route | Rôle |
|---|---|
| `GET /api/periodes` (= `GET /api/collections?kind=period`) | périodes du site (créées si manquantes), filtres et pagination de G5 |
| `GET /api/periodes/courante` | période en cours |
| `GET /api/periodes/{id}/resultat` (= `GET /api/collections/{id}/resultat`) | résultat (§ 3, lot P2) |
| `POST /api/dashboard/collection/{id}/reopen` | réouverture manuelle |
| `GET /api/dashboard/collections-combined?kind=` | combiné des manuelles (défaut) ou des périodes |
| `GET`/`PUT /api/site/features` | réglage `gestion.periods` |
| `POST /api/gestion/reprise/simulation` / `appliquer` / `annuler`, `GET /api/gestion/reprise` | reprise d'historique (§ 4, lot P3) |

Les réponses des collections gagnent `kind`, `periodKey`, `status` (`open` | `closed`), `closedAt`, `reopenedAt`
(`manual`, null, état réel, null, null pour une collection manuelle).

## 6. Lots

| Lot | Contenu |
|---|---|
| **P1** | colonnes et réglage, calendrier, création à la lecture, noms, état par date, gardes d'écriture (409 `PERIOD_CLOSED`), figé à la première lecture, réouverture, `GET /api/periodes*`, combiné par `kind`, tableau de bord de période par date, semis demo avec périodes ; **livré le 10/11/2026** (ligne « Gestion : périodes de calendrier, lot P1 » de `docs/architecture.md`). Même lot : `tauxMarge` = marge / coût total et `tauxMarque` = marge / valeur de vente (définitions usuelles, inversées avant ; lignes figées remises d'équerre par la migration, anomalie signalée par l'application) |
| **P2** | résultat de période (§ 3) : ventes par source et par nature, coût des articles vendus avec coût inconnu, frais, investissement ; **livré le 10/11/2026** (`Version20261129100000`, `scripts/migrate_all_v2_gestion_periode_resultat.sh`, `PeriodResultCalculator`, `GetPeriodResultUseCase`, test `PeriodResultApiTest`) |
| **P3** | reprise d'historique (§ 4) ; **livré le 10/11/2026** |

Chaque lot : contrat envoyé à l'application avant le code, tests fonctionnels, suite complète, commit poussé.
