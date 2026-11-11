# Volet publicité — application de gestion (lot A1, 10/11/2026)

Contrat validé par l'application (« new easymakemoney ») avec ses réponses : devise de la régie avec un taux, justificatif dès maintenant,
double référence. Ajouts seulement, `ROLE_ADMIN` (`access_control` `/api/gestion`), cents. Code : `Entity/MarketingCampaign` (tables
`marketing_campaign`, `marketing_campaign_product`, `marketing_campaign_commande`), `Gestion/Services/MarketingCampaignService`,
`Gestion/Services/CampaignProfitabilityCalculator`, `Gestion/UseCase/MarketingCampaignUseCase`,
`Gestion/Controller/MarketingCampaignController`. Migration `Version20261204100000`, script
`scripts/migrate_all_v2_gestion_campagnes.sh`. Test : `tests/Functional/Gestion/MarketingCampaignApiTest.php`.

## 1. Campagne

`{ id, name, channel, startDate, endDate, amount, currency, exchangeRate, amountSite, siteCurrency, productIds, commandeIds, notes,
document: { id, type, invoiceNumber, invoiceDate } | null, period: { id, periodKey, name } | null, createdAt }`

- `channel` : `facebook` | `instagram` | `google` | `tiktok` | `email` | `print` | `radio` | `flyer` | `influence` | `event` | `other`.
- `amount` en cents dans la **devise de la campagne** (`currency`, celle de la régie ; par défaut la devise du site) ; `exchangeRate`
  obligatoire si elle diffère du site (422 `EXCHANGE_RATE_REQUIRED`, `MUST_BE_POSITIVE`), ignoré sinon (rendu `null`) ; `amountSite` = montant
  ramené dans la devise du site. Tous les calculs (résultat de période, rentabilité) utilisent `amountSite`. Même règle que les commandes (G6).
- Cible facultative : `productIds` et / ou `commandeIds` (leurs articles, par les lignes d'achat) ; sans cible, toutes les ventes d'articles.
- **Classée dans la période de `startDate`** (tout le montant). Création, modification et suppression refusées si cette période est fermée
  (409 `PERIOD_CLOSED`), et pour une modification si la nouvelle date y tombe ; 422 `OUT_OF_CALENDAR`.

## 2. Routes (`/api/gestion/campagnes`)

| Route | Rôle |
|---|---|
| `GET ?from=&to=&channel=&page=&limit=` | campagnes qui touchent l'intervalle, récentes d'abord ; `X-Total-Count`, `X-Page`, `X-Limit` (50, au plus 100) |
| `POST { name, channel, startDate, endDate, amount, currency?, exchangeRate?, productIds?, commandeIds?, notes? }` | 201 la campagne |
| `GET /{id}`, `PATCH /{id}` (mêmes champs, facultatifs), `DELETE /{id}` | la campagne ; 204 ; 404 `CAMPAIGN_NOT_FOUND` |
| `GET /{id}/rentabilite` | rentabilité perçue (§ 3), `currency` du site à la racine |

422 `VALIDATION_FAILED` (`fields` : `channel` `INVALID_CHANNEL`, `endDate` `BEFORE_START`, `amount` `MUST_BE_INTEGER_CENTS`, `currency`
`INVALID_CURRENCY`, `exchangeRate`, `productIds[n]` `PRODUCT_NOT_FOUND`, `commandeIds[n]` `COMMANDE_NOT_FOUND`). Journal `content_audit_log`
(`gestion-campagnes`).

**Justificatif** : la facture d'une régie se lit comme un document de type `note` (`docs/lecture-documents-gestion.md`), puis se confirme
**en campagne** : `POST /api/gestion/documents/{id}/confirmer { as: "campaign", name, channel, startDate, endDate, amount, currency?,
exchangeRate?, productIds?, commandeIds?, notes? }` → 201 `{ campaign, document }` (aucune note de frais créée ; `document.campaignId`).
Supprimer la campagne libère le justificatif, purgé ensuite.

## 3. Résultat de période

`expenses.other` reçoit `{ kind: "advertising", label: "Publicité", amount, campaigns: [{ id, name, amount }] }` (somme des `amountSite` des
campagnes classées dans la période) quand il y en a ; `expenses.total` et `result.net` l'incluent. Les résultats déjà figés ne changent pas.

## 4. Rentabilité perçue (estimation)

```
{ campaign: { id, name }, estimate: true, method: "before_after", currency,
  group: { campaignIds, amount, from, to, shared }, window: { from, to, days, complete }, reference: { from, to, days },
  scope: { kind: "all" | "targeted", productIds },
  during, before, lastYear | null: { revenue, grossMargin, orders, items, unknownCostRevenue },
  uplift: { revenue, grossMargin }, gainPerDollar: { revenue, grossMargin } | null, caveats: [string] }
```

- **Groupe** : les campagnes qui se chevauchent, de proche en proche, sont mesurées ensemble (union des dates, somme des montants, union des
  cibles, ou tout le magasin si l'une n'a pas de cible) ; chaque campagne du groupe rend le même résultat (`shared: true`).
- **Fenêtre** : dates du groupe bornées à aujourd'hui (`complete: false` tant qu'il n'est pas fini). Une campagne à cheval sur la fin d'une
  période reste classée dans sa période de début, mais sa mesure couvre toute sa durée, au-delà de la clôture de cette période. Pas encore commencée : `window.days` 0, mesures à zéro,
  `lastYear` `null`, remarque « pas encore commencée ».
- **Référence principale** `before` : le même nombre de jours juste avant ; **`lastYear`** : la même fenêtre un an plus tôt, quand les ventes
  du site remontent jusque-là (sinon `null`).
- **Ventes et marge** : règles du résultat de période (`PeriodResultCalculator::salesMetrics`) : ventes d'articles nettes des retours, hors
  taxes et nettes des remises, locations et abonnements exclus ; marge brute = ventes au coût connu − coût moyen pondéré à la date de la
  vente ; la part au coût inconnu est exclue et rendue (`unknownCostRevenue`).
- `uplift` = pendant − avant ; `gainPerDollar` = hausse ÷ `group.amount` (devise du site), 2 décimales, `null` pour un montant nul.
  L'application met en avant `gainPerDollar.grossMargin` (une campagne qui fait vendre à perte n'est pas rentable).
- `caveats`, en français simple : campagne en cours ; partagée avec d'autres ; peu de ventes avant (moins de 5 commandes) ; ventes sans coût
  connu ; tendance très différente de l'an dernier (« la saison pèse sans doute »).
