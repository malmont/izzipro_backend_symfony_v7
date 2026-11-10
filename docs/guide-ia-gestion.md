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
- `POST /api/gestion/conseils/{id}/fait { note? }`, `POST /api/gestion/conseils/{id}/ecarter { reason? }` → le conseil ; 404 `ADVICE_NOT_FOUND`,
  409 `ADVICE_CLOSED`.
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
| G1b | mesure après application (« fait » avec ce qui a été appliqué ; marge des N jours après contre N jours avant), commentaire du simulateur (répartitions calculées par le backend) |
