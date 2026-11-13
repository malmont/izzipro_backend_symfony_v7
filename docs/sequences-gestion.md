# Guide des séquences — application de gestion (lot IA-SEQ, 13/11/2026)

« Où j'en suis, quoi faire ensuite. » Le guide liste les suites d'étapes que le gérant mène et calcule son avancement **à partir des
données**, jamais d'une case cochée. Aucune IA, aucune unité, aucune écriture, aucune migration. Plus tard, l'IA fera avancer ces mêmes
étapes (lots IA-FACT, IA-ACT, IA-AVANCE) sans autre référentiel : chaque étape porte déjà la référence de l'action qui la fait avancer.

Code : `src/Gestion/Sequences/SequenceCatalog` (catalogue déclaratif), `src/Gestion/Sequences/SequenceFacts` (constats),
`src/Gestion/Services/GestionSequenceService`, `src/Gestion/Services/MissingCostQuery` (règle « article sans coût »),
`src/Gestion/UseCase/GestionSequenceUseCase`, `src/Gestion/Controller/GestionSequenceController`. Textes : `translations/gestion.{fr,en}.yaml`,
`sequences.*`. Tests : `tests/Functional/Gestion/SequencesApiTest.php`.

## 1. Règle du lot : lire sans écrire, et surtout sans figer

La lecture du résultat ou du tableau de bord d'une période fermée **la fige**. Le guide lit des périodes fermées (clôture de
l'exercice) : son calcul ne passe donc **jamais** par ces lectures, ni par l'écran Année, ni par le budget d'une période, ni par ce qui crée
une période manquante (`PeriodCalendar::current()`, `ensurePeriods()`). `SequenceFacts` ne fait que des requêtes de constat. Pas de cache
propre, pas de journal : tout se recalcule à chaque lecture, comme le cheminement.

Le test `testReadingTheGuideWritesNothingAndFreezesNothing` compte les lignes de **toutes** les tables de la base du site et de la base
maître avant et après la liste, le détail de chaque séquence et de chaque instance, et la liste des articles : identiques, sur un jeu qui
contient des périodes fermées jamais lues.

## 2. Deux sortes de séquences

**À faire une fois (`kind: once`).** Une séquence par groupe du cheminement de mise en route **accordé au site**
(`GET /api/site/readiness`, `docs/console-pilotage.md`) : site, landing page, boutique, caisse, Mémoires Vivantes, Boussole, applications,
assistance, IA… Clé : `setup.` + le groupe sans son préfixe `app.` ou `module.` (`app.management` → `setup.management`, `module.boutique` →
`setup.boutique`, `site` → `setup.site`, `ai` → `setup.ai`) ; le champ `group` garde la clé du cheminement. Les étapes, leurs critères, leurs
textes, leur statut et leur caractère indispensable sont **ceux du cheminement, lus tels quels** : rien n'est recopié, les deux écrans ne
peuvent pas se contredire. Les étapes « Obtenir : … » restent hors des séquences, comme elles sont hors des groupes. Le cheminement
lui-même ne change pas.
`translated: false` quand le groupe n'est pas traduit en anglais : seuls `app.management`, `module.support` et `ai` le sont (lot LEX) ;
les autres restent en français même avec `?locale=en`.

**Qui reviennent (`kind: recurring`)**, avec leurs instances. Les étapes ne gardent que ce que le serveur sait constater : « consulter
l'aperçu » ou « lire l'avis du guide » ne laissent aucune trace, ce ne sont pas des étapes.

| Séquence | Instances (`id`) | Étapes (clé : critère « fait ») |
|---|---|---|
| `period.prepare` Préparer une période | la période en cours (`current: true`) et la suivante ; `id` = `periodKey`, `periodId` entier ou **null** : le calendrier crée la période suivante avec celle en cours quand l'application s'ouvre, mais sur un site où il n'a pas encore tourné elle n'existe pas, et le guide la décrit sans jamais la créer | `budget` : au moins un poste du budget de la période avec un montant prévu (indispensable) · `trip` : un voyage d'achat dont les dates touchent la période (facultatif : jamais « à faire ») · `cash_balance` : dernier solde de trésorerie saisi depuis 30 jours au plus · `fixed_costs` : des frais fixes couvrent des jours de la période · `purchase_order` : au moins une commande fournisseur datée dans la période, hors commandes de reprise |
| `fiscal_year.close` Clôturer l'exercice | le dernier exercice terminé ; `id` = son année de début (« 2025 ») | `periods_closed` : toutes les périodes qui commencent dans l'exercice sont fermées (indispensable ; sans aucune période : séquence sans objet) · `purchase_costs` : aucun article vendu pendant l'exercice sans coût d'achat (indispensable, § 5) · `reconciliation` : un solde de trésorerie saisi dans chaque mois de l'exercice et aucun écart de rapprochement resté ouvert · `fixed_costs` : des frais fixes sur l'exercice |
| `history.recovery` Reprise d'historique | une seule, `id` = `site` ; créneau = celui de la reprise appliquée, sinon des premières ventes à la veille de la période en cours | `needed` : des ventes existent avant le premier achat saisi, ou une reprise est appliquée (sinon **toute la séquence est sans objet**) · `applied` : reprise appliquée · `purchase_costs` : plus aucun article vendu sans coût sur le créneau (§ 5) |

