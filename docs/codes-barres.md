# Codes-barres du catalogue

Lot CB-1 « code-barres de variante : remplir, rendre unique » (15/11/2026, décision 14 du propriétaire). Prérequis du lot L2 de la
prévention des pertes (l'étiquette collée sur la pièce doit désigner la pièce vendue). Code : `src/Services/BarcodeService/` ; base :
migration `Version20270107100000`, script `scripts/migrate_all_v2_codes_barres.sh`.

## 1. La règle

1. **Sur un site, un code désigne une seule chose** : un article OU une variante. Deux articles, deux variantes, une variante et un
   autre article, une variante et son propre article ne partagent jamais un code.
2. **Le code d'une variante est celui de la pièce vendue.** Un article à plusieurs variantes peut garder un code d'article (il ouvre le
   choix de la variante en caisse) ; un article sans variante est désigné par son code d'article.
3. **Un code saisi n'est jamais écrasé ni renuméroté.** Le code du fabricant, quand la pièce en porte un, reste le meilleur code. Le
   code généré sert aux pièces qui n'en ont pas.
4. **Forme canonique** : espaces autour retirés, chaîne vide tenue pour « pas de code », lettres en majuscules. « bar-1 » ne peut pas
   exister à côté de « BAR-1 ». La recherche met de même en forme ce qui est scanné.
5. **Forme acceptée à la saisie** : 1 à 64 caractères, lettres sans accent, chiffres et `- . _ /`, sans espace (`BARCODE_INVALID` sinon).
6. **Formes réservées, refusées** (`BARCODE_RESERVED`) : le préfixe `IZ-` (documents de la caisse : tickets, avoirs, cartes-cadeaux,
   fiches clients) et les deux formes des coupons de fabricant que la caisse sait lire (12 chiffres commençant par 5 ; 24 chiffres ou
   plus commençant par 8110 : `CouponBarcodeParser`). Un scan ne doit pas confondre un article avec un ticket ou un coupon.
7. **Article ou variante supprimés** : le catalogue efface la ligne. Un code reste réservé tant que sa ligne existe ; la ligne effacée,
   un code SAISI redevient libre. Un code GÉNÉRÉ n'est jamais redonné (la séquence ne revient pas en arrière). Les lignes de vente
   gardent leur code figé (`order_items.barcode`).

## 2. Ce que la base garantit seule

Quel que soit le chemin d'écriture (même du SQL direct) :

- le déclencheur `catalog_barcode_guard` (tables `product` et `product_variant`, avant insertion et avant mise à jour du code) met le
  code en forme canonique, refuse plus de 64 caractères, puis refuse un code déjà porté par l'AUTRE table (erreur d'unicité : « déjà
  porté par un article » ou « par une variante ») ;
- un index unique par table sur les codes présents (`uniq_product_barcode`, `uniq_product_variant_barcode`) tient l'unicité dans la table.

Le contrôle croisé se fait sous un verrou consultatif de transaction pris sur le code lui-même (clé `7461080`, `hashtext(code)`) : deux
écritures simultanées du même code sur les deux tables ne passent pas ensemble (la seconde attend la première, puis est refusée) ; deux
codes différents ne s'attendent pas. Il suppose le niveau d'isolation READ COMMITTED, celui de toutes les écritures du catalogue.

Le code de la variante tient 64 caractères (32 avant le lot), comme celui gardé sur les lignes de vente. Celui de l'article reste une
colonne de 255, mais le déclencheur n'en laisse pas écrire plus de 64. L'index simple `idx_product_barcode` reste.

La migration est rejouable (elle retire les déclencheurs avant de toucher au type de la colonne : PostgreSQL refuse de changer le type
d'une colonne nommée par un déclencheur). Elle ne remplit rien : aucun code n'est écrit à la mise en ligne. Le script des sites compte
les doublons après mise en forme AVANT d'écrire et s'arrête s'il y en a (aucun au 15/11/2026).

## 3. Le service

`App\Services\BarcodeService\CatalogBarcodeService` est LE passage de tout chemin qui écrit un code. La base n'est que le dernier
rempart : le service refuse AVANT d'écrire, avec un code stable et un texte en français, et tient ce que la base ne sait pas (caractères
permis, formes réservées).

