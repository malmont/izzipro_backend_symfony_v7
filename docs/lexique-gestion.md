# Lexique de la gestion et console « prof » — lot W2-APPRENDRE (A-1 à A-4, codé le 13/11/2026)

Décision du propriétaire après le premier essai réel de la console : elle doit être un **prof**, pour qu'un gérant qui n'y connaît
rien puisse s'appuyer sur elle. Contrat validé par le chef de projet et le propriétaire le 13/11/2026.

**Principe : la leçon est écrite par le serveur, sans IA.** Définitions, illustrations chiffrées et questions de vérification sont
écrites dans le dépôt ou calculées par le serveur. Le modèle ne fait que désigner une notion (appel 1) et, s'il y a autre chose à
dire, rédiger autour (appel 2). Aucun nombre qu'il n'a pas reçu, exemple compris.

Code : `src/Gestion/Lexique/` (`LexiqueCatalog` : la structure ; `LexiqueTexts` : les textes ; `LexiqueIllustration` : la phrase
chiffrée ; `LexiqueService` : les routes), `Gestion/Controller/GestionLexiqueController`, textes dans
`translations/lexique.{fr,en}.yaml`. Dans la console : analyse `lexique.term` (`AnalysisCatalog`), bloc `lesson`
(`ConsoleResponder`). Test : `tests/Functional/Gestion/LexiqueApiTest.php`.

A-4 (13/11/2026) : le guide rédigé de chaque écran (§ 6 : `ScreenGuides`, `translations/ecrans.{fr,en}.yaml`, analyse
`screen.guide`). Hors lot : le suivi « vue, comprise, utilisée » par utilisateur, les mini-leçons, le bilan du mois : **rien n'est
enregistré par utilisateur ici.**

## 1. Les notions

62 notions : les **54 clés du glossaire du mobile**, avec leurs tirets et leurs définitions, et 8 ajouts (`tresorerie`, `taxes`,
`stock-dormant`, `budget`, `cout-d-achat`, `stock-bas`, `semblables`, `verdict`).

- **Une clé ne change jamais** : les applications s'y relient. Forme : minuscules et tirets.
- **Une notion = une seule définition partout** (serveur, web, mobile). On peut améliorer un texte, jamais une clé.
- L'ordre de `LexiqueCatalog::ENTRIES` est l'ordre du lexique ; il sert aussi d'ordre d'importance en mode « apprendre ».
- Les textes sont les mêmes pour tous les sites ; aucune table.

Ajouter une notion : une entrée dans `LexiqueCatalog::ENTRIES` (notions liées, analyse liée ou `null`, bonnes réponses) et son bloc
dans les **deux** fichiers de textes (`term`, `definition`, puis au besoin `synonyms`, `illustration`, `quiz`). Le test refuse une
notion sans texte dans une langue, une notion liée inconnue, une analyse ou un chiffre qui n'existent pas.

## 2. Routes (sans IA, gratuites, `ROLE_ADMIN`, `?locale=en` pour l'anglais)

| Route | Rend |
|---|---|
| `GET /api/gestion/lexique` | `{ version, entries: [...] }` |
| `GET /api/gestion/lexique/{key}` | l'entrée + `site` (chiffres du site et illustration) ; 404 `LEXIQUE_TERM_NOT_FOUND` |
| `GET /api/gestion/lexique/{key}/quiz` | `{ key, term, questions: [{ id, question, choices: [{ id, label }] }] }`, jamais la bonne réponse |
| `POST /api/gestion/lexique/{key}/quiz/{question}` `{ choice }` | `{ correct, correctChoice, explanation }` |

Une entrée :

```
{ key, term, short, definition, synonyms: [...],
  related: [{ key, term }],
  analysis: { key, params, figures: [id] } | null,
  screens: [code], quiz: n }
```

- `short` : la première phrase de la définition. `quiz` : le nombre de questions de vérification.
- `analysis` : l'analyse du catalogue de la console qui calcule les chiffres du site pour cette notion, avec ses paramètres et les
  chiffres à montrer (par exemple `taux-de-marque` → `sales.overview` du mois : taux, ventes, marge). 39 notions en ont une ; les 23
  autres (promotions, locations, arrondi…) rendent leur définition seule. `screens` : l'écran de cette analyse.
