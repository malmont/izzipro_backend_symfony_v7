# Import d'une boutique existante (lots IMP-1 : catalogue Shopify par fichier ; IMP-2 : clients, avec leur consentement)

Le lot IMP-2 (les clients) est décrit au § 9 ; les § 1 à 8 décrivent le catalogue.

Plan d'ensemble et décisions du propriétaire : `docs/agences-et-import.md` § 4 et § 6. Ce lot (codé le 15/11/2026) importe les
**articles, variantes, images et catégories** d'une boutique Shopify depuis son **fichier d'export des produits** (CSV), sans connexion
à Shopify. Hors lot : clients (IMP-2), commandes (IMP-3), WooCommerce et PrestaShop (IMP-4, IMP-5). L'écran est dans l'application de
gestion web ; il n'y a pas d'écran EasyAdmin ni mobile.

Code : `src/Import/`. Tests : `tests/Functional/Import/`, fichier d'essai `tests/Fixtures/import/shopify-products.csv`.

## 1. Le déroulé

| Étape | Route (`ROLE_ADMIN`) | Ce qui se passe |
|---|---|---|
| Déposer | `POST /api/site/imports` (multipart : `file`, `source=shopify`) → 201 | le fichier est rangé en stockage privé, lu et **analysé sans rien écrire** ; l'import passe à `analysed` |
| Lire | `GET /api/site/imports/{id}` ; `GET /api/site/imports` (les 20 derniers) | état, rapport d'analyse, résultat |
| Confirmer | `POST /api/site/imports/{id}/confirm { visibility?, pos?, pricesIncludeTax? }` → 202 | l'import passe à `running` et part en tâche de fond (file `media`) |
| Annuler | `POST /api/site/imports/{id}/cancel` → 200 | voir § 5 |
| Lignes signalées | `GET /api/site/imports/{id}/issues.csv` | le fichier des refus et des avertissements, pour un tableur |

États : `uploaded` → `analysed` → `running` → `done` ou `failed` ; `cancelled`. **Un seul import actif par site** (409
`IMPORT_ALREADY_ACTIVE`). Limites : 20 Mo, 5 000 lignes. Un refus rend `{ error: CODE, message, … }` ; les codes sont dans
`ImportException::MESSAGES` (dont `IMPORT_NOT_UTF8` : le fichier doit être en UTF-8).

## 2. Ce que l'analyse dit (`report`)

`products { total, importable, refused, alreadyImported }`, `variants`, `stockUnits`, `categoriesToCreate` (noms), `images { total,
refusedHost }`, `barcodes { kept, dropped { duplicateInFile, alreadyUsed, invalid } }`, `compareAtIgnored`, `currency`,
`sitePricesIncludeTax`, `issues` (les 100 premières) et `issuesTotal`. Une ligne signalée : `{ line, handle, code, detail }`, où `line`
est le rang de l'enregistrement dans le fichier (l'en-tête est la ligne 1).

| Code | Sens | Effet |
|---|---|---|
| `TITLE_MISSING`, `NO_VARIANT`, `PRICE_INVALID`, `VARIANTS_TOO_MANY` | titre absent, aucune ligne de variante, prix absent ou illisible, plus de 100 variantes | l'article entier est refusé |
| `BARCODE_INVALID`, `BARCODE_RESERVED` | forme refusée, forme réservée à la caisse (règle de CB-1) | l'article est importé **sans ce code** |
| `BARCODE_DUPLICATE_IN_FILE` | le même code sur deux lignes du fichier | aucune des deux ne le garde : au gérant de dire laquelle a raison |
| `BARCODE_ALREADY_USED` | le code est porté par une fiche du site | l'article est importé sans code ; la fiche du site garde le sien |
| `IMAGE_HOST_REFUSED` | adresse d'image hors des serveurs d'images de Shopify | l'image n'est jamais appelée |
| `COMPARE_AT_IGNORED` | un prix barré plus haut que le prix | non repris (les promotions du site sont datées) ; le prix importé est celui que paie le client |

