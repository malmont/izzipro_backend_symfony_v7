# Console IA générale de la gestion — lot W2 (contrat validé et codé le 13/11/2026)

Cette fiche est le contrat validé par le propriétaire le 13/11/2026, et la description de ce qui est codé.

**État : codé (lot W2, 13/11/2026).** Code : `src/Gestion/Analysis/AnalysisCatalog.php` (les analyses), `src/Gestion/Services/Console/`
(`ConsoleService` : les routes ; `ConsoleModel` : les deux appels au modèle ; `ConsoleResponder` : la réponse, dans le worker
`gestion_ai`), `Gestion/Controller/GestionConsoleController`, message `AnswerConsoleQuestionMessage`. Aucune table de plus : les
échanges sont dans les tables du fil (`gestion_conversation`, type `console`) et ne sont jamais rendus par les routes du fil.
Test : `tests/Functional/Gestion/ConsoleApiTest.php` (modèle simulé).

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
{ key, title, period: { from, to, label } | null,
  figures: [{ id, label, value, unit, previous?, lastYear?, delta?, deltaPercent?, rank? }],
  rows: [{ ... }],            // lignes d'un classement ou d'une liste, bornées
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

**Verdicts** : quand une analyse rend un verdict (garder, monter ou baisser le coefficient d'un article), **c'est le serveur qui le
calcule**, avec la règle du coefficient validée par le propriétaire (lot IA-ACT-5). L'IA l'explique ; elle ne décide pas.

## 4. Forme d'un échange

Question : `{ text, conversationId?, context? }`, `text` de 1 000 caractères au plus.

**`context: { module, screen, params }`** (facultatif) : l'écran de la gestion d'où la question est posée, que le panneau du web reçoit
déjà de chaque écran (par exemple l'écran d'une période, avec son `periodId`).
- Il est donné à l'appel 1 : « Explique-moi ce tableau » choisit alors les analyses de l'écran affiché, avec ses paramètres.
- Il est contrôlé comme le reste : écran connu de la gestion, paramètres qui sont des identifiants existants du site. **Un contexte
  inconnu ou invalide est ignoré, sans erreur** : la question est traitée sans lui.
- Il est gardé dans le message, avec la question.

La réponse arrive **en attente**, sur le modèle de la lecture de documents : la question rend tout de suite un message `pending`, un
worker répond, le web relit toutes les 3 secondes.

```
{ conversationId, messageId, state: "pending" | "done" | "failed", error?,
  blocks: [...], analyses: [...], proposals: [...], credits }
```

- `state` : `pending` (en cours), `done`, `failed` (délai maximal dépassé ou IA en échec : **aucune unité décomptée**, `error` dit pourquoi).
- `blocks`, dans l'ordre d'affichage :
  - `{ type: "text", text, figureIds }` ;
  - `{ type: "figures", items: [{ label, value, unit, source }] }` ;
  - `{ type: "table", columns, rows, source }` ;
  - `{ type: "verdicts", items: [{ subject, verdict, why, figureIds }] }`, pour un bilan. Valeurs de `verdict` (précision du
    13/11/2026) : **`keep`** (garder), **`raise`** (monter), **`lower`** (baisser) : les trois valeurs de `CoefficientRule::verdict`,
    les seules du premier lot. `subject` est `{ type: "product", id, name }`. Une autre analyse pourra plus tard avoir ses propres
    verdicts : chaque clé nouvelle sera écrite ici avant d'être rendue, et l'application affiche une clé inconnue telle quelle, d'un
    ton neutre.
- `analyses` : `[{ key, params, source: { screen, route, params }, computedAt }]`, pour afficher « d'où vient ce chiffre ».
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
- `context` est celui que la question a envoyé, ou `null` s'il était absent ou a été ignoré (§ 4).
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