- **`version`** : 12 caractères, la même dans les deux langues ; elle change dès qu'un texte ou la structure change. Les applications
  gardent le lexique et le rechargent quand elle change.

`site` (dans `GET /lexique/{key}`), ou `null` sans analyse liée :

```
{ analysis: { key, title }, period: { from, to, label } | null,
  figures: [{ id, label, value, unit, term, source }],
  illustration: "Votre taux de marque, du 1er au 13 novembre 2026 : 44,2 %. Sur 100,00 $ vendus, il vous reste 44,20 $ après le coût d'achat." | null }
```

**L'illustration** est une phrase du serveur. Chaque nombre vient d'un chiffre de l'analyse liée, ou d'un calcul fait par le serveur
à partir d'eux (la part gardée sur 100) : aucun nombre inventé. Elle est accordée par le serveur (« 1 commande », « 2 commandes »).
Sans donnée utile (aucune vente, aucun budget, aucun solde), elle vaut `null` et la définition suffit. Quinze notions en ont une.

**Un prof a toujours un exemple quand des données existent (lot W2-d).** Les notions de ventes (ventes, marge, taux de marque,
panier moyen, commandes, taux de retour…) s'illustrent sur **le mois en cours** ; s'il n'a aucune vente, sur **la période en cours** ;
si elle n'en a pas non plus, sur **la dernière période terminée qui a des ventes** (4 périodes remontées au plus) ; à défaut, pas
d'exemple. L'illustration dit toujours sa plage : `period.phrase` vaut « ce mois-ci » ou « sur la période Automne 2026 », et la
phrase la reprend (« Chez vous, sur la période Été 2026 : … »). Code : `AnalysisCatalog::salesExample`.

**Questions de vérification** : écrites dans le lexique (21 questions pour 17 notions), jamais générées. Choix `a`, `b`, `c`. La
réponse est corrigée par le serveur ; rien n'est enregistré. 422 `choice` (`REQUIRED`, `INVALID_CHOICE`) ; 404
`LEXIQUE_TERM_NOT_FOUND`, `LEXIQUE_QUESTION_NOT_FOUND`.

## 3. `term` sur les chiffres

Chaque chiffre d'une analyse porte **`term`**, la clé de la notion qui l'explique, quand il en a une (`revenue` → `ventes`,
`grossMarginRate` → `taux-de-marque`, `breakEvenSales` → `point-mort`… : `LexiqueCatalog::FIGURE_TERMS`). Toute carte de chiffre peut
donc ouvrir sa définition, dans la console ou ailleurs. Le résultat de l'exercice (`year.overview`) renvoie à `resultat-entreprise`,
celui d'une période à `resultat`.

**Règle (lot A-4) : le libellé d'une carte reprend le terme du lexique de sa notion**, avec ce qu'elle compte si besoin : « Taux de
marque » (jamais « Taux de marge » sous une leçon « Taux de marque »), « Ventes sans coût connu (unités) », « Dépensé (réel), achats de
marchandise compris », « Résultat de l'entreprise ». Même règle en anglais (« Profit », « Average order
value », « Current markup multiplier »). Un chiffre qui mesure autre chose que la notion ne porte pas de `term` : les trois comptes
d'un bilan (« Articles à garder », « Articles à monter », « Articles à baisser ») disent ce qu'ils comptent et n'en portent pas. Le test compare
chaque libellé au terme, dans les deux langues.

## 4. Dans la console

- **11e analyse du catalogue : `lexique.term`**, paramètre `term` (une clé du lexique : le modèle reçoit la liste clé, terme,
  synonymes, et ne peut en désigner aucune autre ; une clé inconnue est écartée par le serveur). Elle rend la leçon (`lesson`) et
  lance elle-même l'analyse liée : ses chiffres gardent la source de cette analyse.
- **Bloc `lesson`**, assemblé par le serveur :
  `{ type: "lesson", key, term, definition, illustration, related: [{ key, term }], quiz, source }`. `quiz` > 0 : l'application peut
  proposer le bouton « Vérifier que j'ai compris » (routes du § 2).
- **Question de définition seule** (« c'est quoi le taux de marque ? ») : l'appel 1 ne choisit que des notions. Le serveur assemble
  alors la réponse lui-même : le bloc `lesson`, puis le bloc `figures` de ses chiffres. **Pas d'appel 2, 0 unité** (l'unité réservée
  est rendue). Une ligne « [Console] leçon » au journal et la marque `lesson` sur la réponse permettent de les compter.