| Méthode | Rôle |
|---|---|
| `normalize(?string)` | forme canonique, ou null pour « pas de code » |
| `validate(?string)` | forme seule, sans lire la base : `BARCODE_INVALID`, `BARCODE_RESERVED` |
| `check(?string, $for)` | `validate` puis code libre : `BARCODE_ALREADY_USED` ; `$for` est la fiche qui va porter le code (son propre code ne la gêne pas) |
| `holder(string, $except)` | ce qui porte déjà le code : `{type: product ou variant, id, productId, label}` ; null s'il est libre |
| `warning(?string)` | avertissement d'un code accepté : 13 chiffres dont la clé de contrôle est fausse (imprimé en Code 128) |
| `generate()` | un code généré libre, sans rien écrire |
| `generateFor($fiche)` | donne un code généré à une variante, ou à un article SANS variante, qui n'en a pas : `BARCODE_ALREADY_SET`, `BARCODE_HAS_VARIANTS` |
| `missingCount()` | ce que « Générer les codes manquants » écrirait : `{variants, products, total}` |
| `generateMissing($limit)` | donne un code à tout ce qui n'en a pas, variantes d'abord, au plus 500 par lancement ; rend `{written, variants, products, remaining}` |

Les refus sont des `CatalogBarcodeException` : `errorCode` (stable, les applications le traduisent), message en français pour EasyAdmin,
et `holder` pour `BARCODE_ALREADY_USED` (« Ce code-barres est déjà celui de « Casquette, Rouge / M ». »).

Les règles pures (forme, formes réservées, clé de contrôle, code généré) sont dans `CatalogBarcode` (sans base). Les entités `Product`
et `ProductVariant` prennent la forme canonique dès `setBarcode()` : l'objet en mémoire et la ligne portent la même valeur (Doctrine ne
relit pas la ligne après l'écriture). La variante ne coupe plus en silence un code trop long : il est refusé.

## 4. Génération

- **À la demande seulement** (décision du propriétaire) : rien n'est généré d'office, ni à la création d'une variante, ni à la mise en ligne.
- **Format : EAN-13 interne**, 13 chiffres : préfixe **29** (usage interne au magasin ; 20 à 28 restent libres pour de futures
  étiquettes de balance), numéro sur 10 chiffres, clé de contrôle. Le numéro vient de la séquence `catalog_barcode_seq` de la base du
  site (une base par site : l'unicité par site est naturelle), bornée à 10 chiffres, sans retour au début. Un trou dans la suite est
  sans importance. Si le code tiré existe déjà (saisi à la main), le générateur prend le suivant.
- **Ce qui reçoit un code généré** : la variante ; l'article seulement s'il n'a aucune variante.
- **Jamais d'écrasement** : `generateFor` relit la valeur de la BASE sous verrou de ligne (deux demandes simultanées ne se recouvrent
  pas : la seconde attend, puis voit le code de la première ; un code posé entre-temps par un autre chemin reste).
- **Génération en masse** (`generateMissing`) : les lignes sans code sont verrouillées (`FOR NO KEY UPDATE SKIP LOCKED`), puis écrites
  **en SQL** dans la même transaction (`UPDATE … WHERE barcode IS NULL`). Pas par les entités : `ProductVariant` re-flushe après chaque
  écriture (rappel qui recalcule le stock de l'article) et N variantes écrites en un flush coûtaient N² flush à vide, verrous tenus.
  Ce que Doctrine faisait est donc fait à la main : le cache du site (fiches et listes des articles touchés) est vidé à la validation
  par la file d'invalidation, et les objets déjà en mémoire sont relus. Le verrou est celui d'une mise à jour ordinaire : il ne bloque
  pas l'écriture d'une ligne de vente ; une ligne tenue par une vente en cours ou par un autre lancement n'est pas attendue, elle
  reste pour le lancement suivant (`remaining`) : le lancement n'attend personne et ne s'interbloque pas avec la caisse ; dans l'autre
  sens, la vente d'une fiche que le lancement tient attend sa fin (une fraction de seconde). Si tout ce qui manque est occupé, l'écran
  le dit (« N fiche(s) sans code-barres sont occupées… Relancez »), jamais « aucun code à générer ». **Mesure** (base de test, 15/11/2026) :
  500 codes écrits en moins d'une seconde (test `testTheBulkGenerationKeepsItsCeilingAndStaysFastOnMoreThanFiveHundredRows`, qui
  échoue au-delà de 20 s ou si le plafond saute).
