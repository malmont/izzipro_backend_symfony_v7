# Console IA générale de la gestion — lot W2 (contrat validé et codé le 13/11/2026)

Cette fiche est le contrat validé par le propriétaire le 13/11/2026, et la description de ce qui est codé.

**État : codé (lot W2, 13/11/2026).** Code : `src/Gestion/Analysis/AnalysisCatalog.php` (les analyses), `src/Gestion/Services/Console/`
(`ConsoleService` : les routes ; `ConsoleModel` : les deux appels au modèle ; `ConsoleResponder` : la réponse, dans le worker
`gestion_ai`), `Gestion/Controller/GestionConsoleController`, message `AnswerConsoleQuestionMessage`. Aucune table de plus : les
échanges sont dans les tables du fil (`gestion_conversation`, type `console`) et ne sont jamais rendus par les routes du fil.
Test : `tests/Functional/Gestion/ConsoleApiTest.php` (modèle simulé).

**Lot W2-b (13/11/2026), retouches après le premier essai réel sur demo** : l'écran d'où vient la question est traduit par le serveur
(codes de l'application web, § 4.1) ; une question qui dit « période » lit les ventes sur la période ; les libellés de plage sont
rédigés et ceux des chiffres disent ce qu'ils comptent (§ 2) ; tout chiffre dont un texte parle a sa carte à la suite ; un bilan
d'articles porte ses groupes (§ 3). Code ajouté : `Services/Console/ConsoleScreens.php` (la table des écrans), `Analysis/RangeLabel.php`.

**Lot W2-c (13/11/2026), retouches après l'essai de comparaison** : une carte ne suit que le texte qui parle vraiment du chiffre, et
une même grandeur n'a qu'une carte (§ 3) ; les états et les dates sont rédigés, l'intertitre d'un groupe est une phrase complète, le
panier moyen dit ce qu'il divise (§ 2) ; l'écran Trésorerie donne son horizon de 90 jours (§ 4.1) ; un contexte sans paramètre rend
`params: {}` ; `GET /api/gestion/ia` rend `costs.console: 1` (par réponse rédigée).

## 1. Ce que fait la console

Le gérant pose une question libre sur l'activité de gestion de son site. La console répond comme un spécialiste : elle voit toute la
gestion, fait des analyses d'ensemble, conseille, et peut proposer une action.

Trois règles fermes :
- **L'IA ne calcule rien.** Elle choisit des analyses dans un catalogue, le serveur les exécute, puis l'IA rédige à partir de leurs
  résultats. Tout nombre écrit dans une réponse vient d'une analyse du serveur, avec sa source (écran et route).
- **L'IA n'écrit rien dans les données.** Une action proposée ouvre un fil d'action existant (`docs/conversation-gestion.md` : aperçu,
  empreinte, confirmation, annulation). C'est le gérant qui confirme.
- **Gestion seulement** (décision de périmètre du propriétaire, en tête de `docs/conversation-gestion.md`). Le guide quotidien et ses
  vignettes ne changent pas : les ajustements précis restent chez eux, la console porte les analyses d'ensemble et les ajustements globaux.

## 2. Une analyse nommée

Le socle, commencé dans le lot IA-ACT-5. Une analyse est un service du serveur, sans IA : une clé, un titre, des paramètres déclarés et
une sortie chiffrée. Le fil d'une facture, le guide quotidien et la console appellent la même.

```
{ key, title, period: { from, to, label, previousLabel?, lastYearLabel? } | null,
  figures: [{ id, label, value, unit, previous?, lastYear?, delta?, deltaPercent?, rank? }],
  rows: [{ ... }],            // lignes d'un classement ou d'une liste, bornées
  groups: [{ verdict, source, count, label, productIds, names }],   // bilan d'articles seulement, sinon []
  more: { count, total } | null,
  source: { screen, route, params } }
```

