# Import d'une boutique existante (lot IMP-1 : catalogue Shopify par fichier)

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
- Les fichiers des imports terminés ne sont pas encore purgés (30 jours prévus).