- **Trace** : une ligne au journal du site par lancement de `generateMissing` (`content_audit_log`, ressource `catalog_barcode`,
  action `generate` : qui, quand, combien de variantes et d'articles, premier et dernier code, ce qui reste).
- Le code maison « BAR-… » reste accepté s'il est saisi ; il n'est plus généré.

### Limites connues de la règle

- **Deux formes canoniques presque identiques** : la base retire les ESPACES autour (`BTRIM`) et met en majuscules selon sa locale ;
  l'application (`trim()`, `mb_strtoupper`) retire aussi tabulation et retour à la ligne, et « straße » y devient « STRASSE » avant
  le contrôle de forme. Par les chemins de l'application, aucune différence (les caractères permis sont ASCII) ; par SQL direct, un
  code suivi d'un retour à la ligne peut exister à côté du même code sans lui.
- **Coupons** : le refus des formes de coupon ne vise que les codes tout en chiffres. Un code d'article avec des séparateurs
  (« 5-12345-67890-1 ») est accepté ; l'analyseur de coupons retire les séparateurs avant de lire. Il ne serait pris pour un coupon que
  si la tablette le soumet à la lecture de coupon : cela dépend de son aiguillage, hors de ce lot.
- **UPC-A et EAN-13** : la même étiquette lue en 12 chiffres, ou en 13 avec un zéro devant, donne deux codes distincts. On saisit le
  code tel que le lecteur du magasin le rend.
- **Retour arrière de la migration puis rejeu** (`down` puis `up`) : la séquence repart de 1 ; un code généré puis effacé pourrait
  être redonné. Ne jamais jouer `down` sur un site qui a déjà généré des codes sans remettre la séquence à sa valeur.

## 5. Chemins d'écriture et de lecture

| Chemin | Comment il passe par la règle |
|---|---|
| Fiche article d'EasyAdmin (création, modification) | contrainte `App\Validator\CatalogBarcodeFree` de l'entité `Product` : refus sous le champ |
| Fiche variante d'EasyAdmin (création) et sa modification sur mesure (`/admin/product-variant/{id}/edit-custom`, champ ajouté par le lot) | même contrainte sur `ProductVariant` |
| « Générer un code-barres » (fiche article, liste et détail des variantes) | `generateFor` ; proposé seulement à ce qui n'a pas de code (et, pour un article, pas de variante) ; lien à jeton |
| « Générer les codes manquants » (`POST /admin/barcode-management/generate-missing`, écran « Gestion des Codes-Barres ») | `generateMissing` ; nombre annoncé avant d'écrire, formulaire à jeton, administrateur |
| Création d'une fiche par l'IA (`ProductSheetCreator` : `POST /api/products/ai/create`, page « depuis une photo ») | `check` avant de rien créer ; refus 422 `{error, code, message, holder?}` |
| Ressource d'API de l'article (`POST`, `PUT`, `PATCH /api/products`, administrateur) | n'écrit pas de code-barres : le champ n'est pas dans la liste des champs acceptés (422 « champ non modifiable », gardé par un test) |
| Ressource d'API des véhicules (`PATCH /api/vehicle_products/{id}`, API Platform ; hors du pare-feu des jetons : seule une session d'administrateur y écrit) | le champ y est inscriptible ; un véhicule est un article : la même contrainte s'y applique (422 pour un code pris ou réservé, forme canonique pour un code libre ; gardé par un test) |
| Tout autre chemin (SQL direct) | la base refuse le doublon et met en forme |