- `unit` : `money` (cents de la devise du site), `percent`, `count`, `days`, `date`, `ratio`, `text`, comme les chiffres du guide.
- **Les comparaisons sont fournies par l'analyse** : valeur de la période précédente et de l'an dernier, écart (`delta`), variation en
  pourcentage (`deltaPercent`), rang dans un classement (`rank`). L'IA peut ainsi dire « la marge a baissé de 3 points » en citant un
  chiffre fourni, sans rien calculer.
- Taille bornée : 30 chiffres et 50 lignes au plus par analyse. Au-delà : des totaux, et `more: { count, total }` ; le détail complet
  reste lisible par la route `source`.
- Catalogue en **liste blanche**, comme celui des actions : une analyse absente ne peut pas être appelée.
- **Un libellé de plage est rédigé par le serveur** (lot W2-b, `RangeLabel`) : « du 1er au 13 novembre 2026 », « le 13 novembre 2026 »,
  « du 28 octobre au 13 novembre 2026 » ; en anglais « November 1 to 13, 2026 ». Jamais la forme brute « 1-13 nov. ». Une période se
  nomme par son nom. `previousLabel` et `lastYearLabel` nomment de la même façon les plages comparées (`sales.overview`).
- **Le libellé d'un chiffre dit ce qu'il compte** quand deux mesures se ressemblent : « Frais de la période (port, notes de frais,
  publicité), hors achats de marchandise » (résultat de période) et « Dépenses réelles, achats de marchandise compris » (budget) ne
  sont pas le même montant.
- **`groups`** (bilan d'articles, `articles.new`) : les articles réunis par verdict et par raison (d'où vient le coefficient proposé :
  `similar`, `supplier`, `site`, ou `null` sans verdict), avec leur nombre, un `label` qui est la phrase complète du groupe, prête à
  servir d'intertitre (lot W2-c : « 5 articles au coefficient constaté chez leur fournisseur : à garder », « 1 article sous le
  coefficient de son fournisseur : à monter »), tous leurs identifiants et 5 noms au plus. Rangés : à monter, à baisser, à garder, sans verdict ; le plus nombreux
  d'abord. **Le modèle ne compte rien.**
- **Une valeur de type `text` est rédigée** (lot W2-c) : l'état de la trésorerie, l'état du budget et le verdict d'un article rendent
  dans `value` le libellé que l'écran affiche (« Correct », « Sous le seuil », « Découvert prévu », « Aucun solde saisi » ; « Sans
  prévu », « Dans le budget », « Attention », « Dépassé » ; « À garder », « À monter », « À baisser ») et dans **`code`** le code
  (`ok`, `warning`, `critical`, `no_balance` ; `none`, `ok`, `warning`, `over` ; `keep`, `raise`, `lower`). Les lignes d'un tableau
  (`rows`) gardent leurs codes ; le modèle, lui, en reçoit les libellés. **Une date est donnée au modèle rédigée** (« 11 novembre
  2026 ») ; sa `value` reste au format AAAA-MM-JJ.
- **Trésorerie** (`cash.projection`, `days` de 7 à 180, 30 par défaut) : l'horizon est dit dans le libellé (« Point bas sur 90 jours »,
  « Solde prévu dans 90 jours ») ; au-delà de 14 jours, le point bas sur 14 jours de l'écran est rendu aussi (`lowest14Amount`,
  `lowest14Date`).
- **Panier moyen** : « Panier moyen (ventes avant retours ÷ commandes, hors taxes) ». Il ne se retrouve donc pas en divisant les
  ventes nettes par les commandes : un retour ne fait pas baisser le panier.