Pas d'acte de clôture de l'exercice dans ce lot (lot CLO, plus tard) : la séquence est prête quand ses étapes le sont, et le bilan se lit à
l'écran `annee`. « Bilan disponible » n'est pas une étape : vrai par construction dès que l'exercice est terminé, et le lire figerait.

## 3. Routes

`ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion actif. `?locale=en` traduit ; toute autre valeur rend le français, comme
le cheminement.

**`GET /api/gestion/sequences`** → `{ summary, sequences: [{ key, kind: once | recurring, group (séquence à faire une fois), title,
description, translated, status, progress, next, instances }] }`, une ligne par séquence, sans les étapes. Ordre : les séquences à faire
une fois (ordre des groupes du cheminement), puis celles qui reviennent (ordre du catalogue).
- `summary` : `{ blockingTodo, improvementTodo, sequencesInProgress, next }`, calculé par le serveur sur **toutes** les séquences, une
  séquence qui revient comptant pour son instance courante. `summary.next` est **la prochaine chose à faire, toutes séquences
  confondues** : `{ sequenceKey, instance (identifiant de l'instance courante, ou null), stepKey, title, blocking, screen, action }`.
  Règle : la première étape indispensable à faire, dans l'ordre des séquences ; sinon la première amélioration à faire ; null quand tout
  est prêt. C'est la séquence que l'IA prendra en premier (lot IA-AVANCE). Le guide est affiché sur le web ; l'application mobile ne
  l'affiche pas (décision du propriétaire), la route est la même.
- `status` : `ready` (plus aucune étape indispensable à faire), `in_progress`, `not_applicable` (sans objet).
- `progress` : `{ done, total, blockingTodo, improvementTodo, percent }` ; `total` ne compte que les étapes faites ou à faire (comme le
  cheminement) ; `blockingTodo` et `improvementTodo` : étapes à faire, indispensables ou non.
- `next` : `{ stepKey, title, blocking, screen, action }` ou null ; la première étape indispensable à faire, sinon la première
  amélioration à faire.
- `instances` (vide pour une séquence à faire une fois) : `[{ id, label, from, to, current, periodId (period.prepare), status, progress,
  next }]`. **Le statut, l'avancement et l'étape suivante d'une séquence qui revient sont ceux de son instance courante.**

**`GET /api/gestion/sequences/{key}?instance={id}`** → `{ key, kind, group?, title, description, translated, status, progress, next,
instance, steps }`. Sans `instance` : l'instance courante. `instance` : `{ id, label, from, to, current, periodId? }`, null pour une
séquence à faire une fois. `label` traduit avec `?locale` (« Fall 2026 ») ; un exercice garde son libellé (« 2025 » ou « 2025-2026 »).
404 `SEQUENCE_NOT_FOUND` ; 404 `INSTANCE_NOT_FOUND` (avec `instances`, les identifiants admis).

**Une étape** : `{ key, title, description, status, blocking, screen, action, figures }`.
- `status` : `done`, `todo`, `pending` (en attente d'un tiers), `optional`, `not_applicable`.
- `blocking` : vrai = indispensable, faux = amélioration (les deux couleurs du cheminement).
- `screen` : `{ where, key, params, url }`.
  - `where` : `app` = écran de l'application de gestion, web et mobile, même code ; `backend`, `editor`, `platform` = les trois valeurs
    du cheminement (back-office, éditeur du site, plateforme). Pour ces trois-là, `url` porte l'adresse, calculée par
    `AdminStepUrlResolver` comme les liens de la page Mon site ; `url` est null pour `app`.
  - `key`, écrans de l'application : `periodes`, `exercice`, `articles`, `fournisseurs`, `tresorerie`, `frais-fixes`, `commandes`,
    `notifications`, `assistance-reglages` (émis par le cheminement) ; `periode.preparation`, `periode.budget`, `voyages`,
    `tresorerie.rapprochements`, `annee`, `reprise` (séquences qui reviennent). Liste tenue dans `SequenceCatalog::APP_SCREENS`.
    Écrans du back-office : la clé du cheminement (`AdminStepUrlResolver::SCREENS`) ; inutile de les mapper, l'adresse est fournie.
  - `params` (objet, vide sinon) : `{ periodId, periodKey }` pour une période, `{ year }` pour un exercice, `{ filter: "missing-cost",
    from?, to? }` pour la liste des articles sans coût : à repasser tels quels à `GET /api/gestion/articles`.
- `action` : `{ key, preparable }`, la référence de l'action qui fait avancer l'étape (`preparation.validate`, `trip.create`,
  `cash.balance.create`, `cash.movement.create`, `purchase.create`, `period.close`, `product.cost.set`, `recovery.simulate`,
  `recovery.apply`, `supplier.create`, `fiscal_year.set`, `periods.calendar.set`, `document.invoice.confirm` ; une étape du cheminement
  sans action de la gestion : `readiness.<clé de l'étape>`). `preparable` : la conversation de la gestion sait préparer l'action (aperçu,
  empreinte, confirmation, annulation). Vrai pour `document.invoice.confirm` depuis le lot IA-FACT-1 et, depuis le lot IA-ACT-1, pour
  toute action du catalogue du fil (`ActionCatalog::KEYS` ; dans le guide : `supplier.create`, et depuis le lot IA-ACT-2
  `cash.balance.create`, `cash.movement.create`, `trip.create` ; `docs/conversation-gestion.md` § 9 et 10) ; faux pour les autres.
  `params` (lot IA-ACT-1) : les paramètres à passer à l'ouverture du fil, `POST /api/gestion/conversations { action: key, params }` ;
  vide, il est rendu `[]` comme `screen.params` ; les étapes « frais fixes » rendent `{ fixedCost: true, recurrence: "monthly" }`.
- `figures` : `[{ key, label, value, unit }]`, calculés par le serveur ; `unit` : `money` (cents de la devise du site), `percent`,
  `count`, `days`, `date`, `ratio` comme les sources des conseils, plus `text`. Les comptes que le cheminement rend sous forme d'objet
  (`{ missing: 7, total: 52 }`) sont convertis dans cette forme.

## 4. Ajouter une séquence ou une étape

Une entrée de `SequenceCatalog::RECURRING` (critère `fact`, `blocking`, `optional`, `screen`, `action`), sa méthode de constat dans
`SequenceFacts` (lecture seule) et ses textes `sequences.<séquence>.steps.<étape>.{title,done,todo}` en français et en anglais. Le
garde-fou `testEveryStepOfTheCatalogueHasACriterionAScreenAnActionAndItsTexts` refuse une étape sans critère, sans écran connu, sans
référence d'action ou sans texte, et un chiffre sans libellé. La première étape d'une séquence dit si elle a un objet : si son constat
répond « sans objet », toutes les étapes le sont.

## 5. Articles sans coût : une seule règle, et la liste qu'une étape ouvre

`MissingCostQuery` porte LA règle : un article **standard, hors location**, est sans coût quand il n'en a **ni sur sa fiche**
(`purchase_price` absent ou nul) **ni sur une ligne d'achat chiffrée** (`commande_line.unit_cost_cents`). Le compteur du cheminement
(`management.purchase_cost`), les étapes `purchase_costs` et la liste ci-dessous passent par les mêmes fragments : le nombre d'une étape
et la liste qu'elle ouvre disent toujours la même chose (tests : catalogue, exercice, créneau de reprise).

**`GET /api/gestion/articles?filter=missing-cost[&from=&to=][&q=][&page=&limit=]`**, lecture seule →
`[{ id, name, code, image, price, stock, commandeId, periodId, costSource }]`, par nom.
- Sans `from` ni `to` : tout le catalogue suivi. Avec les deux (jours du magasin, `AAAA-MM-JJ`) : seulement les articles **vendus dans ce
  créneau**, quantité nette positive, mêmes règles de vente que la reprise d'historique (ventes et retours, hors commandes annulées ou en
  attente, hors location et abonnement).
- `q` : nom ou code, sans casse. `page`, `limit` (100 au plus) : convention des listes de la gestion ; en-tête `X-Total-Count` toujours,
  `X-Page` et `X-Limit` avec la pagination.
- `price` en cents, `stock` = quantité de la fiche, `commandeId` = commande à laquelle l'article est rattaché (ou null), `periodId` = la période de cette commande (null sans commande ou si
  la commande n'est dans aucune période ; ajouté le 13/11/2026 pour ouvrir la commande depuis la ligne sans lire un tableau de bord, qui
  figerait une période fermée), `costSource` =
  null (inconnu, par définition de ce filtre ; le champ servira aux filtres à venir).
- 422 `VALIDATION_FAILED` (`fields`) : `filter` absent ou inconnu, un seul de `from` / `to`, date mal formée, `to` avant `from`.