La contrainte `CatalogBarcodeFree` (classe, sur les deux entités) appelle `CatalogBarcodeService::check`, puis compare le code aux autres
fiches du même formulaire (l'article et ses variantes en mémoire). Elle ne juge qu'un code qui CHANGE : un code ancien, d'une forme
refusée aujourd'hui, ne bloque pas la modification du reste de la fiche. Un code de 13 chiffres dont la clé de contrôle est fausse est
accepté, avec un avertissement après l'enregistrement (article, variante créée ou modifiée), une seule fois : seulement quand le code
vient de changer. Les boutons « Générer » ne rendent jamais une page d'erreur : un refus ou une panne revient en message.

**Recherche en ligne** (`GET /api/products/by-category?barcode=…`, publique, lue par la caisse en ligne avec `limit=1&pageSize=1`) :
le code scanné, mis en forme canonique, trouve l'article dont il est le code OU celui d'une de ses variantes ; l'article est rendu
avec toutes ses variantes et **`matchedVariantId`** (la variante désignée ; `null` si le code est celui de l'article, et sur toute
liste sans recherche par code). Un code inconnu rend `data: []` (la caisse se replie alors sur son pack). `meta.total` ne tient pas
compte du code (inchangé). La boutique ne reçoit toujours que le code de l'article.

La création d'une fiche par l'IA (décision du propriétaire du 15/11/2026, règle 2) pose le code lu sur l'unique VARIANTE de la fiche
créée ; l'article n'en porte alors pas. Quand la fiche a plusieurs variantes, le code reste sur l'article. La réponse le dit
(`barcode`, `barcodeOn: variant | product | null`). Ce que les fronts voient : la boutique ne reçoit plus de code d'article pour ces
fiches (elle ne reçoit jamais les codes des variantes) ; la caisse les trouve par la variante, en ligne (`matchedVariantId`) comme hors
ligne (`variants[].barcode` du pack).
Quand la traduction automatique est demandée, elle est faite (et débitée) AVANT la création, donc avant le refus d'un code : un
`BARCODE_ALREADY_USED` arrive après le débit, et chaque nouvel essai est débité de nouveau (ordre des appelants, inchangé par ce lot).

Recherche en ligne, à savoir : la condition « code de l'article OU d'une de ses variantes » se lit par parcours de la table (sans
effet aux tailles actuelles ; la réponse est en cache 300 s par code) ; l'appel de la caisse ne porte pas `isPos=true`, donc un
article non vendu en caisse est rendu en ligne alors que le pack hors ligne l'exclut (déjà vrai pour le code d'article avant le lot).
Un code demandé mais vide une fois mis en forme (des espaces) rend `data: []` ; « 0 » est un code comme un autre ; `barcode=` sans
valeur n'est pas une recherche par code.

Impression des étiquettes : § 7.

## 6. Mise en ligne et retour arrière

- La migration se joue AVANT l'échange du code (`docs/mise-en-ligne.md`) : le code d'avant le lot marche sur la base migrée (il écrit
  des codes par les mêmes colonnes ; un doublon, jusque-là accepté, devient une erreur).
- L'entité `ProductVariant` change (64 caractères) : `cache:clear` après l'échange du code.
- Retour arrière : le code d'avant marche sur la base migrée, la migration peut rester. Son `down` retire la règle (déclencheurs,
  fonction, index uniques, séquence) et laisse la colonne de la variante à 64 caractères (la rétrécir pourrait couper un code).

## 7. Étiquettes à la réception d'une commande fournisseur (lot CB-2)

Contrat validé par le chef pour le mobile et par le web (15/11/2026). Code : `Controller/CommandeController/CommandeLabelController`,
`Services/CommandeService/CommandeLabelService`, `Services/BarcodeService/BarcodeLabelPrinter`, gabarit `templates/barcode/labels.html.twig`.
Administrateur (`access_control` `/api/commandes`). Erreurs à la forme des routes de la commande : `{ error, code, message, … }`.
404 `COMMANDE_NOT_FOUND`. **Aucune écriture de stock** : la réception ne change pas.

Une étiquette désigne la PIÈCE reçue : la variante qui a reçu le stock (la réception en crée toujours une, même pour un article sans
variante). Trois appels : la lecture n'écrit jamais, le PDF ne cache pas une écriture.

**1. `GET /api/commandes/{id}/etiquettes`** → 200

