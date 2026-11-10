# Lecture de documents par IA — application de gestion (lot L1, 10/11/2026)

Contrat validé par l'application (« new easymakemoney ») avec le feu vert du propriétaire : six corrections techniques puis trois
ajouts (brouillon, photo par ligne, type transport). Ajouts seulement, `ROLE_ADMIN` (`access_control` `/api/gestion`), cents,
devise du site à la racine, `PERIOD_CLOSED` respecté à la confirmation, crédits IA du site. Code : `Controller/GestionController/GestionDocumentController`,
`Services/GestionDocumentService/{GestionDocumentService, GestionDocumentReader, GestionDocumentMatcher, GestionDocumentStorage}`,
`UseCase/GestionUseCase/ConfirmGestionDocumentUseCase`, `Message/ReadGestionDocumentMessage` + `MessageHandler/ReadGestionDocumentHandler`,
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
  `groupKey` (nom du premier `create.name`, prix d'achat = moyenne pondérée des `unitCost`, coefficient donné sinon 2, prix de vente =
  achat × coefficient, **stock 0** (il n'entre qu'à la réception), invisible en boutique et en caisse, sans catégorie), une variante par ligne
  quand taille ou couleur sont données (référentiels créés s'ils manquent, stock 0) ; la photo
  de la première ligne du groupe devient l'image de l'article (médiathèque publique). Ligne sans l'un ni l'autre → 422 `lines[n]:
  LINE_UNRESOLVED` ; inconnu → `PRODUCT_NOT_FOUND` ; variante d'un autre article → `lines[n].variantId: VARIANT_NOT_IN_PRODUCT`. Tout ou rien.
  La réponse porte `lines` (lignes à recevoir, avec `remaining`).
- `shipping` sans `transporteurId` : frais de port posé sur le transporteur « Sur facture fournisseur » (créé une fois).
- Doublon : 409 `INVOICE_ALREADY_CONFIRMED { documentId, commandeId }`, levé par `force: true`.

**Note** : `{ montant, date, classificationDate?, description, typeNoteDeFraisId, name? }` → 201 `{ noteDeFrais, document }` (note
datée à `classificationDate ?? date`, même garde de période).

**Transport** : `{ commandeId, transporteurId, price (HT), name?, tracknumber?, facture?, force? }` → 201 `{ fraisDePort, commande,
document }` : LE frais de port de la commande (409 `SHIPPING_COST_ALREADY_SET`, `COMMANDE_CLOSED`, `PERIOD_CLOSED` selon la période de la
commande ; 404 `COMMANDE_NOT_FOUND`, `TRANSPORTEUR_NOT_FOUND`) ; doublon par transporteur + numéro.

## 5. Justificatif, conservation, limites

- Fichiers : `var/storage/private_media/gestion-documents/<code du site>/<id>/` (`page-n.ext`, `line-n.ext`), jamais publics.
- Non confirmé : 30 jours (`expiresAt`) puis purge paresseuse (à chaque envoi et à chaque liste, sans tâche planifiée) ; 410
  `DOCUMENT_EXPIRED` sur toute écriture entre temps. Confirmé : conservé tant que la commande, la note ou le frais existe
  (`document_id` ON DELETE SET NULL ; un document confirmé sans cible est purgé).
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