- `sales.overview` lit une plage (`range` : `today`, `7d`, `month`, `year`) ou une période (`periodId`, qui l'emporte). Sans l'un ni
  l'autre : le mois, comme l'écran des statistiques.

### Catalogue du premier lot

Les dix analyses sont au catalogue (`AnalysisCatalog::DEFINITIONS`). Paramètres admis, tous facultatifs sauf mention : `periodId`
(une période du site ; sans lui, la période en cours), `range` (`today`, `7d`, `month`, `year`), `year`, `days` (7 à 180),
`productId` (obligatoire pour `articles.vs_similar`). Un paramètre facultatif faux est écarté ; un obligatoire manquant ou faux écarte
l'analyse. Chaque chiffre a un identifiant unique dans une réponse : `a1.revenue` = le chiffre `revenue` de la première analyse lancée.

| Clé | Ce qu'elle dit | Source (écran, route) |
|---|---|---|
| `period.result` | résultat d'une période : ventes, coût des articles vendus, frais, résultat, ventes sans coût connu | période, `GET /api/periodes/{id}/resultat` |
| `sales.overview` | ventes, marge, panier, retours, avec la période précédente et l'an dernier | statistiques, `GET /api/gestion/statistiques` |
| `year.overview` | l'année ou l'exercice : cumul, rentabilité, point mort, écart | année, `GET /api/gestion/statistiques/annee` |
| `cash.projection` | solde, projection, date d'un passage sous le seuil | trésorerie, `GET /api/gestion/tresorerie` |
| `budget.status` | prévu et réel par poste | budget, `GET /api/periodes/{id}/budget` |
| `stock.overview` | valeur du stock, articles en stock bas | statistiques et vignettes |
| `articles.missing_cost` | articles sans coût, avec leurs ventes | articles, `GET /api/gestion/articles?filter=missing-cost` |
| `articles.vs_similar` | un article face à ses semblables de la même saison : part vendue, délai, marge obtenue, rabais | lot IA-ACT-5 |
| `articles.new` | les articles nouveaux de la période, chacun avec son bilan face à ses semblables et son verdict | lot IA-ACT-5 |
| `advice.current` | les conseils du guide du jour, tels quels | conseils, `GET /api/gestion/conseils` |

## 3. Comment une réponse se fabrique

Deux appels au modèle par réponse, pour **1 unité**.
1. **Choisir.** Le modèle reçoit la question, l'écran d'où elle vient s'il est donné (`context`, § 4), les 6 derniers messages de
   l'échange (texte seul, sans les données) et le catalogue (clés, titres, paramètres). Il rend, en sortie structurée : les analyses à lancer avec leurs paramètres (4 au plus), ou « hors
   périmètre », ou une demande de précision. Le serveur contrôle chaque clé et chaque paramètre contre le catalogue, puis exécute.
2. **Rédiger.** Le modèle reçoit les résultats des analyses et rend la réponse en blocs.

Le nombre d'appels est fixe et rien n'est exécuté qui ne soit dans la liste. Modèle : Sonnet pour les deux appels au départ, **une
constante nommée par appel** ; on jugera la qualité sur demo et on pourra monter le modèle de l'appel 2 sans recoder. 1 unité pour le
gérant dans tous les cas.

**Vérification des nombres** (celle du guide quotidien, `docs/guide-ia-gestion.md`) : tout nombre écrit dans un bloc doit être la valeur
d'un chiffre fourni, sous une écriture admise. Un bloc qui porte un nombre inconnu est rejeté ; un nouvel essai, puis un texte de repli
écrit par le serveur à partir des chiffres.

Comment c'est tenu, dans le code :
- le modèle ne reçoit pas des cents mais des **écritures** (`display` : « 25,00 $ », « 90 % », « 2,7 ») ; les nombres admis dans un
  texte sont ceux de ces écritures, des libellés et des noms rendus, et ceux de la question du gérant ;
- le modèle **n'écrit que du texte**. Les blocs `figures`, `table` et `verdicts` sont assemblés par le serveur à partir des résultats,
  par référence (`figureIds`, `analysis`) : un identifiant inconnu est retiré, un tableau d'une analyse non lancée est écarté ;
- un nombre est reconnu **par sa valeur**, dans toutes ses écritures : « 25,00 $ », « 25 $ » et « 25,00 » valent 25 ; « 1 234,56 » et
  « 1,234.56 » valent 1234,56 (`ConsoleResponder::numbers`) ;
- un texte au nombre inconnu vaut **un** nouvel essai de rédaction (trois appels au modèle au plus par réponse). S'il reste un nombre
  inconnu, la réponse est une **réponse de repli** : le texte du serveur et les chiffres des analyses. Elle est **gratuite**
  (`ConsoleResponder::CONSOLE_FALLBACK_CHARGED`, `false` par défaut : le gérant ne paie pas une réponse que l'IA n'a pas su écrire).
  Chaque repli est **compté** : la réponse garde la marque `fallback`, et le journal reçoit une ligne « [Console] repli » ; le nombre
  de replis par jour dit si le modèle de rédaction doit monter ;
- dans un bloc `verdicts`, l'explication (`why`) du modèle qui porte un nombre inconnu est remplacée par celle, chiffrée, du serveur.

**L'écran d'où vient la question (lot W2-b).** Le serveur traduit lui-même le `context` (§ 4.1) : l'appel 1 reçoit
`ecran: { code, titre, theme, params, analysesParDefaut }`, où `analysesParDefaut` sont les analyses de cet écran avec ses paramètres,
déjà contrôlées par le catalogue. Si la question parle de ce que le gérant regarde (« cet écran », « ici », « explique-moi »), le modèle
les prend telles quelles. L'appel 2 reçoit le titre et le thème de l'écran, pour le nommer. De plus, **ce que le modèle laisse vide est
pris à l'écran** : une analyse choisie sans période reçoit celle de l'écran (de même pour l'article et l'année) ; une période ou une
plage que le modèle nomme l'emporte.

**« Période » dans la question (lot W2-b).** Une question qui dit « période » lit les ventes sur la période, pas sur le mois :
`sales.overview` choisie sans `periodId` prend la période des autres analyses de la réponse, à défaut celle de l'écran, à défaut la
période en cours. Une durée nommée dans la question (aujourd'hui, jours, semaine, mois, année) garde la plage choisie. La consigne de
l'appel 1 le dit, et le serveur le garantit (`ConsoleResponder::salesOnThePeriod`).

**Une carte pour tout chiffre cité (lot W2-b).** Tout chiffre dont un texte parle a sa carte : le serveur ajoute, à la suite du bloc
`text`, un bloc `figures` pour les chiffres de ses `figureIds` qu'aucun bloc `figures` de la réponse ne montre (jamais deux cartes du
même chiffre). Si le modèle parle d'un chiffre sans le citer, le serveur le retrouve **par sa valeur** : un nombre du texte qui est la
valeur d'un seul chiffre des analyses le désigne ; de plusieurs, celui dont le libellé est dans le texte (« 11 commandes » : le chiffre
« Commandes ») ; sinon aucun, on ne devine pas. Une date ou un texte ne sont pas des nombres cités.