```json
{
  "commande": { "id": 42, "name": "Printemps 2027", "receptionStatus": "partial" },
  "format": { "code": "a4-50x30", "widthMm": 50, "heightMm": 30 },
  "currency": "CAD", "pricesIncludeTax": false, "maxLabels": 500,
  "labels": [
    { "variantId": 412, "product": { "id": 88, "name": "Casquette lin", "code": "CASQ-LIN" },
      "variant": { "id": 412, "size": "M", "color": "Beige" }, "title": "Casquette lin", "subtitle": "Beige / M",
      "barcode": "2900000000418", "barcodeOf": "variant", "symbology": "ean13",
      "priceCents": 3499, "priceIncludesTax": false, "quantityReceived": 12, "copies": 12, "printable": true, "reason": null },
    { "variantId": 413, "product": { "id": 88, "name": "Casquette lin", "code": "CASQ-LIN" },
      "variant": { "id": 413, "size": "L", "color": "Beige" }, "title": "Casquette lin", "subtitle": "Beige / L",
      "barcode": null, "barcodeOf": null, "symbology": null,
      "priceCents": 3499, "priceIncludesTax": false, "quantityReceived": 8, "copies": 8, "printable": false, "reason": "BARCODE_MISSING" }
  ],
  "missing": { "count": 1, "variantIds": [413] },
  "skipped": [],
  "totals": { "lines": 2, "printable": 1, "labels": 12 }
}
```

- Une ligne par variante, dans l'ordre des lignes de la commande. `quantityReceived` : solde des mouvements de stock de la commande
  sur cette variante (réceptions moins annulations ; une variante reçue en deux fois = une ligne).
- Ligne « réputée reçue » (rattachement, reprise : reçue sans mouvement) : sa quantité va à la variante de la ligne, sinon à l'unique
  variante de l'article. Si l'article a plusieurs variantes, la répartition n'est pas connue : chacune est listée, `quantityReceived`
  à `null`, et `copies` propose son stock actuel. Si l'article n'a aucune variante (pas encore vendable), il n'a pas d'étiquette : il
  est rendu dans `skipped [{ lineId, product, quantityReceived, reason: "NO_VARIANT" }]`.
- `barcodeOf` : `variant` ; `product` quand la variante n'a pas de code, que l'article en a un et qu'elle est sa seule variante ;
  `null` : pas de code (`printable: false`, `reason: "BARCODE_MISSING"`, la variante est dans `missing`).
- `symbology` : `ean13` pour 13 chiffres à clé juste, sinon `code128`.
- `priceCents` : prix du catalogue tel qu'affiché au client (variante, sinon article), hors promotion (une étiquette collée survit à la
  promotion), sans calcul de taxe : TTC sur un site réglé « Prix affichés taxes comprises », HT sinon (`priceIncludesTax`, répété en
  tête : `pricesIncludeTax`). `null` quand il n'y a pas de prix (variante sans prix propre et article au prix zéro : le prix de
  l'article est obligatoire en base) : l'étiquette s'imprime sans prix, même avec `showPrice`.
- `copies` : nombre proposé (= `quantityReceived` quand il est connu) ; l'application le change librement. `totals.labels` : somme des
  `copies` des lignes imprimables. Commande sans rien de reçu : `labels: []`.

**2. `POST /api/commandes/{id}/etiquettes/codes`** `{ variantIds?: [413] }` (sans corps : toutes celles de `missing`) → 200
`{ generated: [{ variantId, barcode }], …la réponse du point 1, à jour }`. Donne un code généré (§ 4) aux variantes reçues qui n'en
ont pas ; ne remplace jamais un code (une variante qui a déjà le sien est ignorée) ; idempotent (`generated: []`) ; une ligne au
journal du site (`catalog_barcode`, `generate`, `scope: commande`, `commandeId`). Refus : 422 `VARIANT_NOT_IN_COMMANDE { variantIds }`,
422 `VALIDATION_FAILED { fields: { variantIds: MUST_BE_INTEGER_LIST } }`.

