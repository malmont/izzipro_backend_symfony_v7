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
    coût unitaire** (l'article revient en stock). Coût unitaire = coût de la commande fournisseur du produit ramené par son taux
    (G6) si le rattachement est antérieur à la **coupure** (ci-dessous), sinon `purchase_price` du produit, **sinon coût
    inconnu** : la ligne est comptée dans `unknownCostItems` (articles vendus) et exclue de `cogs`, jamais valorisée au prix de
    vente. `items` = articles vendus au coût connu.
  - `expenses` : frais de port des commandes fournisseurs datées dans la période + notes de frais datées dans la période ;
    `other` est un tableau vide, extensible (`{ kind, label, amount }`) pour la publicité et les frais fixes sans changer la forme.
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

- `POST /api/gestion/reprise/simulation { months: 12 }` → périodes qui seraient créées, ventes par période, articles vendus avec
  ou sans coût connu, investissement estimé ; rien n'est écrit.
- `POST /api/gestion/reprise/appliquer { months, costs: [{ productId, purchasePrice }] }` → périodes manquantes (fermées par
  la date), **une commande de reprise par période** (« Reprise d'historique — Été 2025 », fournisseur « Reprise d'historique »,
  `kind = 'reprise'` sur la commande) avec les produits vendus pendant la période rattachés au coût fourni ; sans coût fourni :
  coût inconnu. Journal `content_audit_log`. Une seule reprise active par site.
- `POST /api/gestion/reprise/annuler` → commandes de reprise supprimées (produits détachés), périodes créées sans autre contenu
  supprimées.
- Déjà en place depuis P1 : `PeriodCalendar::ensurePeriods($from)` crée aussi les périodes manquantes **avant** la première
  période existante quand `$from` lui est antérieur (24 mois au plus) ; le semis demo et la reprise s'appuient dessus.

## 5. Routes (ROLE_ADMIN, additives)

| Route | Rôle |
|---|---|
| `GET /api/periodes` (= `GET /api/collections?kind=period`) | périodes du site (créées si manquantes), filtres et pagination de G5 |
| `GET /api/periodes/courante` | période en cours |
| `GET /api/periodes/{id}/resultat` (= `GET /api/collections/{id}/resultat`) | résultat (§ 3, lot P2) |
| `POST /api/dashboard/collection/{id}/reopen` | réouverture manuelle |
| `GET /api/dashboard/collections-combined?kind=` | combiné des manuelles (défaut) ou des périodes |
| `GET`/`PUT /api/site/features` | réglage `gestion.periods` |
| `POST /api/gestion/reprise/simulation` / `appliquer` / `annuler` | reprise d'historique |

Les réponses des collections gagnent `kind`, `periodKey`, `status` (`open` | `closed`), `closedAt`, `reopenedAt`
(`manual`, null, état réel, null, null pour une collection manuelle).

## 6. Lots

| Lot | Contenu |
|---|---|
| **P1** | colonnes et réglage, calendrier, création à la lecture, noms, état par date, gardes d'écriture (409 `PERIOD_CLOSED`), figé à la première lecture, réouverture, `GET /api/periodes*`, combiné par `kind`, tableau de bord de période par date, semis demo avec périodes ; **livré le 10/11/2026** (ligne « Gestion : périodes de calendrier, lot P1 » de `docs/architecture.md`). Même lot : `tauxMarge` = marge / coût total et `tauxMarque` = marge / valeur de vente (définitions usuelles, inversées avant ; lignes figées remises d'équerre par la migration, anomalie signalée par l'application) |
| **P2** | résultat de période (§ 3) : ventes par source et par nature, coût des articles vendus avec coût inconnu, frais, investissement ; **livré le 10/11/2026** (`Version20261129100000`, `scripts/migrate_all_v2_gestion_periode_resultat.sh`, `PeriodResultCalculator`, `GetPeriodResultUseCase`, test `PeriodResultApiTest`) |
| **P3** | reprise d'historique (§ 4) |

Chaque lot : contrat envoyé à l'application avant le code, tests fonctionnels, suite complète, commit poussé.
