# Lexique de la gestion et console « prof » — lot W2-APPRENDRE (A-1 à A-3, codé le 13/11/2026)

Décision du propriétaire après le premier essai réel de la console : elle doit être un **prof**, pour qu'un gérant qui n'y connaît
rien puisse s'appuyer sur elle. Contrat validé par le chef de projet et le propriétaire le 13/11/2026.

**Principe : la leçon est écrite par le serveur, sans IA.** Définitions, illustrations chiffrées et questions de vérification sont
écrites dans le dépôt ou calculées par le serveur. Le modèle ne fait que désigner une notion (appel 1) et, s'il y a autre chose à
dire, rédiger autour (appel 2). Aucun nombre qu'il n'a pas reçu, exemple compris.

Code : `src/Gestion/Lexique/` (`LexiqueCatalog` : la structure ; `LexiqueTexts` : les textes ; `LexiqueIllustration` : la phrase
chiffrée ; `LexiqueService` : les routes), `Gestion/Controller/GestionLexiqueController`, textes dans
`translations/lexique.{fr,en}.yaml`. Dans la console : analyse `lexique.term` (`AnalysisCatalog`), bloc `lesson`
(`ConsoleResponder`). Test : `tests/Functional/Gestion/LexiqueApiTest.php`.

À venir : A-4, le guide rédigé de chaque écran (analyse `screen.guide`). Hors lot : le suivi « vue, comprise, utilisée » par
utilisateur, les mini-leçons, le bilan du mois : **rien n'est enregistré par utilisateur ici.**

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

**Questions de vérification** : écrites dans le lexique (21 questions pour 17 notions), jamais générées. Choix `a`, `b`, `c`. La
réponse est corrigée par le serveur ; rien n'est enregistré. 422 `choice` (`REQUIRED`, `INVALID_CHOICE`) ; 404
`LEXIQUE_TERM_NOT_FOUND`, `LEXIQUE_QUESTION_NOT_FOUND`.

## 3. `term` sur les chiffres

Chaque chiffre d'une analyse porte **`term`**, la clé de la notion qui l'explique, quand il en a une (`revenue` → `ventes`,
`grossMarginRate` → `taux-de-marque`, `breakEvenSales` → `point-mort`… : `LexiqueCatalog::FIGURE_TERMS`). Toute carte de chiffre peut
donc ouvrir sa définition, dans la console ou ailleurs. Le résultat de l'exercice (`year.overview`) renvoie à `resultat-entreprise`,
celui d'une période à `resultat`.

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

## 5. Unités

| Quoi | Unités |
|---|---|
| Routes du lexique et des questions de vérification | 0 (aucun appel au modèle) |
| Console, définition seule | 0 (un appel au modèle pour choisir, aucune rédaction) |
| Console, définition et autre analyse | 1 (réponse rédigée) |
| Leçons ajoutées par le mode « apprendre » | 0 de plus |
