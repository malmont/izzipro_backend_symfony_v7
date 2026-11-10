# Guide IA de la gestion (lot G1a, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, sur `docs/propositions-pilotage.md` de l'application (sections
« Conseiller IA » et « Guide de période »). Le guide accompagne la période (voyage d'achat, vente, fin de période, clôture) ; chaque conseil
donne une action, son effet estimé en argent, un niveau de confiance et ses sources. Règles fermes : **le backend calcule tous les chiffres,
l'IA ne fait que rédiger, classer et résumer** ; rien ne s'applique sans le gérant (actions v1 : ouvrir un écran) ; aucune donnée personnelle
vers l'IA ; salaires en total seulement. G1b (mesure après application, commentaire du simulateur) viendra ensuite.

Code : `Services/GestionService/AdviceRules` (règles), `GestionAdviceService` (générations, conseils, textes de repli, vérification des
nombres, vignette), `AdviceWriter` (rédaction par l'IA), `Message/GenerateGestionAdviceMessage` + `MessageHandler/GenerateGestionAdviceHandler`
(transport `gestion_ai`, worker de la gestion), `UseCase/GestionUseCase/GestionAdviceUseCase`, `Controller/GestionController/GestionAdviceController`,
commande `app:gestion:conseils`. Tables `gestion_advice`, `gestion_advice_run` (entités `GestionAdvice`, `GestionAdviceRun`, en SQL, jamais en
cache applicatif). Textes `translations/gestion.{fr,en}.yaml` (`advice.*`). Migration `Version20261209100000`, script
`scripts/migrate_all_v2_gestion_conseils.sh`. Test : `tests/Functional/Gestion/AdviceApiTest.php`.

## 1. Moments

Calculés pour la période demandée, à la date du jour (plusieurs peuvent coexister) :
- `planning` : période à venir (seules les règles de budget et de trésorerie) ;
- `selling` : période en cours, avant la phase de fin ;
- `end` : 14 derniers jours (le dernier tiers si la période dure moins de 42 jours) ;
- `closing` : période terminée, jusqu'à 60 jours après sa fin ;
- `trip` : un voyage couvre aujourd'hui.

## 2. Règles (constantes de `AdviceRules`)

`effect.amount` est **signé du point de vue du gérant** : positif = argent gagné ou libéré, négatif = coût ou risque.

| Règle | Moments | Condition | Effet | Confiance | Action |
|---|---|---|---|---|---|
| R1 `FAST_SELLER` | selling | ≥ 5 ventes nettes en 30 j, < 21 jours de stock à ce rythme, coût connu | ventes restantes (rythme × jours restants, plafonné au stock) × prix moyen obtenu × 5 % | medium dès 10 ventes, sinon low | `articles { productIds }` |
| R2 `SLOW_STOCK` | selling, end | en stock, aucune vente nette depuis 45 j, valeur au coût ≥ 100 $, plus vieux que 45 j (1re ligne d'achat, sinon création) | valeur au coût (argent immobilisé) | low | `simulateur { periodId, promotion 20 % }` |
| R3 `AD_RELAUNCH` | selling | campagne finie depuis < 60 j, `gainPerDollar.grossMargin` ≥ 2, aucune campagne en cours | (gain − 1) × montant | medium, low si la rentabilité a des remarques | `campagnes.new { fromCampaignId }` |
| R4 `BUDGET_PACE` | planning, selling, end, trip | poste dépassé (high) ou réel ÷ prévu ≥ part écoulée + 0,2 (medium) | −dépassement constaté ou projeté (réel ÷ part écoulée − prévu) | | `periode.budget { periodId }` |
| R5 `TRIP_BUDGET` | trip | suivi du voyage en warning ou over | reste du budget (négatif si dépassé) | high | `voyages.suivi { tripId }` |
| R6 `TRIP_COEFFICIENT` | trip | coefficient des achats du voyage < 0,9 × celui des 365 derniers jours (≥ 20 lignes, hors voyage et reprise) | coût × (référence − coefficient) | medium | `voyages.suivi { tripId }` |
| R7 `CASH_TIGHT` | sauf closing | trésorerie warning ou critical (`docs/tresorerie-gestion.md`) | point bas 14 j − seuil | high (medium avec ventes estimées) | `tresorerie` |
| R8 `PURCHASE_CAPACITY` | planning, selling, trip | reste un prévu de stock à acheter et un solde est saisi : premier jour (90 j au plus) où la série d'alerte moins le reste à acheter reste au-dessus du seuil | −reste à acheter ; `params.date` (null : « pas dans les 90 jours ») | high (medium avec ventes estimées) | `tresorerie` |
| R9 `END_CLEARANCE` | end | stock de fin (rythme du simulateur) au coût ≥ 30 % des achats de la période (≥ 500 $ sans achats) | valeur au coût des 5 articles lents (≤ 2 ventes en 30 j) les plus lourds | low | `simulateur { periodId, promotion 20 % }` |
| R10 `PACE_BEHIND` / `PACE_AHEAD` | selling | ventes prévues (base du simulateur) ÷ même période l'an dernier < 0,85 ou > 1,15 | l'écart | medium | `simulateur { periodId }` |
| R11 `CLOSING_REVIEW` | closing | toujours : ventes, marge, résultat net, et l'an dernier s'il existe | résultat net | high | `periode.budget` |
| R11 `LESSON_STOCKOUT` | closing | variantes à stock 0 vendues ≥ 5 fois dans la période | marge de leurs ventes | medium | `articles` |
| R11 `LESSON_OVERSTOCK` | closing | articles invendus dans la période, ≥ 100 $ au coût | valeur au coût | medium | `articles` |
| R11 `LESSON_BUDGET` | closing | poste dépassé de plus de 10 % | −dépassement | high | `periode.budget` |

- R1, R2 : 3 conseils au plus (plus gros effets) ; R3 : 2 au plus. Plafond de 8 conseils, R7 et R8 gardés au-delà. Rang de départ =
  |effet| × poids (high 1, medium 0,6, low 0,3) ; l'IA peut réordonner.
- `figures` des conseils sur des articles (R1, R2, R9, leçons) : `[{ productId, variantId: null, name, price (affiché), cost (courant), stock, soldLast30Days }]`.
- Le stock des leçons de clôture est le stock actuel (pas celui du dernier jour de la période).

## 3. Conseil

```
{ id, periodId, moment, kind, title, text, textSource: "ai" | "rules",
  action: { screen, params }, effect: { amount, label }, confidence: "high" | "medium" | "low",
  sources: [{ label, value, unit: money | percent | count | days | date | ratio }], figures: [], subject: { productIds? | campaignId? | category? | tripId? },
  status: "new" | "done" | "dismissed", active, rank, createdAt, updatedAt, doneAt, dismissedAt }
```

- Identité : genre + sujet (`signature`) dans la période. Une nouvelle génération met à jour les chiffres d'un conseil existant et **garde son
  statut** ; un conseil fait ou écarté ne revient pas dans la période ; un conseil qui n'est plus détecté passe `active: false` (historique).
- Le texte de l'IA est gardé tant que les sources ne changent pas ; si elles changent, le texte de repli reprend jusqu'à la rédaction suivante.
- Fait / écarté : statut porté par le site, auteur (`status_by`), note et date gardés ; le sujet reste en base pour la mesure de G1b.

## 4. Routes (`ROLE_ADMIN`)

- `GET /api/gestion/conseils?periodId=&status=new|all&locale=` (sans `periodId` : la période en cours ; 404 `PERIOD_NOT_FOUND` / `NOT_A_PERIOD`,
  422 `INVALID_STATUS`) → `{ period { id, name, start, end }, moments, currency, headline, ai { status, generatedAt }, generating, manualRefreshesLeft, advices }`.
  - Premier GET du jour d'une période : les règles sont calculées tout de suite (textes de repli) et la rédaction par l'IA part au worker ;
    les GET suivants rendent ses textes. Une période fermée n'a qu'une génération, ensuite figée.
  - `ai.status` : `rules_only` (règles seules), `pending`, `ok`, `not_included` (pas de droit « Aide IA » / « IA complète »),
    `credits_exhausted`, `error`. Une rédaction perdue (worker arrêté) passe en `error` après 15 minutes.
- `POST /api/gestion/conseils/{id}/fait { note?, applied? }`, `POST /api/gestion/conseils/{id}/ecarter { reason? }` → le conseil ; 404
  `ADVICE_NOT_FOUND`, 409 `ADVICE_CLOSED` ; `applied` (lot G1b-1) : § 8.
- `GET /api/gestion/conseils/{id}/mesure` (lot G1b-1) : § 8.
- `POST /api/gestion/conseils/actualiser { periodId? }` → 202 `{ runId, manualRefreshesLeft }` ; 429 `ADVICE_REFRESH_LIMIT` (3 par site et par
  jour), 409 `ADVICE_GENERATING` (une rédaction en cours), 409 `PERIOD_CLOSED`.
- Commande `app:gestion:conseils --tenant= [--period=] [--locale=]` : règles du jour sans IA et même réponse que le GET (contrôle, exemples).

## 5. L'IA

- `claude-sonnet-5-5` en sortie structurée (`{ headline, advices: [{ id, title, text }], order }`, sans union de types), 1 crédit IA du site
  par rédaction (compteur `LandingAiQuotaService`, composant `gestion_advice`), droit « Aide IA » ou « IA complète » ; sans droit ni crédits,
  le guide reste en textes de repli, sans erreur. Au plus 1 rédaction automatique par période et par jour et 3 relances : 5 crédits par jour au plus.
- Charge envoyée (`AdviceWriter::payload`) : période, moments, résultat (ventes, marge, net), autres revenus (locations, abonnements),
  trésorerie (statut, point bas, seuil), mouvements prévus des 30 jours en totaux par catégorie (salaires en total), conseils (genre, moment,
  confiance, effet, sources formatées, noms d'articles, texte de repli). Jamais de client, fournisseur, utilisateur, nom de campagne (canal
  seulement) ni nom de voyage (lieu seulement).
- **Vérification** : tout nombre écrit dans un titre ou un texte doit être une valeur des sources ou de l'effet du conseil, sous une écriture
  admise (montant au cent, arrondi ou sans les centimes ; pourcentage ou ratio arrondi ; date par jour, mois ou année ; quantité), ou un repère
  fixe (1, 7, 14, 21, 30, 45, 60, 90). Une valeur recalculée (somme, écart) est refusée : le texte est rejeté et le dernier texte valide, sinon
  le texte de repli, reste. La phrase de synthèse est vérifiée sur les valeurs du contexte et des conseils. Rejets comptés
  (`gestion_advice_run.rejected`) ; le texte rejeté n'est journalisé qu'en débogage (`kernel.debug`).
- Langue : celle du site ; une autre langue demandée reçoit les textes de repli.

## 6. Vignette « advice »

`docs/vignettes-gestion.md` : premier conseil `new` de la période en cours, `kind: "advice"`, priorité 68 / 64 / 60 selon la confiance, action
`conseils { adviceId }` ; jamais pour `CASH_TIGHT` ni `TRIP_BUDGET` (déjà `cash-low` et `trip-budget`). Le premier GET du jour des vignettes
calcule les règles (sans jamais demander l'IA) et crée la période en cours au besoin.

## 7. Lots

| Lot | Contenu |
|---|---|
| **G1a** | règles R1–R11, conseils, générations, rédaction vérifiée par l'IA, routes, vignette ; livré le 11/11/2026 |
| **G1b-1** | mesure après application (§ 8), vignette `advice-result`, résultats des conseils appliqués dans le contexte de l'IA ; livré le 11/11/2026 |
| **G1b-2** | avis du guide sur le simulateur (§ 9) ; `currency` sur chaque vignette ; livré le 11/11/2026 |
| P4 | appliquer un prix depuis un conseil (`adviceId`, conseil marqué fait dans le même appel) : `docs/prix-gestion.md` |
| Plus tard | mesure des leçons de clôture sur la période suivante de même saison |

## 8. Mesure après application (lot G1b-1)

- **« Fait » avec ce qui a été appliqué** : `applied { appliedOn?, pricePercent?, promotionPercent?, productIds?, campaignId? }`, facultatif
  (l'application propose le pourcentage du conseil et la date du jour). `appliedOn` : aujourd'hui par défaut, jamais dans le futur ; `productIds` :
  les articles du sujet par défaut ; `campaignId` : la campagne créée pour une relance. Colonnes `gestion_advice.applied`, `applied_on`
  (`Version20261210100000`, `scripts/migrate_all_v2_gestion_conseils_mesure.sh`). 422 `applied` `MUST_BE_OBJECT` ; `applied.appliedOn`
  `INVALID_DATE` | `DATE_IN_FUTURE` ; `applied.pricePercent` (−90 à 300) / `applied.promotionPercent` (0 à 90) `MUST_BE_NUMBER` | `OUT_OF_RANGE` ;
  `applied.productIds[i]` `PRODUCT_NOT_FOUND` ; `applied.campaignId` `CAMPAIGN_NOT_FOUND`. Sans `applied` : date du « fait », sujet du conseil.
- `GET /api/gestion/conseils/{id}/mesure` (`AdviceMeasure`) → `{ adviceId, kind, measurable, appliedOn, applied, window { days, before { from, to },
  after { from, to }, complete }, scope { kind: products | campaign, productIds, campaignId? }, before, after, change { revenue, grossMargin, units },
  lastYear { before, after, change } | null, estimatedEffect, confidence, caveats [{ code, text }], text }` ; 404 `ADVICE_NOT_FOUND`,
  409 `ADVICE_NOT_DONE`.
  - Fenêtre : `after` = 14 jours à partir de `appliedOn`, coupés à hier ; `before` = autant de jours juste avant ; `complete` à 14 jours ;
    moins de 7 jours : remarque `TOO_EARLY`, confiance low.
  - Ventes et marge des articles du conseil, mêmes règles que le résultat de période (`PeriodResultCalculator::salesMetrics`).
  - Saison : mêmes fenêtres l'an dernier ; si les ventes y ont varié dans le même sens et d'une ampleur comparable (± 50 % de la variation de
    cette année), remarque `SEASONAL`, confiance low. Sinon : medium si complète, low sinon.
  - Mesurables : `FAST_SELLER`, `SLOW_STOCK`, `END_CLEARANCE` (articles), `AD_RELAUNCH` avec `applied.campaignId` (rentabilité A1 de la
    campagne reprise : `before` = référence, `after` = pendant). Les autres, **leçons de clôture comprises**, rendent `measurable: false` sans
    chiffre (la vraie mesure d'une leçon viendra en comparant la période suivante de même saison).
  - `text` (repli des règles) : hausse de prix « Hausse de 5 % appliquée le 12 novembre : marge +35,00 $ en 14 jours (estimé : +3,00 $). » ;
    promotion sur un article qui ne se vendait pas (aucune vente avant) : les ventes obtenues (« Manteau : 3 ventes depuis la promotion du … ») ;
    sinon l'écart de marge ; trop tôt : « trop tôt pour mesurer (n jours sur 14) ».
- Liste `GET conseils` : chaque conseil fait porte `applied`, `appliedOn` et `measurement { complete, change { grossMargin, revenue }, text } | null`.
- Contexte de l'IA : `appliedResults` (genre, date, écarts de marge et de ventes des conseils de la période dont la mesure est complète) ; ces
  chiffres sont admis par la vérification.
- Vignette **`advice-result`** (`kind: "advice"`, priorité 50) : le dernier conseil fait dont la mesure est complète depuis moins de 30 jours ;
  texte = texte de la mesure ; action `conseils { adviceId }` ; écartée pour toujours pour ce conseil (un conseil suivant la fait revenir).

## 9. Avis du guide sur le simulateur (lot G1b-2)

`POST /api/periodes/{id}/simulation/commentaire` (même corps que `POST /api/periodes/{id}/simulation`, bouton « Avis du guide » de l'application,
jamais à chaque mouvement d'un curseur ; `GestionSimulationCommentService`) →

```
{ simulation: (réponse de POST simulation, inchangée),
  candidates: [{ key, label, planned { purchases, trip, shipping, advertising, other }, result, cashAfterPurchases, breakEven, expenses,
                 delta { result, cashAfterPurchases }, purchasesCut?, reachesThreshold? }],
  comment: { recommended, title, text, textSource: "ai" | "rules", rejected? },
  ai: { status: ok | not_included | credits_exhausted | error }, cached }
```

- Candidats, tous calculés par le backend et repassés dans le simulateur avec les leviers du gérant (seul le prévu change) ; `delta` par
  rapport à `keep` :
  - `keep` : le prévu enregistré ;
  - `simulated` : le prévu du corps (seulement si le corps en porte un) ;
  - `lastYearShares` : le même total prévu, réparti selon les parts réelles des postes de la même période l'an dernier (l'arrondi va au
    stock) ; absent sans période l'an dernier, sans dépense l'an dernier ou sans prévu ;
  - `lastYearActual` : chaque poste au réel de la même période l'an dernier, sans ramener au total actuel (« et si je dépense comme l'an
    dernier ? ») ; absent sans période l'an dernier ;
  - `cashSafe` : achats prévus (de `simulated`, sinon `keep`) réduits juste assez pour que la trésorerie après achats atteigne le seuil, jamais
    sous les achats déjà faits (`purchasesCut`, `reachesThreshold`) ; absent sans solde saisi ou si la trésorerie est déjà au-dessus.
- **Un changement des seuls achats ne modifie pas `result`** (le stock n'est pas une charge du résultat) : seule la trésorerie après achats
  change (le stock de fin du simulateur ne compte pas les achats prévus). L'IA reçoit cette règle : elle ne présente jamais `cashSafe` comme une
  perte de résultat.
- Avis : l'IA choisit `recommended` parmi les clés et explique en deux ou trois phrases (Sonnet 5.5, sortie structurée, 1 crédit, droit Aide
  IA, appel synchrone de 30 s au plus). Mêmes vérifications qu'au § 5 : tout nombre écrit doit venir des candidats, de la simulation ou des
  leviers, sinon avis des règles (`rejected: true`). Avis des règles : `cashSafe` si la trésorerie l'impose, sinon le meilleur résultat (à
  égalité, garder le prévu).
- Cache : même période, mêmes leviers, même langue, même jour → même réponse (`cached: true`), sans nouveau crédit (table
  `gestion_simulation_comment`, `Version20261211100000`, `scripts/migrate_all_v2_gestion_simulation_commentaire.sh`).
- Plafond : 10 avis par site et par jour, puis 429 `COMMENT_LIMIT` dont le corps porte quand même `simulation`, `candidates` et l'avis des
  règles. 404 `NOT_A_PERIOD`, 409 `PERIOD_CLOSED`, 422 comme la simulation.
- Appliquer = `PUT /api/periodes/{id}/budget` avec le `planned` du candidat choisi ; aucune écriture ici hors du cache.