**3. `POST /api/commandes/{id}/etiquettes/pdf`** `{ labels: [{ variantId, copies }], showPrice?: false }` (`?locale=fr|en` pour l'écriture
du prix) → 200 `application/pdf`, `Content-Disposition: attachment; filename="etiquettes-commande-{id}.pdf"`, `X-Labels-Count`.
Planches A4 d'étiquettes 50 × 30 mm : nom de l'article, variante, barres, code en clair, prix si `showPrice`. Refus (JSON, rien n'est
imprimé) : 422 `VALIDATION_FAILED { fields }` (`labels: REQUIRED` ; `labels[i].variantId: MUST_BE_POSITIVE | DUPLICATE` ;
`labels[i].copies: MUST_BE_POSITIVE` ; `showPrice: MUST_BE_BOOLEAN`), 422 `LABELS_LIMIT_EXCEEDED { max: 500, requested }`,
422 `VARIANT_NOT_IN_COMMANDE { variantIds }`, 409 `BARCODE_MISSING { variantIds }` (générer d'abord, ou retirer ces lignes).

Parcours : après « Marquer comme reçue », « Imprimer les étiquettes » → appel 1 ; si `missing.count > 0`, « Générer les codes
manquants (N) » → appel 2 ; choix des nombres et du prix → appel 3. Test : `tests/Functional/Gestion/CommandeLabelsApiTest.php`.

### Écran « Gestion des Codes-Barres » d'EasyAdmin (repris par le lot CB-2)

`/admin/barcode-management` (`BarcodeManagementController`, liste par `Services/BarcodeService/BarcodeCatalogList`, en SQL) : une ligne
par variante et par article sans variante, **50 par page** (plus de chargement de tout le catalogue) ; recherche par nom, code
d'article, code-barres (forme canonique : on peut scanner dans le champ) ou identifiant d'article ; filtre « seulement sans
code-barres ». Le code d'une ligne suit la règle des étiquettes de la gestion (variante, sinon article quand elle est sa seule
variante). On donne un nombre d'étiquettes par ligne puis « Imprimer la sélection » (page à imprimer) ou « PDF de la sélection »
(`POST /admin/barcode-management/print` : `copies[variant-412]`, `copies[product-95]`, `showPrice`, `output`) : même planche que la
gestion (`BarcodeLabelPrinter`), prix au choix (un prix à zéro vaut « pas de prix », comme en gestion), 500 étiquettes au plus, une
ligne sans code ou une clé inconnue refusée avec un message. La sélection porte sur la page affichée.

Plafond de 500 étiquettes, mesuré le 15/11/2026 dans le conteneur (rendu seul, sans base) : 500 étiquettes de 50 codes en 2,2 s,
500 étiquettes de 500 codes distincts (pire cas) en 5,6 s, 66 Mo au plus ; 100 étiquettes en 0,4 s. Les anciennes adresses d'impression d'un article (`/admin/print-barcode/{id}/{copies}`,
`/admin/pdf/print-barcode/{id}/{copies}`) rendent la même planche ; `/admin/barcode-image/{id}` n'existe plus.

## 8. Tests

- `tests/Functional/Boutique/CatalogBarcodeTest.php` : la base seule (forme canonique à l'insertion et à la mise à jour, quatre
  croisements sous toutes les casses, longueur, écritures simultanées sur les deux tables, code libéré avec sa ligne, migration jouée
  deux fois de suite sur une base qui la porte déjà, retour arrière) ; le service (refus avant d'écrire et ce qui porte le code, formes
  réservées, génération qui saute un code pris, jamais d'écrasement même quand l'objet en mémoire ignore le code de la base, plafond et
  trace de la génération en masse).
- `tests/Functional/Admin/CatalogBarcodeAdminTest.php` : fiches article et variante (refus sous le champ, forme canonique, code ancien
  non touché, avertissement de clé de contrôle), boutons « Générer » (jamais d'écrasement, jeton), « Générer les codes manquants »
  (nombre annoncé, jeton, administrateur, ligne au journal) ; la ressource d'API de l'article n'écrit pas de code-barres.
- `tests/Functional/Boutique/CatalogBarcodeAiSheetTest.php` : création d'une fiche par l'IA (refus avant de rien créer).
- `tests/Functional/Boutique/CatalogBarcodeSearchApiTest.php` : recherche en ligne (code de variante, code d'article, code inconnu,
  `matchedVariantId`, cas exact de la caisse).
- `tests/Functional/Gestion/CommandeLabelsApiTest.php` (lot CB-2) : les trois routes d'étiquettes d'une commande reçue (quantités par
  variante, ligne réputée reçue, code de l'article pour une variante unique, génération qui ne remplace rien, refus du PDF, prix écrit
  seulement si demandé, aucune écriture de stock).