Les prix sont lus **dans la devise du site, sans conversion** (le fichier n'a pas de devise).

## 3. Correspondance

| Colonne du fichier | Devient |
|---|---|
| Handle | référence d'import de l'article (`import_item`) |
| Title, Body (HTML), Tags | nom, description (HTML nettoyé par `ImportHtml::clean` : balises de mise en forme permises, aucun attribut, ni lien ni image ni script), mots-clés ; une traduction dans la langue du site |
| Type, sinon le dernier niveau de Product Category | catégorie, créée si aucune ne porte ce nom |
| Option « Taille », « Size », « Pointure » | taille de la variante |
| Option « Couleur », « Color », « Colour » | couleur de la variante |
| autre option | valeur d'option (`ProductOption`, `ProductOptionValue`) ; « Title / Default Title » = pas d'option |
| Variant Price | prix de la variante, en cents ; le prix de l'article est celui de sa première variante |
| Cost per item (première variante) | coût d'achat de l'article ; il ne sert qu'à la valeur du stock |
| Variant Inventory Qty | stock de départ (négatif ou illisible : 0 ; plafonné à 1 000 000) |
| Variant Barcode | code-barres de la variante, par la règle de CB-1 |
| Variant SKU (première variante) | code de l'article |
| Image Src (lignes du produit et lignes d'image seules) | images de la galerie, la première étant aussi la photo principale ; 10 au plus par article |
| Published, Status | visible sur le web seulement si publié ET actif |

Choix à la confirmation : `visibility` = `as_source` (défaut : comme dans le fichier) ou `hidden` (tout masqué) ; `pos` (vendu en
caisse, défaut `true`) ; `pricesIncludeTax` (les prix du fichier comprennent-ils les taxes ? défaut : le réglage du site). Si ce choix
diffère du réglage du site, les prix sont ramenés au mode du site par le taux des taxes de l'adresse du magasin (`options.priceFactor`) ;
si ce taux est inconnu, la confirmation est refusée (`IMPORT_TAX_RATE_UNKNOWN`) plutôt que d'importer des prix faux.

## 4. L'exécution

Le worker `media` exécute l'import (`RunCatalogImportHandler`) : chaque article dans **sa propre transaction**
(`CatalogImportWriter`), à la manière de la création d'une fiche. Un article en échec n'arrête pas les autres (`result.failed`,
`result.failures`), sauf si l'échec ferme la connexion : l'import passe alors à `failed` (`IMPORT_INTERRUPTED`) ; ce qui est écrit
reste, et un nouveau dépôt du fichier reprend le reste. L'avancement s'écrit dans `result` tous les 20 articles. Si le worker
redémarre en cours de route, le message est relivré : l'exécution reprend et saute ce qui est déjà écrit. Si la mise en file échoue
à la confirmation, l'import revient à `analysed` (503 `IMPORT_QUEUE_UNAVAILABLE`).

- **Stock de départ** : un mouvement de type « Import » par variante qui a du stock. Ce n'est ni une réception ni un achat : il
  n'entre pas dans les entrées et réceptions des statistiques (`StockEvolutionService::TYPES` ne connaît pas ce type) et ne crée aucune
  dépense.
- **Rejeu** : tout ce qu'un import crée est noté dans `import_item` avec sa référence d'origine. Rejouer le même fichier ne crée pas
  de doublon et ne réécrit pas un article déjà importé (`alreadyImported`) tant que cet article existe ; un article retiré (par
  l'annulation ou à la main) redevient importable.
- **Images** (`ImportImageFetcher`) : https, hôte `cdn.shopify.com` seulement, ni identifiants ni port ; les redirections ne sont pas
  suivies par le client : chaque nouvelle adresse est revérifiée (3 au plus) ; 10 Mo au plus ; type réel lu dans le contenu. Une image
  en échec n'empêche pas l'article ; elle est au résultat (`IMAGE_UNREACHABLE`, `IMAGE_TOO_BIG`, `IMAGE_TYPE_REFUSED`,
  `IMAGE_HOST_REFUSED`).

## 5. L'annulation

- Import déposé ou analysé : abandonné, son fichier est effacé.
- Import terminé (ou en échec) depuis moins de 7 jours (`CANCEL_DAYS`) : les articles qu'il a créés et que **rien n'utilise** sont
  retirés, avec leurs mouvements « Import », leurs images, leurs lignes d'`import_item`, et les catégories qu'il a créées et qui
  restent vides. Un article est **gardé** et listé (`result.cancel.kept`) s'il a été vendu (`SOLD`) ou s'il est utilisé autrement
  (`IN_USE` : mouvement de stock autre que « Import », ligne d'achat, réservation, formule d'abonnement, paiement en cours : les
  conditions de `ProductUsageGuard`). Un article gardé reste « déjà importé » au rejeu.
- Import resté `running` plus de 60 minutes (`STALE_MINUTES`) : tenu pour interrompu, il s'annule de la même façon. Un site n'est
  donc jamais bloqué par un import qui ne finit pas.
- Le retrait se fait en SQL, article par article (jamais par l'ORM : un rappel de la variante réenregistrerait l'article) ; le cache
  du site est vidé à la fin ; une ligne au journal du site (`import_job`, `cancel`).

## 6. Le fichier des lignes signalées

`issues.csv` : séparateur point-virgule, UTF-8 avec marque d'ordre. Une cellule qui commence par `=`, `+`, `-`, `@`, une tabulation
ou un retour reçoit une apostrophe devant : un tableur ne l'exécute pas comme une formule.

## 7. Base

Migration `Version20270108100000`, script `scripts/migrate_all_v2_import.sh` : tables `import_job` (un index unique partiel garantit un
seul import actif) et `import_item`, type de mouvement « Import » (identifiant 6 ou plus : 1 à 5 sont les types d'origine, lus en dur ;
ne pas le renommer, l'import le cherche par son nom). Additive ; à appliquer à toutes les bases **avant** l'échange du code.

## 8. Limites connues

- Un prix à trois décimales (« 12.500 ») est lu comme des milliers ; l'export de Shopify en donne deux.
- Marque, poids, image propre à une variante et coût des variantes autres que la première ne sont pas repris.
- Un gros import occupe le worker `media` (les vidéos attendent).
- Les fichiers des imports de catalogue terminés ne sont pas encore purgés (30 jours prévus) ; le fichier d'un import de clients, lui,
  est supprimé dès la fin de l'exécution (§ 9).

## 9. Clients, avec leur consentement (lot IMP-2, 15/11/2026)

Contrat validé par le propriétaire le 15/11/2026. Code : `src/Import/CustomerImportService.php` (dépôt, analyse, exécution, invitations,
annulation), `ImportGuests.php` (les comptes invités : états, jeton, effacement), `ImportInvitationService.php` (le lien, côté client),
`Shopify/ShopifyCustomerCsv.php`, `Controller/ImportInvitationController.php`. Test : `tests/Functional/Import/ShopifyCustomerImportTest.php`.

**À faire avant l'ouverture au public** (décision du propriétaire) : la conformité à la loi anti-pourriel et à la Loi 25 (texte de
l'invitation, reprise du consentement commercial, durées) sera **vérifiée par l'avocat**.

### 9.1 Le principe

Le fichier d'export des clients suit le même circuit que le catalogue (dépôt, analyse sans écriture, confirmation, exécution par le
worker `media`). Chaque client du fichier devient un **compte « invité par import »** : il n'est **pas** un client.

| Un invité | |
|---|---|
| Mot de passe | inutilisable (`!` suivi d'un aléa : aucun hachage ne lui correspond), `passwordSet` faux |
| Connexion | impossible, par aucun chemin : compte non vérifié, aucun rôle de client |
| Appartenance | aucune (`docs/apps-membres.md`) |
| Courriel | **aucun** avant que le commerçant décide d'envoyer les invitations, puis **un seul**, jamais relancé |
| Marque | `signup_channel = import_invite`, une ligne dans `import_customer_invitation` ; « Invité (boutique importée) » dans la colonne « Applications » de l'écran des utilisateurs (et dans son filtre) |

**Chemins publics fermés à un invité en attente** (ils ouvriraient un compte sans acceptation ; réponse neutre, aucun courriel, aucun
jeton) : « mot de passe oublié » (`/api/password-reset/request`, `verify`, `confirm` et l'ancien `/password-reset/confirm`), le renvoi
du lien de vérification (`/api/resend-verification`), l'activation de Mémoires Vivantes (`/api/memoires/auth/activate`). Chacun
demande `ImportGuests::isPending()`. **Toute nouvelle route publique qui pose un mot de passe ou envoie un lien doit le demander aussi.**

Ne sont repris que le prénom, le nom, le courriel (gardé en minuscules), le téléphone et l'adresse par défaut (si elle porte rue,
ville, code postal et pays). **Ne sont pas lus** : notes, étiquettes, total dépensé, nombre de commandes.

### 9.2 Routes du commerçant (`ROLE_ADMIN`, `/api/site/imports`)

Les routes du § 1 valent pour les deux natures ; chaque import porte `kind` (`catalog` ou `customers`) et la liste les mêle.

| Route | Corps | Réponse | Refus |
|---|---|---|---|
| `POST /api/site/imports` | multipart `file`, `source=shopify`, **`kind=customers`** (défaut : `catalog`) | 201, l'import analysé | 422 `IMPORT_KIND_UNKNOWN`, `IMPORT_NOT_A_CUSTOMER_FILE` (colonnes « Email » et « First Name » ou « Last Name » attendues), `IMPORT_TOO_MANY_ROWS` (5 000), `IMPORT_NOT_UTF8`, `IMPORT_EMPTY_FILE`, `IMPORT_FILE_TOO_BIG` ; 409 `IMPORT_ALREADY_ACTIVE` (un seul import actif par site, toutes natures) |
| `POST /api/site/imports/{id}/confirm` | `{ attestMarketingConsent?: bool }` | 202, `running` | 422 `fields.attestMarketingConsent` `MUST_BE_BOOLEAN` ; 409 `IMPORT_NOTHING_TO_IMPORT`, `IMPORT_NOT_READY` ; 503 `IMPORT_QUEUE_UNAVAILABLE` |
| `POST /api/site/imports/{id}/invitations` | — | 202 `{ importId, queued }` : les invitations partent en tâche de fond | 409 `IMPORT_NOT_READY` (import non terminé), `IMPORT_NOTHING_TO_INVITE` (tous déjà invités : aucune relance), `IMPORT_INVITATION_LINK_UNKNOWN` (aucune adresse publique connue pour le site) ; 404 pour un import de catalogue |
| `GET /api/site/imports/{id}/customers?page=&state=` | — | `{ customers, page, pages, total }`, 50 par page | 422 `fields.state` `INVALID_VALUE` |
| `POST /api/site/imports/{id}/cancel` | — | 200, `cancelled` ; `result.cancel = { erased, kept }` | 409 `IMPORT_NOT_CANCELLABLE` (plus aucun invité en attente) |
| `GET /api/site/imports/{id}/issues.csv` | — | `line;code` (jamais une adresse) | |

La vue d'un import de clients : les champs du § 1, plus `guests: { imported, invited, accepted, kept, invitable }` ; `report.customers
= { total, importable, alreadyCustomer, alreadyImported, duplicates, refused, withAddress, marketingClaimed }`, `report.issues`
(`{ line, code }` : `NO_EMAIL`, `EMAIL_INVALID`, `DUPLICATE_IN_FILE`, `ALREADY_CUSTOMER`, `ALREADY_IMPORTED`, `ADDRESS_INCOMPLETE`),
`report.eraseDays` (90), `report.linkDays` (30) ; `result = { created, withAddress, marketingClaimed, skipped, failed, failures,
invitations?: { sent, failed, lastRunAt }, cancel? }`. **Le rapport ne porte ni adresse ni nom.**

Un client repris (`customers[]`) : `{ userId, email, firstname, lastname, state, marketingClaimed, importedAt, invitedAt,
linkExpiresAt, eraseAt, acceptedAt }`. États : `imported` (repris), `invited` (son lien est parti), `accepted` (client), `kept`
(n'a pas accepté mais une commande tient son compte : compte d'achat sans compte ordinaire).

Règles :
- Une adresse qui est déjà celle d'un compte du site (comparée sans la casse) : ligne `ALREADY_CUSTOMER`, **le compte n'est pas
  touché**. Rejouer le fichier ne crée aucun doublon (`ALREADY_IMPORTED`).
- **Consentement aux courriels commerciaux** : noté sur l'invité (`marketingClaimed`) seulement si le fichier le dit (« Accepts Email
  Marketing ») **et** si le commerçant l'atteste à la confirmation (`attestMarketingConsent: true`). L'attestation est gardée sur
  l'import (`options.attestedBy`, `attestedAt` : le journal du site ne garde ses lignes que 180 jours) et écrite au journal
  (`import_job` / `attest`). L'inscription à l'infolettre (`newsletter_subscriber`) n'est écrite **qu'à l'acceptation**.
- **Le fichier est supprimé du serveur dès la fin de l'exécution**, réussie ou non.
- Annuler : un import non confirmé est abandonné ; un import terminé efface ses invités en attente (leurs liens ne valent plus rien),
  **tant qu'il en reste**. Un client qui a accepté reste un client.

### 9.3 L'invitation

« Envoyer les invitations » est un geste du commerçant. Chaque invité reçoit **un** courriel (`customer_import_invitation`, classé
indispensable, `templates/emails/customer_import_invitation.html.twig`, textes `translations/emails.{fr,en}.yaml`), dans la langue du
site : sobre, sans aucune promotion, deux liens (« Rouvrir mon compte », « Ne pas rouvrir mon compte ») et la phrase « Vous ne
recevrez pas d'autre message à ce sujet ». **Aucune relance** : un invité déjà invité ne l'est jamais de nouveau. Un envoi en échec
rend l'invité à « repris » (rien n'est parti) ; cinq échecs d'affilée arrêtent la série.

Le lien : `https://<adresse publique du site>/invitation?token=…&locale=…` (et `&decline=1` pour le refus) : une page de la **boutique
publique**. Le jeton est aléatoire (64 caractères hexadécimaux) ; **seule son empreinte est en base** ; il ne sert qu'une fois ; il
vaut 30 jours, **jamais au-delà de l'effacement** de l'invité ; un invité à moins d'un jour de son effacement n'est plus invité.

### 9.4 Le lien, côté client (routes publiques, limitées en débit comme la connexion)

Le jeton va dans le **corps** (jamais dans l'adresse de l'API). Tout lien inconnu, expiré, déjà servi ou d'un compte effacé rend le
même refus : 404 `IMPORT_INVITATION_INVALID`.

| Route | Corps | Réponse | Refus |
|---|---|---|---|
| `POST /api/import-invitation/verify` | `{ token, locale? }` | `{ firstname, email, site, expiresAt, marketing, terms }` (`terms` : les conditions de la boutique, comme `GET /api/apps/boutique/terms`, avec `version`) | 404 |
| `POST /api/import-invitation/accept` | `{ token, password, termsVersion, accepted: true, marketing?: bool, locale? }` | `{ accepted: true, email, marketing }` ; le client se connecte ensuite par la connexion ordinaire | 422 `VALIDATION_FAILED` (`fields.password` `REQUIRED` \| `TOO_SHORT` (8) \| `TOO_LONG`, `fields.marketing` `MUST_BE_BOOLEAN`) ; 422 `TERMS_NOT_ACCEPTED` ; 409 `TERMS_VERSION_CHANGED { terms }` (le lien reste valable) ; 404 |
| `POST /api/import-invitation/decline` | `{ token }` | `{ declined: true }` | 404 |

Accepter, dans une seule transaction : l'invitation est close, le mot de passe est posé, le compte devient un compte de client
(vérifié, `ROLE_USER_INTERNET`), l'appartenance `boutique` est créée (source `import`, version acceptée, date). `marketing` (dans
`verify`) dit que l'ancienne boutique lui envoyait ses courriels commerciaux : il est alors inscrit à l'infolettre, **sauf s'il
envoie `marketing: false`** (la page l'écrit en clair, avec une case qu'il peut décocher). Sans cette mention dans le fichier, ou
sans attestation, rien n'est repris, même s'il envoie `true`. Refuser : tout ce qui a été repris est effacé tout de suite.

### 9.5 Effacement

Un invité qui n'a pas accepté est **effacé 90 jours après l'import** (`ImportGuests::ERASE_DAYS`) : son compte, ses adresses, sa ligne
d'invitation, la trace de l'import. Rien d'autre n'est touché (une inscription à l'infolettre ou une réservation faites par la même
personne hors de l'import restent : `PersonalDataEraser` n'est volontairement pas employé). L'effacement passe au **passage horaire
déjà en marche** (`app:gestion:notifications:envoyer`, pour tout site, module de gestion activé ou non : aucun minuteur de plus) et à
la lecture de la liste des imports.

Cas particuliers :
- Un invité qu'une commande (ou un panier, un abonnement, un livre, une fiche de caisse) tient **n'est pas effacé** : à l'échéance, à
  l'annulation ou à son refus, il devient un compte d'achat sans compte ordinaire (`kept` : vérifié, rôle de client, toujours sans mot
  de passe choisi), comme tout compte né d'une commande passée sans compte.
- Une personne qui **s'inscrit d'elle-même** avec l'adresse d'un invité en attente : la fiche reprise est effacée et l'inscription
  repart d'un compte neuf (pas de refus `EMAIL_ALREADY_USED`, qui révélerait l'import).

### 9.6 Base

Migration `Version20270112100000`, script `scripts/migrate_all_v2_import_clients.sh` : table `import_customer_invitation` (compte,
import, état, empreinte du jeton, langue, consentement repris, dates : repris, invité, fin du lien, effacement, accepté) et trois
index. Additive ; à appliquer à toutes les bases **avant** l'échange du code. Deux messages de worker s'ajoutent (file `media`).

### 9.7 Limites connues

- Tant qu'un invité est en attente, une commande passée sans compte avec son adresse se rattache à son compte (règle générale du
  tunnel) ; il ne peut alors choisir un mot de passe que par son invitation, jusqu'à l'échéance ou l'annulation.
- Les invitations partent toutes d'un coup, par la file `media` puis la file des courriels : pas d'envoi étalé.
- La langue de l'invitation est celle du site (l'export de Shopify ne dit pas celle du client).
- WooCommerce et PrestaShop : lecteurs à écrire derrière la même interface.