**Le bon texte, une seule carte (lot W2-c).** Un chiffre que le modèle cite dans `figureIds` ne compte que si le texte **en parle
vraiment** : sa valeur y est écrite (un nombre reconnu par sa valeur, une date ou un état sous leur forme rédigée), ou son libellé y
est écrit (le début du libellé : « budget prévu »). Sinon il est ignoré pour ce texte ; le texte qui en parle, lui, le retrouve par sa
valeur. Et quand deux analyses rendent **la même grandeur, de même valeur, sur la même plage** (les ventes de la période, dans le
résultat et dans les statistiques), c'est un seul chiffre : celui de la première analyse, sa source gardée ; une seule carte.

**Consignes de rédaction ajoutées (lot W2-b)** : ne jamais rapprocher deux mesures différentes sans dire ce que chacune compte ; nommer
une plage par son libellé recopié tel quel ; pour un bilan d'articles, une phrase par groupe (« 5 articles au coefficient de leur
fournisseur : à garder »), jamais la même raison répétée article par article, le détail restant dans le bloc `verdicts`.

**Verdicts** : quand une analyse rend un verdict (garder, monter ou baisser le coefficient d'un article), **c'est le serveur qui le
calcule**, avec la règle du coefficient validée par le propriétaire (lot IA-ACT-5). L'IA l'explique ; elle ne décide pas.

## 4. Forme d'un échange

Question : `{ text, conversationId?, context? }`, `text` de 1 000 caractères au plus.

**`context: { module, screen, params }`** (facultatif) : l'écran de la gestion d'où la question est posée, que le panneau du web reçoit
déjà de chaque écran (par exemple l'écran d'une période, avec son `periodId`).
- Il est donné à l'appel 1, traduit par le serveur (§ 3) : « Explique-moi ce tableau » choisit alors les analyses de l'écran affiché,
  avec ses paramètres.
- Il est contrôlé comme le reste : code d'écran connu (§ 4.1), paramètres déclarés pour cet écran et bien formés ; `periodId`,
  `productId` et `commandeId` doivent exister sur le site. **Un code inconnu est ignoré, sans erreur** (`context: null`, la question
  est traitée sans lui) ; un paramètre inconnu ou mal formé est écarté, l'écran restant gardé.
- Il est gardé dans le message, avec la question, sous la forme contrôlée `{ module, screen, params }`.

### 4.1 Les écrans connus (lot W2-b, `ConsoleScreens`)

Les codes sont ceux que l'application web envoie (36 codes relevés dans son code le 13/11/2026) ; **le web n'a rien à changer**. Chaque
code a un thème, des paramètres admis et des analyses par défaut ; un écran sans analyse propre reçoit celles de son thème.

| Thème | Analyses du thème | Écrans (paramètres) → analyses propres |
|---|---|---|
| `accueil` | `period.result`, `advice.current` | `gestion.home` → `sales.overview` (aujourd'hui), `advice.current`, `period.result` ; `sequences` ; `sequences.detail` (`key`, `instance`) ; `conseils` (`adviceId`) → `advice.current` ; `conversations` (`kind`) ; `conversation` (`conversationId`) |
| `achats` | `budget.status`, `articles.new`, `articles.missing_cost` | `commandes` (`periodId`, `commandeId`) → `budget.status`, `articles.new` ; `a-recevoir` → `stock.overview`, `articles.new` ; `a-classer` → `articles.missing_cost`, `articles.new` ; `fournisseurs` ; `frais-de-port` (`periodId`) → `budget.status`, `period.result` ; `transporteurs` ; `voyages` (`page`), `voyages.suivi` (`tripId`) → `budget.status` ; `articles` (`filter`, `from`, `to`) → `articles.missing_cost`, `articles.new` ; `documents.scan` (`type`, `documentId`) → `articles.new`, `budget.status` |
| `ventes` | `sales.overview`, `stock.overview` | `prix` (`adviceId`) → `articles.new`, `articles.missing_cost` ; `promotions` (`adviceId`) ; `locations.prix` (`adviceId`), `locations` (`range`) → `sales.overview` ; `publicite` (`page`, `channel`), `publicite.campagne` (`campaignId`), `campagnes.new` (`fromCampaignId`) → `sales.overview`, `budget.status` |
| `argent` | `cash.projection`, `budget.status` | `tresorerie` → `cash.projection` ; `frais-fixes` → `cash.projection`, `year.overview` ; `notes-de-frais` (`periodId`) → `budget.status`, `period.result` |
| `resultats` | `period.result`, `year.overview` | `periodes` (`page`) ; `periode` (`periodId`) → `period.result`, `sales.overview` ; `periode.budget` (`periodId`) → `budget.status` ; `periode.simulation` (`periodId`) → `period.result`, `budget.status` ; `preparation` → `period.result`, `cash.projection`, `budget.status` ; `statistiques` (`range`, `source`, `periodId`) → `sales.overview` ; `statistiques.annee` (`year`, `pricePercent`) → `year.overview` |
| `reglages` | `year.overview`, `articles.missing_cost` | `reglages` ; `reprise` |

- Un écran peut **fixer un paramètre** à une de ses analyses (lot W2-c) : `tresorerie` donne `days: 90` à `cash.projection` (l'écran
  montre le point bas sur 14 et 90 jours et le solde dans 90 jours), `gestion.home` donne `range: today` à `sales.overview`. Ce
  paramètre complète aussi l'analyse que le modèle choisit lui-même depuis cet écran, quand il le laisse vide ; ce qu'il nomme l'emporte.
- `theme` (`theme` : `achats`, `ventes`, `argent`, `resultats`, `reglages`) est la page d'un thème : elle reçoit les analyses du thème
  qu'elle montre.
- Une analyse par défaut reprend, parmi les paramètres de l'écran, ceux qu'elle déclare au catalogue (`periodId`, `productId`, `year`,
  `range`). Une période l'emporte sur une plage ; une plage que l'analyse ne connaît pas (`period`, `dates`, `30d`, `current`) est
  écartée. Une analyse dont un paramètre obligatoire manque n'est pas proposée.
- Types des paramètres : identifiant ou page (entier > 0), année (2000 à 2100), date (AAAA-MM-JJ), nombre, texte court sans espace
  (`key`, `instance`, `channel`), ou une liste de valeurs. `periodId`, `productId`, `commandeId` et `year` sont admis sur tout écran.
- Les codes du premier contrat restent acceptés (`accueil`, `journee`, `periode.resultat`, `periode.preparation`, `commande`,
  `article`, `stock`, `tresorerie.rapprochements`, `documents`, `simulateur`, `console`).
- Chaque écran et chaque thème a un titre écrit par le serveur dans les deux langues (`console.screens.*`, `console.themes.*`).
- Le guide rédigé de chaque écran (« à quoi sert cet écran ») viendra avec le lot W2-APPRENDRE.

La réponse arrive **en attente**, sur le modèle de la lecture de documents : la question rend tout de suite un message `pending`, un
worker répond, le web relit toutes les 3 secondes.

```
{ conversationId, messageId, state: "pending" | "done" | "failed", error?,
  blocks: [...], analyses: [...], proposals: [...], credits }
```

- `state` : `pending` (en cours), `done`, `failed` (délai maximal dépassé ou IA en échec : **aucune unité décomptée**, `error` dit pourquoi).
- `blocks`, dans l'ordre d'affichage :
  - `{ type: "text", text, figureIds }` ;
  - `{ type: "figures", items: [{ label, value, unit, source }] }` ; depuis le lot W2-b, le serveur en ajoute un à la suite de tout
    bloc `text` dont un chiffre cité n'a pas encore sa carte (§ 3) : même forme, rien à distinguer côté application ;
  - `{ type: "table", columns, rows, source }` ;
  - `{ type: "verdicts", items: [{ subject, verdict, why, figureIds }], groups: [{ verdict, source, count, label, productIds }] }`,
    pour un bilan. `groups` (lot W2-b) : les articles du bilan réunis par verdict et par raison, comptés par le serveur ; l'application
    peut en faire les titres de ses sections. Valeurs de `verdict` (précision du
    13/11/2026) : **`keep`** (garder), **`raise`** (monter), **`lower`** (baisser) : les trois valeurs de `CoefficientRule::verdict`,
    les seules du premier lot. `subject` est `{ type: "product", id, name }`. Une autre analyse pourra plus tard avoir ses propres
    verdicts : chaque clé nouvelle sera écrite ici avant d'être rendue, et l'application affiche une clé inconnue telle quelle, d'un
    ton neutre.
- `analyses` : `[{ key, ref, title, params, source: { screen, route, params }, period: { from, to, label, previousLabel?,
  lastYearLabel? } | null, computedAt }]`, pour afficher « d'où vient ce chiffre » et sur quelle plage (libellé rédigé, § 2).
- `proposals` : `[{ action, params, label }]`, 3 au plus. `action` est une clé du catalogue des actions ; `params` ne contient que des
  identifiants présents dans les résultats des analyses et passe le contrôle d'ouverture d'un fil ; une proposition invalide est
  retirée par le serveur. Le web ouvre le fil par `POST /api/gestion/conversations { action, params }`.
  La console peut proposer **toute action du catalogue** quand la question s'y prête ; l'accent est mis sur les ajustements globaux
  (`price.set`, plus tard une promotion de groupe), sans s'y limiter.
- **Les blocs sont la forme du rapport** : le lot DOC fabriquera le PDF à partir des blocs gardés, sans nouvel appel d'IA.

## 5. Routes

`ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion actif, palier d'IA du site.

| Route | Rôle |
|---|---|
| `POST /api/gestion/console/messages { text, conversationId?, context? }` | pose une question, avec l'écran d'où elle vient ; rend le message `pending` |
| `GET /api/gestion/console/conversations/{id}` | l'échange : questions et réponses, à relire toutes les 3 secondes tant qu'un message est `pending` |
| `GET /api/gestion/console/conversations` | l'historique du site, du plus récent au plus ancien |
| `DELETE /api/gestion/console/conversations/{id}` | le gérant efface un échange ; l'effacement est inscrit au journal |
| `GET /api/gestion/console/analyses` | le catalogue : clé, titre, paramètres, source |

### 5.1 Formes des réponses (précisions du 13/11/2026, demande du web)

**`POST /api/gestion/console/messages`** → 202, la réponse en préparation :

```
{ conversationId, questionMessageId, messageId, state: "pending",
  blocks: [], analyses: [], proposals: [], credits }
```

`questionMessageId` est le message de la question, `messageId` celui de la réponse à relire. Sans `conversationId`, un nouvel échange
est créé ; son titre est la première question, coupée à 80 caractères.

**`GET /api/gestion/console/conversations/{id}`** → l'échange entier, dans l'ordre :

```
{ id, title, createdAt, updatedAt, pending: true | false,
  messages: [
    { messageId, role: "user", text, context: { module, screen, params } | null, createdAt },
    { messageId, role: "assistant", replyTo, state: "pending" | "done" | "failed",
      error: { code, message } | null,
      blocks: [...], analyses: [...], proposals: [...],
      createdAt, answeredAt | null }
  ],
  credits }
```

- Un message `user` est toujours suivi de sa réponse `assistant` (`replyTo` = le `messageId` de la question). Les champs `blocks`,
  `analyses` et `proposals` sont ceux du § 4 ; ils sont vides tant que `state` vaut `pending`.
- `pending` (au niveau de l'échange) dit qu'une réponse est encore attendue : le web relit toutes les 3 secondes tant qu'il est vrai.
- `context` est celui que la question a envoyé, ou `null` s'il était absent ou a été ignoré (§ 4). Sans paramètre, il rend
  `params: {}` (un objet, lot W2-c).
- `error` n'est rempli que pour `state: "failed"` (codes plus bas).

**`GET /api/gestion/console/conversations?page=1&limit=20`** → un tableau, du plus récemment modifié au plus ancien, paginé comme la
liste des fils (`GET /api/gestion/conversations`) : en-têtes `X-Total-Count`, `X-Page`, `X-Limit` ; `limit` de 1 à 100, 20 par défaut.

```
[ { id, title, createdAt, updatedAt, questions, pending, lastState } ]
```

`title` : la première question, coupée à 80 caractères ; `questions` : le nombre de questions posées ; `lastState` : l'état de la
dernière réponse (`pending`, `done`, `failed`). La liste ne rend aucun bloc : le détail se lit par l'échange.

**`DELETE /api/gestion/console/conversations/{id}`** → 204. L'échange ne se lit plus (404) ; l'effacement est inscrit au journal.

**`GET /api/gestion/console/analyses`** → `[ { key, title, params: [{ name, type, required }], source: { screen, route } } ]`.

### 5.2 Codes d'erreur propres à la console

Forme habituelle des refus de la gestion : `{ error, code, message, … }`.

| Statut | Code | Quand |
|---|---|---|
| 404 | `CONSOLE_CONVERSATION_NOT_FOUND` | l'échange n'existe pas ou a été effacé (lecture, effacement, ou `conversationId` d'une question) |
| 409 | `CONSOLE_ANSWER_PENDING` | une question est posée, ou un effacement demandé, alors que la réponse précédente de cet échange est encore en préparation : une réponse à la fois par échange ; le corps porte `messageId` de la réponse attendue |
| 422 | `VALIDATION_FAILED` | `fields.text` : `REQUIRED`, `TOO_LONG` (plus de 1 000 caractères) ; `fields.conversationId` : `INVALID_ID` |
| 403 | `AI_NOT_INCLUDED` | le palier d'IA du site ne comprend pas la console |
| 402 | `AI_CREDITS_EXHAUSTED` | plus d'unités |
| 429 | `AI_DAILY_LIMIT` | plafond quotidien commun avec la phrase libre du fil ; le corps porte `limit` et `resetAt` |

Un **délai dépassé n'est pas un statut HTTP** : la question a été acceptée (202), et c'est la réponse qui passe à
`state: "failed"`, sans unité décomptée. `error.code` d'une réponse en échec :

| `error.code` | Quand |
|---|---|
| `CONSOLE_TIMEOUT` | aucune réponse au bout du délai maximal (120 secondes, une constante du serveur) |
| `AI_UNAVAILABLE` | le modèle n'a pas répondu ou sa réponse est inutilisable |

Après un échec, le gérant peut reposer sa question : c'est une nouvelle question, dans le même échange.

## 6. Unités, limites, conservation

- **1 unité par réponse rédigée par le modèle** ; aucune si l'IA échoue. Le propriétaire de la plateforme n'est pas décompté.
- **Gratuit** : une réponse de repli (§ 3) ; une question hors périmètre, à laquelle le serveur répond lui-même par un court texte qui dit ce que la console sait
  faire ; une demande de précision posée par le serveur (le modèle n'a désigné aucune analyse valable, ou sa demande de précision
  portait un nombre de son cru).
- L'unité est **réservée à la question** (402 s'il n'en reste plus) puis décomptée ou rendue par le worker. Délai dépassé : rendue.
- **Une demande de précision rédigée par le modèle coûte 1 unité**, comme toute réponse rédigée par lui.
- Palier d'IA du site (403 `AI_NOT_INCLUDED`), crédits (402 `AI_CREDITS_EXHAUSTED`), plafond quotidien commun avec la phrase libre du
  fil (429 `AI_DAILY_LIMIT`).
- Historique **par site**, gardé **12 mois**, dans les tables du fil déjà en place (un fil de type « console ») : pas de migration.
  Les échanges de plus de 12 mois sont retirés quand une question arrive. Le modèle reçoit les 6 derniers messages de l'échange, texte seul.
- **Lecture seule** : la console et ses analyses n'écrivent rien dans les données du site (garde-fou dans `ConsoleApiTest`). Le service
  n'écrit que ses propres échanges, et l'effacement d'un échange au journal (`gestion-console`).

## 7. Premier cas d'usage : « Fais le bilan de mes nouveaux articles »

1. Appel 1 : le modèle demande `articles.new` sur la période en cours.
2. Le serveur rend, par article : coût, prix, coefficient affiché, part vendue, délai, rabais, les mêmes chiffres pour ses semblables,
   et le verdict calculé par la règle du coefficient : `keep` (garder : moins d'un demi-cran, 0,1, entre son coefficient et celui
   que le serveur proposerait), `raise` (monter) ou `lower` (baisser). Un article sans coût ou sans prix n'a pas de verdict (`null`) :
   il est listé, sans entrer dans le bloc `verdicts`.
3. Appel 2 : un bloc `verdicts` (pour chacun : le verdict et le pourquoi chiffré), un texte de synthèse, une proposition `price.set` sur
   les articles « monter » et une autre sur les articles « baisser », avec les identifiants venus de l'analyse.
4. Le gérant ouvre une proposition : fil `price.set`, aperçu, empreinte, confirmation, annulation sous 24 heures.

## 8. Ce que le web peut préparer dès maintenant

- Le contexte de l'écran (`{ module, screen, params }`, déjà fourni par chaque écran depuis W0), à joindre à chaque question.
- Un panneau d'échange : zone de saisie, état « en cours » relu toutes les 3 secondes, état « échec » avec son motif.
- Le rendu des quatre types de blocs, avec pour chaque chiffre un lien vers sa source (`screen`, `params`).
- Les propositions : un bouton par proposition, qui ouvre le fil d'action déjà codé côté web.
- L'historique des échanges, et l'effacement d'un échange avec confirmation.