- **Définition et état** (« c'est quoi la marge, et où en est la mienne ? ») : `lexique.term` + l'analyse d'état. La leçon passe
  devant, le modèle rédige le reste : appel 2, **1 unité**. Les nombres de la définition (« 20 $ », « 50 $ ») sont admis dans le texte.
- **Mode « apprendre »** : `POST /api/gestion/console/messages { …, mode: "learn" }`. Toute réponse reçoit alors, à la fin, les
  leçons des notions de ses chiffres montrés (par `term`), dans l'ordre du lexique, **3 au plus** par réponse (celles que la question
  demande comprises), jamais deux fois la même, illustrées par les chiffres de la réponse. Aucun appel au modèle ni unité de plus.
  Le mode est envoyé par l'application **à chaque question** ; le serveur ne le retient pas et ne le rend pas ; toute autre valeur
  est ignorée.
- `GET /api/gestion/ia` : `costs.lesson: 0`.

- **`screen.guide`** (12e analyse, lot A-4) : le guide de l'écran d'où vient la question (§ 6). L'écran est celui du **contexte** :
  le modèle ne donne aucun paramètre, et s'il nomme un autre écran le serveur ne l'écoute pas ; sans contexte connu, pas de guide.
  Bloc `{ type: "guide", screen, title, theme, purpose, points, next, source }`. Demandé **seul** (« explique-moi cet écran »,
  « qu'est-ce que je dois regarder ici ? »), il est assemblé par le serveur : le guide, puis les chiffres du moment de l'écran (ses
  analyses par défaut, 6 chiffres au plus par analyse), **sans appel 2, 0 unité**. Avec une autre analyse : le guide passe devant, le
  modèle rédige le reste (1 unité).
- **Un état qui n'est pas un jugement se dit par une phrase** (lot A-4) : pour un budget « sans prévu » ou une trésorerie « sans
  solde », le modèle reçoit « Aucun budget n'est prévu pour cette période », « Aucun solde n'a encore été saisi », à reprendre telles
  quelles, et non un état à glisser dans « est … ».

## 5. Unités

| Quoi | Unités |
|---|---|
| Routes du lexique et des questions de vérification | 0 (aucun appel au modèle) |
| Console, définition seule | 0 (un appel au modèle pour choisir, aucune rédaction) |
| Console, définition et autre analyse | 1 (réponse rédigée) |
| Leçons ajoutées par le mode « apprendre » | 0 de plus |
| Route du guide d'un écran ; console, guide seul | 0 |
| Console, guide et autre analyse | 1 (réponse rédigée) |

## 6. Les guides des écrans (lot A-4)

Pour chaque écran de l'application : **à quoi il sert, ce que veulent dire ses chiffres principaux, quoi faire ensuite**. Écrits par
le serveur pour un débutant, à partir du relevé de l'application web (36 écrans), dans les deux langues :
`translations/ecrans.{fr,en}.yaml`, une entrée par code d'écran (« . » et « - » remplacés par « _ »).

`GET /api/gestion/ecrans/{code}/guide?locale=fr|en` (sans IA, gratuit, `ROLE_ADMIN`) ; 404 `SCREEN_GUIDE_NOT_FOUND` :

```
{ screen, title, theme: { key, label }, purpose,
  points: [{ label, text, term }],
  next }
```

- `points` : de 1 à 6 par écran. **`term`** est la clé du lexique quand le point renvoie à une notion (l'identifiant du point dans le
  fichier est alors cette clé), sinon `null` : 78 points sur 113 sont reliés.
- **Quand le libellé de l'écran diffère du terme du lexique, le texte du point fait le lien** (« Taux de marge = Le taux de marque
  des articles de la commande… ») : le débutant relie les deux mots. Le test l'exige pour chaque point relié, dans les deux langues.
- Les codes du premier contrat de la console (`accueil`, `periode.resultat`, `article`…) rendent le guide de l'écran du web qui leur
  correspond (`ScreenGuides::ALIASES`).
- Ajouter ou corriger un guide : son bloc dans les **deux** fichiers (`purpose`, `points.<id>.label` et `.text`, `next`), mêmes
  identifiants de points des deux côtés.
