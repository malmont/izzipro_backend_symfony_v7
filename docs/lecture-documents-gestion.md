# Lecture de documents par IA — application de gestion (lot L1, 10/11/2026)

Contrat validé par l'application (« new easymakemoney ») avec le feu vert du propriétaire : six corrections techniques puis trois
ajouts (brouillon, photo par ligne, type transport). Ajouts seulement, `ROLE_ADMIN` (`access_control` `/api/gestion`), cents,
devise du site à la racine, `PERIOD_CLOSED` respecté à la confirmation, crédits IA du site. Code : `Gestion/Controller/GestionDocumentController`,
`Gestion/Services/Document/{GestionDocumentService, GestionDocumentReader, GestionDocumentMatcher, GestionDocumentStorage}`,
`Gestion/UseCase/ConfirmGestionDocumentUseCase`, `Gestion/Message/ReadGestionDocumentMessage` + `Gestion/MessageHandler/ReadGestionDocumentHandler`,
entité `GestionDocument` (table `gestion_document`). Test : `tests/Functional/Gestion/GestionDocumentsApiTest.php`.

## 1. Principe

Le gérant photographie (ou envoie en PDF) une facture fournisseur, un reçu de dépense ou une facture de transport. Le serveur
réserve un crédit IA, stocke les fichiers en privé et confie la lecture au **worker dédié `gestion_ai`** (conteneur
`symfony_messenger_worker_gestion_v2`, file `messages_gestion_ai`, jamais relancée ; demande du propriétaire : rien de mêlé aux
autres files). Le modèle (`claude-sonnet-5-5`, images et PDF lus nativement, **sortie structurée** `output_config.format` avec un schéma
JSON par type : depuis Sonnet 5.5 l'API refuse l'outil imposé, constaté en production le 10/11/2026) rend ce qui est écrit ;
**le backend fait tout ce qui est chiffre ou catalogue** : montants ramenés hors taxes, totaux recalculés et écart, correspondances
avec les articles, fournisseurs, transporteurs et types de note (SQL + similarité, jamais l'IA), regroupement taille / couleur,
doublon de numéro. Rien n'est créé avant la **confirmation** par le gérant, qui envoie les données vérifiées. Le document reste
attaché comme **justificatif** de la commande, de la note ou du frais de port créé.

## 2. Routes (`/api/gestion/documents`)

| Route | Rôle |
|---|---|
| `POST` (multipart : `type` = `facture` \| `note` \| `transport`, `files[]`, `locale?` fr \| en) | 202 `{ id, status: "reading", … }` dès que les fichiers sont validés et stockés, crédit réservé. 1 à 6 images (JPEG, PNG, WebP, 8 Mo chacune, une image = une page dans l'ordre d'envoi) **ou** 1 PDF (20 Mo, 20 pages). 422 `DOCUMENT_INVALID { fields: { type: INVALID_TYPE, files: REQUIRED \| MIXED \| TOO_MANY, "files[n]": FORMAT \| TOO_LARGE \| PDF_TOO_MANY_PAGES } }` (rien n'est gardé), 402 `AI_CREDITS_EXHAUSTED`, 403 `AI_NOT_INCLUDED`, 429 `AI_TOO_MANY_FAILURES`, 409 `DOCUMENT_IN_PROGRESS` (une lecture à la fois par site) |
| `GET /{id}` | état complet : `status` reading \| extracted \| failed \| confirmed \| expired, `error` (failed : `AI_UNAVAILABLE` \| `AI_TOO_MANY_FAILURES` \| `DOCUMENT_UNREADABLE`, crédit libéré), `files`, `extraction`, `draft`, `draftUpdatedAt`, `confirmed`, `linePhotos`, `commandeId` / `noteDeFraisId` / `fraisDePortId`, `expiresAt` (null une fois confirmé), `currency`. L'écran interroge toutes les 3 à 5 s |
| `GET ?type=&status=&page=&limit=` | liste paginée (`X-Total-Count`, `X-Page`, `X-Limit`, 50 par défaut, 100 au plus), sans extraction ni brouillon ; purge paresseuse au passage |
| `GET /{id}/fichier/{n}` | fichier d'origine de la page `n` (binaire, inline, type d'origine, `Cache-Control: no-store, private`) ; 404 `FILE_NOT_FOUND` |
| `POST /{id}/relire` | 202, nouvelle lecture sans renvoyer les fichiers (nouveau crédit) ; pour `failed` ou `extracted` ; 409 `DOCUMENT_IN_PROGRESS` |
| `PATCH /{id}/brouillon` (JSON libre) | brouillon de vérification enregistré tel quel (256 Ko au plus : 422 `DRAFT_TOO_LARGE`), idempotent (même corps : `draftUpdatedAt` inchangé) ; rien n'est créé ; conservé après confirmation |
| `POST /{id}/lignes/{n}/photo` (multipart `file`) | photo facultative d'une ligne (JPEG, PNG, WebP, 8 Mo), remplace la précédente → 200 `{ n, photo: { name, mime, size } }` ; `GET` → le fichier ; `DELETE` → 204 (404 `PHOTO_NOT_FOUND`). `n` = numéro de ligne de l'extraction, 1000 et plus pour les lignes ajoutées par le gérant |
| `POST /{id}/apercu` (JSON, corps de la confirmation d'une facture) | 200, **aucune écriture** : coût converti, prix de vente prévu et marge par ligne, totaux recalculés, refus de la confirmation en `issues` (§ 4 bis) ; 422 `DOCUMENT_TYPE` (note, transport), `VALIDATION_FAILED { lines: REQUIRED }` ; 400 `INVALID_JSON` ; 409 `DOCUMENT_CONFIRMED`, `DOCUMENT_IN_PROGRESS` ; 410 `DOCUMENT_EXPIRED` |
| `POST /{id}/confirmer` (JSON) | crée ce qui est envoyé, **tout ou rien** (transaction) → 201 ; 409 `DOCUMENT_CONFIRMED`, `DOCUMENT_IN_PROGRESS` (lecture en cours), 410 `DOCUMENT_EXPIRED` |
| `DELETE /{id}` | 204 pour un document non confirmé (fichiers effacés) ; confirmé → 409 `DOCUMENT_CONFIRMED` |

Les erreurs de la confirmation suivent `GestionValidationExceptionListener` : 422 `VALIDATION_FAILED { fields }` et 409 / 404 à code.

## 3. Extraction

**Facture (`type = facture`)** :

```
{ supplier: { name, id | null, candidates: [{ id, name, score }], currency | null },
  invoice: { number, date, dueDate, currency, exchangeRate: null },
  amountsMode: "ht" | "ttc" | "unknown", currency (site),
  lines: [{ n, label, baseLabel, size, color, supplierCode, quantity, unitCost, total, unitCostRead, totalRead, taxable,
            groupKey, groupSize, product: { id, name, code, score, variantId } | null, candidates: [{ id, name, code, score }] }],
  taxes: [{ label, rate, amount }], shipping: { label, amount, amountRead } | null,
  totals: { subtotal, taxes, total },                                   // lus sur le document
  computed: { linesTotal, shipping, taxRate, total, difference },       // recalculés ; difference = total lu − total calculé
  confidence: { overall, number, date, lines, totals }, notes: [string],
  duplicate: { documentId, commandeId, fraisDePortId, status } | null }
```

- `unitCost`, `total`, `computed.linesTotal` sont **hors taxes**, en cents, dans la devise du document ; `unitCostRead` / `totalRead`
  gardent les montants lus. `amountsMode = "ttc"` : chaque ligne taxable est ramenée en HT au taux global du document
  (`taxes / (total − taxes)`, sinon somme des taux lus, sinon 14,975 %) ; `"unknown"` : montants traités comme HT, note
  « Le document ne dit pas si les prix sont avec ou sans taxes : ils sont pris sans taxes, à vérifier », `confidence.totals` plafonnée à 0,5. Le gérant corrige `unitCost` ligne par ligne
  dans la confirmation : c'est la valeur confirmée qui devient `commande_cost_cents`, donc le coût des articles vendus (P2).
- `quantity` absente → 1 ; `unitCost` absent mais `total` présent → déduit.
- `groupKey` = clé normalisée de `baseLabel` (sans taille ni couleur) ; `groupSize > 1` : l'application propose un seul article
  avec variantes. `product` = meilleur candidat (nom, code article ou code-barres ; score ≥ 0,55), `variantId` = variante existante
  qui porte la taille et la couleur, sinon `null` (variante à créer).
- `duplicate` posé dès la lecture : même fournisseur reconnu et même numéro qu'un document confirmé (`commandeId`) ou en cours.
- Montants dans la devise du document ; si elle diffère de celle du site, `exchangeRate` est à saisir à la confirmation (G6).

**Note de frais (`type = note`)** : `{ amount (TTC), date, currency, merchant, description, taxes, kind, typeNoteDeFrais: { id, name } |
null, candidates: [{ id, name }], confidence: { overall, amount, date }, notes }`.

**Transport (`type = transport`)** : `{ carrier: { name, id, candidates }, invoice: { number, date, currency }, trackingNumber,
amount (HT), amountRead, amountsMode, currency, taxes, totals, computed: { taxRate, total, difference }, commande: { id, name } | null
(suggestion par le numéro de suivi, sinon commande ouverte sans frais de port la plus proche de la date ; jamais imposée),
confidence: { overall, number, date, amount }, notes, duplicate }`.

## 4. Confirmation

> **Factures fournisseurs** (fin du lot L1, 10/11/2026) : la confirmation crée des **lignes d'achat à recevoir** et un nouvel article à
> **stock 0**, invisible ; le stock n'entre qu'à la réception (`docs/lignes-achat-gestion.md` § 6 et § 7). Le code `INVOICE_CONFIRMATION_PENDING`
> n'existe plus.

**Facture** : `{ supplier: { id } | { create: { name, typeFournisseurId?, currency? } }, invoice: { number?, date, exchangeRate? },
classificationDate?, name?, lines: [{ n?, label?, quantity, unitCost, productId? | create?: { name, coefficient?, groupKey? }, size?,
color? }], shipping?: { amount, transporteurId? }, force? }` → 201 `{ commande (CommandeOutputDTO, avec document), attached: [{ lineN,
productId }], created: [{ lineN, productId, name }], shipping: FraisDePortOutputDTO | null, document }`.

- Commande classée dans la période de `classificationDate ?? invoice.date` (409 `PERIOD_CLOSED { periodId, periodKey }` : l'application
  propose « enregistrer dans la période en cours » (`classificationDate` = aujourd'hui) ou « rouvrir » ; 422 `OUT_OF_CALENDAR`).
  `invoice.date` et le numéro restent sur le document ; les lectures de commandes, notes et frais rendent `document: { id, type,
  invoiceNumber, invoiceDate } | null`.
- Devise de la commande = devise du fournisseur ; `exchangeRate` obligatoire si elle diffère du site (422 `EXCHANGE_RATE_REQUIRED`) ;
  budget = Σ quantité × `unitCost` + port, dans la devise de la commande ; nom par défaut « Facture <numéro> — <fournisseur> ».
- Ligne avec `productId` (et `variantId` facultatif) : une **ligne d'achat à recevoir** de plus (réassort), **sans effet sur le stock, le prix
  de vente, le prix d'achat de la fiche ni la visibilité**. Ligne avec `create` : un article par
  `groupKey` (nom du premier `create.name`, prix d'achat = moyenne pondérée des `unitCost` **convertie dans la devise du site**,
  coefficient donné sinon 2, prix de vente = prix d'achat × coefficient : la règle unique du § 4 bis, **stock 0** (il n'entre qu'à la réception), invisible en boutique et en caisse, sans catégorie), une variante par ligne
  quand taille ou couleur sont données (référentiels créés s'ils manquent, stock 0) ; la photo
  de la première ligne du groupe devient l'image de l'article (médiathèque publique). Ligne sans l'un ni l'autre → 422 `lines[n]:
  LINE_UNRESOLVED` ; inconnu → `PRODUCT_NOT_FOUND` ; variante d'un autre article → `lines[n].variantId: VARIANT_NOT_IN_PRODUCT`. Tout ou rien.
  La réponse porte `lines` (lignes à recevoir, avec `remaining`).
- `shipping` sans `transporteurId` : frais de port posé sur le transporteur « Sur facture fournisseur » (créé une fois).
- Doublon : 409 `INVOICE_ALREADY_CONFIRMED { documentId, commandeId }`, levé par `force: true`.

- **Taux de change dans le prix** (lot APERÇU-FACT, 12/11/2026) : pour une facture dans une autre devise que celle du site, le prix
  d'achat et le prix de vente de l'article créé sont dans la devise du site (coût × `exchangeRate`). Avant, le taux n'entrait pas dans
  le prix : un article acheté 10,00 EUR au coefficient 2 était créé à 20,00, lus comme des dollars. **Le changement ne vaut que pour les
  nouvelles confirmations** : rien de ce qui a été confirmé avant n'est corrigé (les factures en devise étrangère déjà confirmées sont
  comptées par base à la mise en ligne). La ligne d'achat garde son coût dans la devise de la commande (lot G6).

**Note confirmée en campagne de publicité** (lot A1) : `{ as: "campaign", name, channel, startDate, endDate, amount, currency?, exchangeRate?,
productIds?, commandeIds?, notes? }` → 201 `{ campaign, document }` (facture d'une régie ; aucune note de frais ; `docs/publicite-gestion.md`).

**Note** : `{ montant, date, classificationDate?, description, typeNoteDeFraisId, name? }` → 201 `{ noteDeFrais, document }` (note
datée à `classificationDate ?? date`, même garde de période).

**Transport** : `{ commandeId, transporteurId, price (HT), name?, tracknumber?, facture?, force? }` → 201 `{ fraisDePort, commande,
document }` : LE frais de port de la commande (409 `SHIPPING_COST_ALREADY_SET`, `COMMANDE_CLOSED`, `PERIOD_CLOSED` selon la période de la
commande ; 404 `COMMANDE_NOT_FOUND`, `TRANSPORTEUR_NOT_FOUND`) ; doublon par transporteur + numéro.

## 4 bis. Aperçu d'une facture en cours de vérification (lot APERÇU-FACT, 12/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 12/11/2026. `POST /api/gestion/documents/{id}/apercu` rend ce que donnerait
la confirmation de la facture **telle que le gérant l'a corrigée**, sans rien écrire (ni brouillon, ni journal, ni crédit IA). Les
applications n'ont plus à calculer le prix de vente estimé ni le total des lignes.

Code : `Gestion/UseCase/PreviewGestionDocumentUseCase` ; règles partagées avec la confirmation dans
`Gestion/Services/Document/GestionInvoiceDraft` (lecture des lignes, groupes, taux, prix, totaux).
Test : `GestionDocumentsApiTest::testInvoicePreviewGivesThePricesOfTheConfirmationAndWritesNothing`.

- **Corps** : celui de la confirmation (§ 4). Seules les lignes sont exigées ; le fournisseur, la date et le numéro ne le sont pas ; les
  champs en trop sont ignorés.
- **Réponse** :
  ```
  { currency (du site), invoiceCurrency, exchangeRate | null, pricesIncludeTax,
    lines: [{ n, quantity, unitCost, total, unitCostConverted | null, kind: "existing" | "create" | "unresolved",
              coefficient | null, plannedPrice | null, currentPrice | null, plannedMargin | null, plannedMarginRate | null, groupKey | null }],
    totals: { lines, shipping, taxRate, taxes, total, read: { subtotal, taxes, total }, difference | null },
    issues: [{ path, code }] }
  ```
- **Devise de la facture** (`invoiceCurrency`) : celle du fournisseur du corps, comme à la confirmation (`supplier.id` : sa devise ;
  `supplier.create.currency` ; un fournisseur sans devise est dans la devise du site). Sans fournisseur dans le corps : la devise lue sur
  le document, sinon celle du site.
- **`exchangeRate`** : le taux qui s'applique, 1 quand la facture est dans la devise du site (un taux envoyé est alors ignoré, comme sur
  la commande), `null` quand il est requis et manque (`issues` : `exchangeRate` `EXCHANGE_RATE_REQUIRED`).
- **Par ligne**, `unitCost` et `total` dans la devise de la facture, le reste en cents de la devise du site :
  - `unitCostConverted` = arrondi(`unitCost` × taux) ; `null` sans taux.
  - `kind` `create` : `plannedPrice` est le prix que la confirmation donnera à l'article, par **la même fonction**
    (`GestionInvoiceDraft::groupPrice`) : coût moyen pondéré du groupe (`groupKey`), converti (arrondi au cent), × coefficient de la
    première ligne du groupe (2 par défaut), arrondi au cent. Il est répété sur chaque ligne du groupe. Le coefficient se lit donc
    « prix de vente ÷ coût, dans la devise du site ». `null` si une ligne du groupe est refusée ou si le taux manque.
  - `kind` `existing` : `plannedPrice` `null` (la confirmation ne change pas le prix d'un article existant) ; `currentPrice` = le prix de
    vente actuel de la variante, sinon de l'article.
  - `kind` `unresolved` : ni article ni création (ou les deux) ; aucun prix.
  - `plannedMargin` = `plannedPrice` − `unitCostConverted` de la ligne ; `plannedMarginRate` = marge ÷ prix de vente × 100, à une
    décimale : le **taux de marque**, la définition de `grossMarginRate` des statistiques (`docs/statistiques-gestion.md`) et de
    l'aperçu des prix (`docs/prix-gestion.md`). `null` quand `plannedPrice` est `null`. Comme dans l'aperçu des prix, la marge est
    prise sur le prix affiché : quand le site vend taxes comprises (`pricesIncludeTax`), ce prix contient la taxe.
- **`totals`**, dans la devise de la facture : `lines` = Σ quantité × `unitCost` des lignes lisibles ; `shipping` ; `taxes` au taux global
  de la lecture (`taxRate`, celui de `extraction.computed.taxRate`, appliqué sans son arrondi ; aucune taxe quand le mode des montants est
  inconnu) ; `total` ; `read` : les montants lus ; `difference` = total lu − total recalculé. Sur les lignes lues sans correction, ces
  valeurs égalent `extraction.computed`.
- **`issues`** : les refus que la confirmation opposerait à ces champs, mêmes chemins et mêmes codes (`lines[n].quantity`
  `MUST_BE_POSITIVE`, `lines[n]` `LINE_UNRESOLVED` / `PRODUCT_OR_CREATE`, `lines[n].productId` `PRODUCT_NOT_FOUND`, `lines[n].variantId`
  `VARIANT_NOT_IN_PRODUCT`, `lines[n].create.coefficient` `MUST_BE_POSITIVE`, `exchangeRate` `EXCHANGE_RATE_REQUIRED`, `supplier.id`
  `FOURNISSEUR_NOT_FOUND`, `shipping.amount`…). Ils ne bloquent pas l'aperçu : le reste est calculé. Ne sont pas signalés ici : le
  fournisseur et la date absents, la période clôturée, le doublon de numéro.

## 5. Justificatif, conservation, limites

- Fichiers : `var/storage/private_media/gestion-documents/<code du site>/<id>/` (`page-n.ext`, `line-n.ext`), jamais publics.
- Non confirmé : 30 jours (`expiresAt`) puis purge paresseuse (à chaque envoi et à chaque liste, sans tâche planifiée) ; 410
  `DOCUMENT_EXPIRED` sur toute écriture entre temps.
- Confirmé : conservé tant qu'une de ses cibles existe (commande, note de frais, frais de port, campagne). Deux liens, dans deux sens :
  - la cible porte `document_id` (clé étrangère, ON DELETE SET NULL) : supprimer le document libère la cible ;
  - le document porte `commande_id`, `note_de_frais_id`, `frais_de_port_id`, `campaign_id` : de simples identifiants, sans clé
    étrangère.
- **Purge d'un document confirmé** (DOC-ORPH, 12/11/2026, `GestionDocumentService::purge`) : quand **toutes** ses cibles ont disparu, le
  document est supprimé avec ses fichiers. Le critère est l'existence réelle de la ligne cible.
  - Moment : aussitôt après la suppression d'une note de frais, d'une commande, d'un frais de port ou d'une campagne, et de nouveau à
    chaque liste et à chaque envoi (filet : suppression depuis EasyAdmin, cascade en base).
  - Un document à plusieurs cibles reste tant qu'une cible existe ; l'identifiant de la cible disparue est remis à null.
  - Une suppression refusée (409 `PERIOD_CLOSED`, par exemple) ne purge rien.
  - Les fichiers sont supprimés après l'enregistrement en base, jamais avant.
  - Journal des contenus : une entrée `gestion-documents` / `purge` par document, avec le motif (`target_deleted` ou `expired`), les
    cibles disparues et, pour chaque fichier, son nom d'origine, sa taille et son empreinte SHA-256. Le fichier lui-même n'est pas gardé.
  - Après la purge : le document n'est plus dans `GET /api/gestion/documents`, et `GET /api/gestion/documents/{id}` répond 404.
  - Avant le 12/11/2026, rien ne remettait ces identifiants à null : un document dont la note avait été supprimée restait dans la
    liste, et `DELETE` répondait 409 `DOCUMENT_CONFIRMED`.
- Crédits : 1 par lecture (`LandingAiQuotaService`, composant `gestion_document`, réservation 30 min, facturée en succès, libérée en échec).
- Palier : la lecture demande « Aide IA » (`ai.help`, inclus dans `ai.full`), même en mode démonstration ; 403 `AI_NOT_INCLUDED` ; le propriétaire
  de la plateforme n'y est pas soumis. La confirmation est l'acte du gérant : pas de `ai.full`.
- Lecture perdue (worker arrêté, message disparu) : une lecture dont la réservation est échue passe en `failed` (`AI_UNAVAILABLE`, crédit libéré)
  à la lecture suivante de l'état, de la liste ou au prochain envoi ; le site n'est plus bloqué.
- `notes` (toutes les extractions) est affiché tel quel au commerçant : le modèle n'y met que ce qu'il faut vérifier, en français simple, sans
  nom de champ ni code, liste vide si tout est clair (retour de l'application du 10/11/2026).
- Envoi au modèle : les photos JPEG et PNG de plus de 1,5 Mo ou 2400 px sont réduites à 2400 px en JPEG (orientation EXIF appliquée) pour tenir
  dans la taille de requête de l'API ; le fichier d'origine reste le justificatif. WebP : envoyé tel quel (GD du serveur sans WebP).
- Limite connue : le nombre de pages d'un PDF est compté sur sa structure ; un PDF à flux d'objets compressés peut échapper à `PDF_TOO_MANY_PAGES`
  (la lecture échoue alors en `failed`, sans crédit retenu).
- Journal `content_audit_log` (`gestion-documents` : upload, confirm, delete).
- Schéma : migration `Version20261201100000`, script `scripts/migrate_all_v2_gestion_documents.sh` (table `gestion_document`,
  `document_id` sur `commande`, `note_de_frais`, `frais_de_port`).
- Déploiement : nouveau transport `gestion_ai` (`config/packages/messenger.yaml`) et service `messenger_worker_gestion` (`docker-compose.yml`),
  à créer avec `docker compose up -d --no-deps messenger_worker_gestion` ; écran `/admin/workers` (`WorkerMonitor`).
