# Console IA générale de la gestion — contrat du lot W2 (validé le 13/11/2026, **pas encore codé**)

Cette fiche est le contrat validé par le propriétaire le 13/11/2026. Le backend le codera après le lot IA-ACT-5 ; le web peut déjà
planifier son panneau dessus. Tant que la ligne « État » ci-dessous dit « à coder », **aucune des routes de cette fiche n'existe**.

**État : à coder (après IA-ACT-5).**

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

Les dix analyses entrent au premier lot quand leur calcul existe déjà ; sinon les six premières avec `articles.new` et
`articles.vs_similar`, les deux dernières au lot suivant.

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
  - `{ type: "verdicts", items: [{ subject, verdict, why, figureIds }] }`, pour un bilan.
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

## 6. Unités, limites, conservation

- **1 unité par réponse rédigée par le modèle** ; aucune si l'IA échoue. Le propriétaire de la plateforme n'est pas décompté.
- **Gratuit** : une question hors périmètre, à laquelle le serveur répond lui-même par un court texte qui dit ce que la console sait
  faire ; une demande de précision posée par le serveur.
- **Une demande de précision rédigée par le modèle coûte 1 unité**, comme toute réponse rédigée par lui.
- Palier d'IA du site (403 `AI_NOT_INCLUDED`), crédits (402 `AI_CREDITS_EXHAUSTED`), plafond quotidien commun avec la phrase libre du
  fil (429 `AI_DAILY_LIMIT`).
- Historique **par site**, gardé **12 mois**, dans les tables du fil déjà en place (un fil de type « console ») : pas de migration.

## 7. Premier cas d'usage : « Fais le bilan de mes nouveaux articles »

1. Appel 1 : le modèle demande `articles.new` sur la période en cours.
2. Le serveur rend, par article : coût, prix, coefficient affiché, part vendue, délai, rabais, les mêmes chiffres pour ses semblables,
   et le verdict (garder, monter, baisser) calculé par la règle du coefficient.
3. Appel 2 : un bloc `verdicts` (pour chacun : le verdict et le pourquoi chiffré), un texte de synthèse, une proposition `price.set` sur
   les articles « monter » et une autre sur les articles « baisser », avec les identifiants venus de l'analyse.
4. Le gérant ouvre une proposition : fil `price.set`, aperçu, empreinte, confirmation, annulation sous 24 heures.

## 8. Ce que le web peut préparer dès maintenant

- Le contexte de l'écran (`{ module, screen, params }`, déjà fourni par chaque écran depuis W0), à joindre à chaque question.
- Un panneau d'échange : zone de saisie, état « en cours » relu toutes les 3 secondes, état « échec » avec son motif.
- Le rendu des quatre types de blocs, avec pour chaque chiffre un lien vers sa source (`screen`, `params`).
- Les propositions : un bouton par proposition, qui ouvre le fil d'action déjà codé côté web.
- L'historique des échanges, et l'effacement d'un échange avec confirmation.
