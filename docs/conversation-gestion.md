# Facture et actions par conversation — application de gestion (lots IA-FACT-1, IA-FACT-2-A, IA-FACT-2-B, IA-ACT-1 et IA-ACT-2, 13/11/2026)

Un **fil de conversation gardé par le serveur** mène une facture fournisseur de la pièce jointe à la commande : attente de la lecture dans
le fil, résumé, questions sur ce qui manque, aperçu, confirmation, **annulation d'un bloc**. Règle du propriétaire : *aucune écriture
préparée sans annulation possible*.

**Qui fait quoi.** Le serveur calcule tout : le résumé, les questions (à partir des trous qu'il connaît), l'aperçu, les montants,
l'empreinte. Répondre par un bouton est gratuit et n'appelle aucune IA. Dans ce lot, le seul appel d'IA du parcours est la lecture du
document (1 unité, inchangée). L'ajout à une commande ouverte est livré par IA-FACT-2-A (§ 7) ; la phrase libre interprétée par l'IA
(1 unité par réponse) par IA-FACT-2-B (§ 8). L'IA ne fournit jamais un montant, un article ou un fournisseur qui ne vient pas du
document ou du catalogue. Depuis IA-ACT-1 (§ 9), le même fil mène aussi une **action préparable** (note de frais, fournisseur, frais
de port, budget), sans document.

Code : `src/Gestion/Services/Conversation/` (`GestionConversationService`, `InvoiceQuestions`), `src/Gestion/Services/Document/GestionDocumentUndoService`,
`src/Gestion/UseCase/GestionConversationUseCase`, `src/Gestion/Controller/GestionConversationController`, entités `GestionConversation` et
`GestionConversationMessage` (tables `gestion_conversation`, `gestion_conversation_message` ; migration `Version20261227100000`, script
`scripts/migrate_all_v2_gestion_conversations.sh`). Textes : `translations/gestion.{fr,en}.yaml`, `conversation.*`. Tests :
`tests/Functional/Gestion/ConversationApiTest.php`. Lecture des documents : `docs/lecture-documents-gestion.md`.

## 1. Routes

`ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion actif. `?locale=fr|en` (sinon la langue du fil, donnée à sa création).

| Route | Rôle |
|---|---|
| `POST /api/gestion/conversations` | Ouvre le fil d'une facture. Multipart `files[]` (ou `file`), `text?`, `locale?` : le document est créé par le même chemin que `POST /api/gestion/documents` (mêmes formats, mêmes refus 422 `DOCUMENT_INVALID`, 402 `AI_CREDITS_EXHAUSTED`, 403 `AI_NOT_INCLUDED`, 429, 409 `DOCUMENT_IN_PROGRESS`, même crédit). Ou JSON `{ documentId, text? }` pour un document déjà envoyé. **201** le fil créé ; **200** le fil déjà ouvert pour ce document (**un seul fil actif par document**). 422 `VALIDATION_FAILED { fields: { files: REQUIRED } }` sans fichier ni document ; 404 `DOCUMENT_NOT_FOUND` ; 422 `DOCUMENT_TYPE` pour une note ou un transport. |
| `GET /api/gestion/conversations/{id}` | Le fil (§ 2). À interroger toutes les 3 à 5 s pendant la lecture. Ne crée rien. 404 `CONVERSATION_NOT_FOUND`. |
| `POST …/{id}/answers` | `{ answers: [{ questionId, value }] }` → le fil à jour. Gratuit, aucune IA. `value: null` retire une réponse. 422 `VALIDATION_FAILED { fields: { "answers[i].questionId": UNKNOWN_QUESTION, "answers[i].value": <code> } }` ; 409 `CONVERSATION_NOT_OPEN { state }` (lecture en cours ou en échec, facture confirmée, fil abandonné). |
| `POST …/{id}/messages` | `{ text }` : une phrase libre, traduite par l'IA en réponses aux questions (§ 8) → 200 `{ reply, applied, ignored, thread }`. **1 unité** par réponse de l'IA. |
| `POST …/{id}/confirm` | `{ previewHash }` → **201**, le résultat de la confirmation d'une facture (`docs/lecture-documents-gestion.md` § 4), plus `undo` et `thread`. 409 `CONVERSATION_NOT_READY { state, openQuestions, issues }` ; **409 `PREVIEW_CHANGED { preview, previewHash }`** si l'aperçu a changé depuis son affichage ; les autres refus sont ceux de la confirmation (`PERIOD_CLOSED`, `INVOICE_ALREADY_CONFIRMED`…). |
| `POST …/{id}/undo` | Annulation d'un bloc (§ 5) → 200 `{ undone, thread }` : le fil revient à l'aperçu, avec ses réponses. |
| `POST …/{id}/abandon` | Abandon du fil par le gérant (lot IA-ACT-1, facture ou action) → 200 le fil, `state: abandoned` ; rien n'est créé, le document d'une facture reste et un autre fil peut le reprendre. 409 `CONVERSATION_NOT_OPEN { state }` si le fil est déjà confirmé ou abandonné. |
| `GET /api/gestion/conversations?page=&limit=&kind=` | `kind` (lot IA-ACT-1) : `invoice` ou la clé d'une action ; autre valeur → 422 `{ fields: { kind: UNKNOWN_KIND } }`. Liste légère, du plus récent au plus ancien : `[{ id, kind, state, document: { id, status, commandeId }, createdAt, updatedAt }]` (`state` sans le calcul des questions : `questions` vaut aussi pour un fil prêt) ; `X-Total-Count`, `X-Page`, `X-Limit`. |
| `POST /api/gestion/documents/{id}/annuler-confirmation` | La même annulation, hors conversation, pour l'écran de vérification → 200 `{ undone, document }`. |

## 2. Le fil

```
{ id, kind: "invoice", state, locale, createdAt, updatedAt,
  document: { id, status, error, commandeId } | null,
  summary: { text, supplier, number, date, lines, total, currency, openQuestions } | null,
  messages: [{ id, role: user | assistant, kind, content, createdAt }],
  questions: [...], openQuestions,
  preview | null, previewHash | null,
  undo: { available, until, blockers: [{ code, message }] } | null,
  credits }
```

- `state` : `reading` (lecture en cours) · `failed` (lecture en échec, `document.error` : relire par `POST /api/gestion/documents/{id}/relire`) ·
  `questions` (il manque une réponse obligatoire, ou l'aperçu signale un refus dans `preview.issues`) · `ready` (aperçu prêt, `previewHash`
  rendu) · `confirmed` · `abandoned`.
- `summary` : le résumé de la lecture, **écrit par le serveur** à partir des chiffres lus (gratuit) ; `text` dans la langue demandée.
- `messages` : ce que le gérant a envoyé ou décidé, et ce que le serveur a constaté, dans l'ordre. `kind` : `document` (pièce jointe),
  `text` (`role: user` : la phrase du gérant, `{ text }` ; `role: assistant` : la réponse de l'IA, `{ text, applied, ignored }`, § 8),
  `answers` (`{ answers }`, avec `source: "ai"` quand elles viennent d'une phrase libre), `confirmed`, `undone`, `abandoned`. Le résumé, les questions et l'aperçu ne sont pas des messages : ils sont
  recalculés à chaque lecture.
- `preview` : exactement la réponse de `POST /api/gestion/documents/{id}/apercu` (`docs/lecture-documents-gestion.md` § 4 bis) pour le
  corps bâti depuis la lecture et les réponses. `previewHash` : empreinte de ce corps et de ce que l'aperçu en a calculé (lignes, totaux,
  taux, devise). La confirmation recalcule tout et refuse si elle diffère. Même modèle que les prix et la préparation d'une période.
- `undo` : null tant que rien n'est confirmé ; `blockers` dit ce qui empêcherait l'annulation aujourd'hui (§ 5).
- `credits` : les unités d'IA du site.

## 3. Les questions

Calculées par le serveur à chaque lecture. Toutes sont rendues, avec leur réponse (`answer`, null tant qu'il n'y en a pas) : le gérant
peut en changer. Forme : `{ id, kind, path, title, text, required, input: { type: choice | date | money | number, … }, options: [{ value,
label, score? }], answer }`. `path` est le champ de la confirmation que la question renseigne.

| `id` | `kind` | Posée quand | `value` attendue | Refus de la réponse |
|---|---|---|---|---|
| `supplier` | `supplier` | **toujours**, en premier. Fournisseur reconnu par la lecture : la question arrive répondue (`answer: { id }`, **`proposed: true`** : réponse proposée par le serveur, hors `openQuestions`) et le gérant peut en choisir un autre (IA-FACT-2-A) ; non reconnu : `answer` null, `proposed: false` | `{ id }` (un candidat) ou `{ create: { name, typeFournisseurId?, currency? } }` | `FOURNISSEUR_NOT_FOUND`, `INVALID_CURRENCY`, `INVALID_VALUE` |
| `target` | `target` | le fournisseur a au moins une commande ouverte admise (§ 7) ; **juste après `supplier`** | `"new"` ou `{ commandeId }` (une des options) | `INVALID_VALUE` |
| `invoice.date` | `invoice.date` | la date n'a pas été lue | `AAAA-MM-JJ` | `INVALID_DATE` |
| `amountsMode` | `amountsMode` | le document ne dit pas si les prix sont hors taxes | `ht` ou `ttc` (`ttc` : chaque ligne taxable est ramenée hors taxes au taux du document) | `INVALID_VALUE` |
| `line:<clé>` | `line.product` | une ligne n'a pas d'article reconnu ; `<clé>` = `n<numéro>` ou la clé de groupe (tailles et couleurs d'un même article : une seule question) | `{ productId, variantId? }` ou `{ create: { name, coefficient? } }` | `PRODUCT_NOT_FOUND`, `VARIANT_NOT_IN_PRODUCT`, `MUST_BE_POSITIVE`, `INVALID_VALUE` |
| `unitCost:<n>` | `line.unitCost` | le coût d'une ligne n'a pas été lu | entier, en cents de la devise de la facture | `INVALID_AMOUNT` |
| `exchangeRate` | `exchangeRate` | la devise de la facture (celle du fournisseur) n'est pas celle du site | nombre > 0 | `MUST_BE_POSITIVE` |
| `period` | `period` | la date de la facture tombe dans une période fermée | `today` (classer à aujourd'hui) ou `abandon` | `INVALID_VALUE` |
| `duplicate` | `duplicate` | même fournisseur et même numéro qu'une facture déjà confirmée (`duplicate: { documentId, commandeId }`) | `force` (confirmer quand même) ou `abandon` | `INVALID_VALUE` |
| `shipping` | `shipping` | la facture porte un frais de port et la commande cible en a déjà un (§ 7) | `add` ou `skip` | `INVALID_VALUE` |
| `difference` | `difference` | le total lu diffère du total recalculé | `accept` (à reconnaître avant l'aperçu) | `INVALID_VALUE` |

Une réponse peut faire naître une question (une date donnée qui tombe dans une période fermée). Plusieurs réponses d'un même envoi
s'appliquent **dans l'ordre**, chacune contrôlée sur les questions telles que la précédente les a laissées (on peut envoyer ensemble
`target` puis `shipping`). Répondre `abandon` ferme le fil sans rien
créer ; le document reste utilisable, et un nouveau fil peut s'ouvrir pour lui. Une ligne dont l'article a été reconnu ne pose pas de
question ; l'aperçu montre l'article retenu.

## 4. Confirmation

La confirmation du fil est celle d'une facture, vers une **nouvelle commande** ou, depuis IA-FACT-2-A, vers une commande ouverte du même
fournisseur (§ 7). Elle note sur le document tout ce qu'elle a créé (`confirmed.undo`, interne : la commande, ses lignes d'achat, les articles et
variantes créés, le fournisseur créé, le frais de port). Depuis ce lot, **toute** confirmation de facture le note, par le fil comme par
`POST /api/gestion/documents/{id}/confirmer` ; la lecture d'un document rend `undo: { available, until }` et `confirmed` sans cette clé.

Dans le guide des séquences (`docs/sequences-gestion.md`), l'action `document.invoice.confirm` porte désormais `preparable: true`.

## 5. Annulation d'un bloc

`POST /api/gestion/conversations/{id}/undo` ou `POST /api/gestion/documents/{id}/annuler-confirmation` → `undone: { commandeId, removed:
{ lines, products, shipping, supplier }, kept }`.

- Retire **exactement** ce que la confirmation a créé, **tout ou rien** (une transaction) : la commande, ses lignes d'achat, son frais de
  port, les articles créés (et leurs variantes). Les articles déjà au catalogue ne changent pas ; leurs raccourcis d'achat sont recalculés.
  Le fournisseur créé par la confirmation n'est retiré que s'il ne sert à aucune autre commande ; sinon il est gardé (`kept: ["supplier"]`),
  sans bloquer.
- Le document revient à « lu » (`extracted`), avec ses fichiers, son brouillon et une nouvelle échéance de conservation ; le fil revient à
  l'aperçu.
- Dans les **24 heures** qui suivent la confirmation (409 `UNDO_EXPIRED { until }`), **une seule fois** par confirmation (409
  `ALREADY_UNDONE` ; 409 `DOCUMENT_NOT_CONFIRMED` si rien n'a jamais été confirmé). 409 `UNDO_UNAVAILABLE` pour une confirmation d'avant
  ce lot (rien n'a été noté) ou un autre type de document.
- **409 `UNDO_BLOCKED { reasons: [{ code, message }] }`** dès que quelque chose a bougé depuis : `PERIOD_CLOSED`, `COMMANDE_CLOSED`,
  `COMMANDE_CHANGED` (lignes ajoutées, retirées ou modifiées), `LINE_RECEIVED` (une ligne reçue en stock), `SHIPPING_CHANGED`,
  `PRODUCT_SOLD`, `PRODUCT_CHANGED` (article créé modifié, mis en vente, reçu ou racheté), et **`LATER_DOCUMENT`** : une autre facture a
  été ajoutée à la même commande après celle-ci. On annule dans l'**ordre inverse** des ajouts ; ce blocage nomme la facture à annuler
  d'abord (« Annulez d'abord la facture n° X ajoutée à cette commande le … ») et porte `documentId`, `invoiceNumber` et `confirmedAt`,
  pour que l'application ouvre son fil.
- Journal : `content_audit_log`, ressource `gestion-documents`, actions `confirm` et `undo` (l'annulation garde ce qui avait été confirmé
  et créé).

## 6. Données et conservation

`gestion_conversation` (document, statut `open` | `abandoned`, réponses, langue, auteur) et `gestion_conversation_message`. Un index
unique garantit un seul fil ouvert par document. Les fils et leurs messages disparaissent avec leur document (clé étrangère ON DELETE
CASCADE) : suppression, expiration à 30 jours sans confirmation, purge d'un justificatif orphelin.

## 7. Ajouter la facture à une commande ouverte (lot IA-FACT-2-A, 13/11/2026)

Décision du propriétaire : à la confirmation, le gérant choisit entre une **nouvelle commande** et l'**ajout à une commande ouverte du
même fournisseur**. Sans migration.

**Commandes admises** (`Services/Document/InvoiceTargetPolicy`, une seule règle pour la question, l'aperçu et la confirmation) : même
fournisseur, commande ordinaire (pas une commande de reprise d'historique), non figée, datée dans une période ouverte, dans la devise
du fournisseur.

**La question `target`**, posée seulement si le fournisseur a au moins une commande admise, juste après `supplier` : options
`{ value: "new" }` puis, par commande admise de la plus récente à la plus ancienne, `{ value: { commandeId }, label, date, budget, currency,
linesCount }`. Elle est recalculée à chaque lecture : changer de fournisseur la recalcule ou la retire, et une réponse donnée pour un
autre fournisseur, ou pour une commande qui n'est plus admise, est oubliée (la question se repose).
- Répondre une commande **retire les questions `period` et `exchangeRate`** : la date, la période et le taux de change sont ceux de la
  commande (l'aperçu montre son taux) ; la date de la facture reste sur le document. Revenir à `"new"` les fait réapparaître.
- **Frais de port** : posé si la commande n'en a pas. Si elle en a déjà un, la question `shipping` (avec `shipping: { current, added,
  total }` en cents) propose `add` (« Ajouter 12,00 EUR au frais de port actuel (8,00 EUR) → 20,00 EUR ») ou `skip` (« Ne pas compter
  les 12,00 EUR de port de cette facture »).

**Ce que fait la confirmation** avec `target: { commandeId }` dans le corps (accepté aussi par `POST /api/gestion/documents/{id}/confirmer`
et `…/apercu`, avec `shipping: { amount, mode: "add" }`) :
- **Un article n'a qu'une ligne d'achat par commande** (une par variante) : article absent → nouvelle ligne à recevoir ; article déjà
  présent → la ligne **grossit**, quantités additionnées, **coût moyen pondéré**, quantité déjà reçue inchangée
  (`CommandeLineService::add`). Les articles nouveaux sont créés comme d'habitude (stock 0, invisibles).
- Le budget de la commande augmente du total ajouté (lignes et port ajouté). La commande garde sa date, son nom et son justificatif
  d'origine (`document`) ; le document ajouté porte `commandeId`.
- Refus : 404 `COMMANDE_NOT_FOUND` ; 409 `TARGET_NOT_ALLOWED { reason, commandeId }`, `reason` : `SUPPLIER_MISMATCH`, `REPRISE`,
  `COMMANDE_CLOSED`, `PERIOD_CLOSED`, `CURRENCY_MISMATCH` ; 409 `SHIPPING_COST_ALREADY_SET` si la facture porte un port, que la
  commande en a déjà un et que `shipping.mode` n'est pas `add`.

**L'aperçu** porte `target` : null pour une nouvelle commande ; sinon `{ commandeId, name, date, currency, exchangeRate, budgetBefore,
budgetAfter, shipping: { current, added, total } }`, et chaque ligne d'un article existant porte `lineBefore` (`{ quantity, unitCost }`
ou null) et `lineAfter` (après l'ajout). Une cible refusée : `target: { commandeId, refused: <raison> }` et le refus `target`
(`TARGET_NOT_ALLOWED` ou `COMMANDE_NOT_FOUND`) dans `issues`. L'empreinte couvre la cible.

**Les factures d'une commande** : `GET /api/commandes/{id}/documents` → `[{ id, type, invoiceNumber, invoiceDate, confirmedAt, added,
lines: [{ lineId, productId, variantId, quantity, unitCost }], undo: { available, until } }]`, de la première à la dernière ajoutée.
`added` : vrai pour une facture ajoutée à une commande existante. `lines` est l'**apport** de la facture : pour une ligne partagée, la
quantité qu'elle y a ajoutée, à son propre coût. Il n'y a pas de colonne « document » sur la ligne d'achat : elle serait fausse dès qu'une
ligne est partagée par deux factures.

**Annulation d'un ajout** : la confirmation note, pour chaque ligne qu'elle a touchée, son état d'avant (ou « créée »), le budget et le
frais de port d'avant. L'annulation remet exactement cela (`undone: { commandeId, mode: "add", removed: { lines, products, shipping,
supplier }, restored: { lines, shipping, budget }, kept }`) : lignes créées retirées, lignes grossies ramenées à leur quantité et à leur
coût d'avant, budget et port remis, articles créés retirés ; la commande reste. Mêmes règles que le § 5 (24 heures, une fois, tout ou
rien) ; refusée si une ligne touchée a changé ou a été reçue depuis, ou si une facture a été ajoutée après (`LATER_DOCUMENT`).

## 8. La phrase libre, par l'IA (lot IA-FACT-2-B, 13/11/2026)

`POST /api/gestion/conversations/{id}/messages { text }` (1000 caractères au plus) → 200
`{ reply, applied: [{ questionId, value }], ignored: [{ questionId, reason }], thread }`.

**Ce que fait l'IA** (`Services/Conversation/ConversationInterpreter`, `claude-sonnet-5-5`, sortie structurée) : elle reçoit la phrase du
gérant et les questions du fil (titre, texte, options numérotées), et rend des réponses à ces questions, plus une courte phrase (`reply`).
Rien d'autre : elle ne confirme, n'annule et n'abandonne jamais ; confirmer reste un geste du gérant, avec l'empreinte.

**Garde-fous, appliqués par le serveur après la réponse du modèle** (une réponse écartée est rendue dans `ignored`, avec sa raison) :
- question à choix : seulement une option proposée, désignée par son rang (`NOT_AN_OPTION`) ;
- création d'un fournisseur ou d'un article sous un autre nom que celui proposé : seulement un nom **écrit dans la phrase du gérant** ou
  **lu sur le document** (`NAME_NOT_IN_SENTENCE`) ;
- montant, taux, date : seulement une valeur **présente dans la phrase du gérant** (`VALUE_NOT_IN_SENTENCE`) ; une date est dite par
  « aujourd'hui », « hier », `AAAA-MM-JJ`, ou son jour avec son mois, en chiffres ou en lettres ;
- question inconnue (`UNKNOWN_QUESTION`) ; « abandon » proposé par l'IA (`ABANDON_BY_BUTTON_ONLY`) ;
- chaque réponse gardée repasse ensuite par **le contrôle d'un bouton** (§ 3) : son code de refus est alors la raison (`PRODUCT_NOT_FOUND`…).
Les réponses s'appliquent dans l'ordre, comme celles d'un envoi par boutons.

**Unités et limites.**
- **1 unité par réponse de l'IA**, même si la phrase ne répond à aucune question ; **aucune** si l'IA échoue (503 `AI_UNAVAILABLE`, rien
  n'est gardé dans le fil). Le propriétaire de la plateforme n'est pas décompté. Les réponses par bouton restent gratuites.
- Palier d'IA du site, comme la lecture d'un document : 403 `AI_NOT_INCLUDED` ; crédits épuisés : 402 `AI_CREDITS_EXHAUSTED`.
- Plafond : `GestionConversationService::AI_MESSAGES_PER_DAY` = **100 réponses de l'IA par jour (jour du magasin) et par site**. Atteint :
  **429 `AI_DAILY_LIMIT { limit, resetAt }`**, `resetAt` = la fin du jour du magasin (ISO 8601) ; code distinct du 429 générique
  (`AI_TOO_MANY_FAILURES`). Aucun appel, aucune unité.
- 422 `VALIDATION_FAILED { fields: { text: REQUIRED | TOO_LONG } }` ; 409 `CONVERSATION_NOT_OPEN` sur un fil qui n'attend pas de réponse.

**Dans le fil** : la phrase (`user`, `text`), les réponses appliquées (`assistant`, `answers`, `source: "ai"`) et la réponse de l'IA
(`assistant`, `text`, avec `applied` et `ignored`). `reply` est la phrase du modèle ; s'il n'en rend pas, un texte du serveur.

## 9. Les actions préparables (lot IA-ACT-1, 13/11/2026)

Le même fil prépare une **action de gestion**, sans document : le serveur pose une question par paramètre, calcule l'aperçu et son
empreinte, n'écrit **qu'à la confirmation**, par le service de la route existante (mêmes contrôles, mêmes refus, même journal), et sait
défaire d'un bloc ce qu'il a créé. Règle du propriétaire inchangée : aucune écriture préparée sans annulation possible.

**Catalogue : une liste blanche** (`Services/Conversation/Action/ActionCatalog`, `KEYS`). Une action absente ne s'ouvre pas (422), et la
phrase libre ne peut jamais en ouvrir une : l'action est toujours donnée par sa clé, à l'ouverture. Les clés sont celles du guide des
séquences (`docs/sequences-gestion.md`) : une seule liste. Une action = une classe (`GestionAction` : `build`, `accept`, `preview`,
`execute`, `blockers`, `undo`), ses textes (`conversation.actions.<clé avec _>.*`) et son entrée au catalogue ; sa clé tient en 20
caractères (colonne `gestion_conversation.kind`). Aucune table de plus : ce que la confirmation a créé est noté dans son message
`confirmed`, d'où part l'annulation.

| Clé | Paramètres (questions) | Écrit par | Annulation, et ses refus |
|---|---|---|---|
| `expense.create` | `amount` (cents, > 0), `date`, `typeNoteDeFraisId`, `description` | `NoteDeFraisService::createNoteDeFrais`, dans la période de la date (date dans une période fermée : refus `PERIOD_CLOSED` dès la réponse) | retire la note ; `EXPENSE_CHANGED` (modifiée ou disparue), `PERIOD_CLOSED` |
| `supplier.create` | `name`, `typeFournisseurId` (proposé : « Autre »), `currency` (proposée : la devise du site) | `FournisseurService::createFournisseur` | retire le fournisseur ; `SUPPLIER_USED` (une commande l'utilise), `SUPPLIER_CHANGED` |
| `shipping.create` | `commandeId` (commandes ouvertes sans frais de port, 50 plus récentes), `transporteurId`, `amount`, `tracknumber` (facultatif) | `FraisDePortService::createFraisDePort` : LE frais de port de la commande | retire le frais de port ; `SHIPPING_CHANGED`, `COMMANDE_CLOSED`, `PERIOD_CLOSED` |
| `budget.put` | `periodId` (proposée : la période en cours ; périodes ouvertes), `planned:<poste>` pour `purchases`, `trip`, `shipping`, `advertising`, `other` (cents ; facultatifs, au moins un) | `GestionBudgetService::put` : les postes non donnés gardent leur prévu, 0 efface | remet le prévu d'avant des postes posés ; `BUDGET_CHANGED` (un poste posé a changé depuis), `PERIOD_CLOSED` |

**Ouvrir.** `POST /api/gestion/conversations { action, params?, text?, locale? }` → **201**, toujours un nouveau fil. `params`
(`{ paramètre: valeur }`) : chaque valeur est contrôlée comme une réponse par bouton et la question est rendue **déjà répondue**
(`proposed: false`). 422 `VALIDATION_FAILED { fields }` : `action: UNKNOWN_ACTION` (hors catalogue), `action: NOT_WITH_DOCUMENT` (avec un
fichier ou un `documentId`), `params: MUST_BE_OBJECT`, `params.<paramètre>: UNKNOWN_PARAM | <code de refus>` ; aucun fil n'est ouvert sur
un refus. `text` est gardé dans le fil sans être lu par l'IA (pour la faire lire : `POST …/messages`). Depuis le guide des séquences, une
étape rend `action: { key, preparable, params }` : `preparable` dit que le fil sait la préparer, `params` ce qu'il faut passer ici.

**Le fil d'une action** a la forme du § 2, avec : `kind` = la clé de l'action ; `document: null` ; `action: { key, title, available,
result }` (`result` : ce que la confirmation a créé, sinon `null` ; `available: false` si l'action a quitté le catalogue) ; `summary:
{ text, openQuestions }` ; **`issues: [{ path, code }]`** (un refus qui empêche l'aperçu sans qu'une question soit ouverte : la commande a
reçu un frais de port entre-temps, aucun poste de budget donné…) ; `preview` et `previewHash` **seulement à l'état `ready`**. États :
`questions`, `ready`, `confirmed`, `abandoned`. Messages : `action` (ouverture, avec `params`), `text`, `answers`, `confirmed`
(`{ action, result, previewHash }`), `undone`, `abandoned`.

**Questions.** `{ id, kind, path, title, text, required, input, options, answer, proposed }` : `id` = le paramètre, `kind` =
`<action>.<paramètre>`, `input.type` = `money` (cents, avec `currency`), `date` (`AAAA-MM-JJ`), `choice` (une `value` des `options`) ou
`text`. Une valeur proposée par le serveur (`proposed: true`) vaut réponse tant que le gérant ne la change pas. Refus d'une réponse :
422 `{ fields: { "answers[i].value": <code> } }` : `INVALID_AMOUNT`, `MUST_BE_POSITIVE`, `MUST_BE_INTEGER_CENTS`, `INVALID_DATE`,
`PERIOD_CLOSED`, `PERIOD_NOT_FOUND`, `REQUIRED`, `TYPE_NOTE_DE_FRAIS_NOT_FOUND`, `TYPE_FOURNISSEUR_NOT_FOUND`, `INVALID_CURRENCY`,
`COMMANDE_NOT_FOUND`, `COMMANDE_CLOSED`, `TRANSPORTEUR_NOT_FOUND`, et **`SHIPPING_COST_ALREADY_SET`** sur la question de la commande
(pas de cumul ici : ce n'est pas une facture).

**Aperçu et empreinte.** L'aperçu dit ce qui sera écrit (`expense.create` : montant, date, type, période ; `budget.put` : par poste
`{ category, label, before, after }`, `totalBefore`, `totalAfter`). L'empreinte couvre l'action, les réponses et l'aperçu, donc le
**prévu actuel de chaque poste** d'un budget : modifié entre l'affichage et la confirmation → 409 `PREVIEW_CHANGED { preview, previewHash }`.

**Confirmer.** `POST …/{id}/confirm { previewHash }` → **201** `{ result, undo: { available, until }, thread }` ; `result` :
`{ noteDeFraisId, periodId }`, `{ fournisseurId }`, `{ fraisDePortId, commandeId }`, `{ periodId }`. 409 `CONVERSATION_NOT_READY { state,
openQuestions, issues }`, `PREVIEW_CHANGED`, et les refus du service de la route existante.

**Annuler.** `POST …/{id}/undo` → 200 `{ undone, thread }`, **une fois par confirmation, dans les 24 heures**
(`ActionThreadService::UNDO_HOURS`), tout ou rien. Le fil revient à son aperçu, avec ses réponses, et peut confirmer de nouveau.
409 `NOTHING_TO_UNDO`, `ALREADY_UNDONE`, `UNDO_EXPIRED { until }`, `UNDO_BLOCKED { reasons: [{ code, message }] }` (codes du tableau).
`thread.undo` = `{ available, until, blockers }` : les blocages sont constatés à chaque lecture.

**Phrase libre** (`POST …/messages`, § 8 : même unité, mêmes limites). Garde-fous de plus pour une action : un **texte**
(description, nom, numéro de suivi) n'est gardé que s'il est écrit dans la phrase (`VALUE_NOT_IN_SENTENCE`) ; un **choix** (type,
commande, transporteur, période, devise) n'est gardé que si la phrase **désigne cette option, et elle seule**
(`ConversationInterpreter::named`). Un mot du libellé compte s'il est significatif : 3 caractères au moins, hors mots vides (les, des,
pour, frais…), hors mots présents dans le libellé de toutes les options ; sans casse ni accents. L'option désignée est celle qui a le
plus de mots dans la phrase (« novembre 2026 » désigne Novembre 2026, pas Décembre 2026). Aucune option désignée, ou une autre que
celle rendue par le modèle : `OPTION_NOT_IN_SENTENCE` ; plusieurs à égalité (« novembre » avec deux mois de novembre, deux types
cités) : **`AMBIGUOUS_OPTION`**, aucun choix n'est fait, la question reste ouverte. Montants et dates : seulement ceux de la phrase.
L'IA ne confirme, n'annule et n'abandonne pas.

Code : `Services/Conversation/ActionThreadService` (ouverture, fil, confirmation, annulation, abandon), `Services/Conversation/Action/`
(catalogue et actions) ; `GestionConversationService` garde les réponses par bouton et la phrase libre, communes aux deux sortes de fil.
Tests : `tests/Functional/Gestion/ActionConversationApiTest.php`. Suite : IA-ACT-2 (§ 10), puis IA-ACT-3 (coût d'achat d'un article,
mois de début d'exercice, calendrier des périodes, clôture d'une période).

## 10. Trésorerie et voyage (lot IA-ACT-2, 13/11/2026)

Trois actions de plus au catalogue, même fil, mêmes routes, sans migration. Clés du guide des séquences.

| Clé | Paramètres (questions) | Écrit par | Annulation, et ses refus |
|---|---|---|---|
| `cash.balance.create` | `date` (proposée : aujourd'hui, jour du magasin ; `input.max` ; refus `DATE_IN_FUTURE`), `amount` (cents, **peut être négatif** : `input.signed: true`), `note` (facultative) | `GestionCashService::saveBalance` : un solde déjà saisi à cette date est **remplacé** | retire le solde créé, ou remet le montant et la note d'avant s'il en a remplacé un ; `BALANCE_CHANGED` (modifié ou supprimé depuis) |
| `cash.movement.create` | `label`, `direction` (`out` proposé, `in`), `category` (`rent`, `salaries`, `insurance`, `subscription`, `utilities`, `loan`, `tax`, `other`), `amount` (cents, > 0), `startDate`, `recurrence` (`none` proposé, `weekly`, `monthly`, `quarterly`, `yearly`), `endDate` (facultative ; refus `BEFORE_START`), `fixedCost` (facultatif : `true` / `false`), `notes` (facultatives) | `GestionCashService::createMovement` | retire le mouvement ; `MOVEMENT_CHANGED` |
| `trip.create` | `name`, `place` (facultatif), `startDate`, `endDate` (refus `BEFORE_START`), `budgetTotal` (cents, 0 admis), `budgetDaily` (facultatif), `currency` (proposée : la devise du site), `exchangeRate` (question posée **seulement** si la devise diffère de celle du site ; `input.type: number`, > 0), `notes` (facultatives) ; `budgetScope` garde sa valeur par défaut | `GestionTripService::create` : rattache seul les notes de frais et commandes libres datées dans le voyage | supprime le voyage, les éléments rattachés redeviennent libres ; `TRIP_CHANGED`, `TRIP_USED` (un élément y a été rattaché à la main depuis), `PERIOD_CLOSED` (un élément rattaché est dans une période fermée : il garderait son rattachement) |

**Aperçus.**
- `cash.balance.create` : `{ date, amount, currency, note, replaces }` ; `replaces` = `null`, ou `{ id, amount, note }` du solde déjà saisi à
  cette date, qui sera remplacé. L'empreinte le couvre : un solde saisi ou modifié à cette date entre-temps → `PREVIEW_CHANGED`.
  `result` : `{ balanceId, replaced }`.
- `cash.movement.create` : `{ label, direction, category, categoryLabel, amount, currency, startDate, recurrence, endDate, notes,
  monthlyEquivalent, fixedCost: { value, source, text } }`. **Le gérant voit la décision « frais fixe » avant de confirmer** : `source` =
  `choice` (sa réponse), `rule` (sans réponse : la règle de la catégorie, `GestionFixedCostService::defaultFixed`) ou `never` (une entrée
  ou un mouvement ponctuel n'est jamais un frais fixe, quelle que soit la réponse) ; `text` le dit en clair : « Compté comme frais fixe :
  oui (règle de la catégorie Loyer). ». `endDate` vaut `null` pour un mouvement ponctuel. `result` : `{ movementId, fixedCost }`.
- `trip.create` : `{ name, place, startDate, endDate, days, budgetTotal, budgetDaily, currency, exchangeRate, notes, attaches: { notes,
  commandes, text } }`. `attaches` compte ce que la création rattachera (même règle que le service, hors périodes fermées et commandes de
  reprise) et `text` dit ce qui bouge dans les deux sens : « À la création, 2 note(s) de frais et 1 commande(s) libres datées dans le
  voyage y seront rattachées. Annuler le voyage rendra ces éléments libres. ». `result` : `{ tripId }` ; l'annulation rend `undone:
  { removed: { trip }, freed: { notes, commandes } }`.

**Refus vus par le fil (`issues`).** `endDate: BEFORE_START` (le début a été déplacé après la fin) ; `startDate: TRIP_OVERLAP { tripId }`
(les dates chevauchent un autre voyage : deux voyages ne se chevauchent pas).

**Depuis le guide.** Les étapes « frais fixes » rendent `action: { key: "cash.movement.create", preparable: true, params: { fixedCost:
true, recurrence: "monthly" } }` : le fil s'ouvre sur un frais fixe mensuel, deux réponses que le gérant peut changer
(`SequenceCatalog::FIXED_COST_PARAMS`). Un `params` vide est rendu `[]`, comme `screen.params`.

**Montant négatif par phrase libre** (question `input.signed`, le solde). Le signe n'est lu que s'il est **explicite et juste devant le
nombre** : « -250 », « −250 » (signe moins typographique) ou « moins 250 » (`ConversationInterpreter::signedNumbers`). Aucun mot n'est
interprété : « découvert de 250 » ne donne jamais -250 (le modèle qui rendrait -250 est écarté, `VALUE_NOT_IN_SENTENCE` ; s'il rend 250,
c'est 250). Le signe ne se perd pas non plus (« -250 » ne devient pas 250), le tiret d'une date n'est pas un signe, « moins de 250 » non
plus. Une question de montant sans `signed` ne lit jamais un négatif. L'aperçu affiche le solde négatif tel quel ; confirmer reste un
geste du gérant.

Code : `Action/CashBalanceCreateAction`, `CashMovementCreateAction`, `TripCreateAction`. Tests : `ActionConversationApiTest`
(`testACashBalanceAPlannedMovementAndATripAreEachPreparedAndUndone`, `testANegativeBalanceIsOnlyReadFromAnExplicitSign`).
