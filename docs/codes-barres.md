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
  pas ; un code posé entre-temps par un autre chemin reste). `generateMissing` verrouille les lignes sans code avant de les remplir (un
  second lancement simultané attend, puis ne voit que ce qui reste) et écrit par les entités, pour que le cache du site soit invalidé.
- **Trace** : une ligne au journal du site par lancement de `generateMissing` (`content_audit_log`, ressource `catalog_barcode`,
  action `generate` : qui, quand, combien de variantes et d'articles, premier et dernier code, ce qui reste).
- Le code maison « BAR-… » reste accepté s'il est saisi ; il n'est plus généré.

## 5. Chemins d'écriture et de lecture

| Chemin | Comment il passe par la règle |
|---|---|
| Fiche article d'EasyAdmin (création, modification) | contrainte `App\Validator\CatalogBarcodeFree` de l'entité `Product` : refus sous le champ |
| Fiche variante d'EasyAdmin (création) et sa modification sur mesure (`/admin/product-variant/{id}/edit-custom`, champ ajouté par le lot) | même contrainte sur `ProductVariant` |
| « Générer un code-barres » (fiche article, liste et détail des variantes) | `generateFor` ; proposé seulement à ce qui n'a pas de code (et, pour un article, pas de variante) ; lien à jeton |
| « Générer les codes manquants » (`POST /admin/barcode-management/generate-missing`, écran « Gestion des Codes-Barres ») | `generateMissing` ; nombre annoncé avant d'écrire, formulaire à jeton, administrateur |
| Création d'une fiche par l'IA (`ProductSheetCreator` : `POST /api/products/ai/create`, page « depuis une photo ») | `check` avant de rien créer ; refus 422 `{error, code, message, holder?}` |
| Ressource d'API de l'article (`POST`, `PUT`, `PATCH /api/products`, administrateur) | n'écrit pas de code-barres : le champ n'est pas dans la liste des champs acceptés (422 « champ non modifiable », gardé par un test) |
| Tout autre chemin (SQL direct) | la base refuse le doublon et met en forme |

La contrainte `CatalogBarcodeFree` (classe, sur les deux entités) appelle `CatalogBarcodeService::check`, puis compare le code aux autres
fiches du même formulaire (l'article et ses variantes en mémoire). Elle ne juge qu'un code qui CHANGE : un code ancien, d'une forme
refusée aujourd'hui, ne bloque pas la modification du reste de la fiche. Un code de 13 chiffres dont la clé de contrôle est fausse est
accepté, avec un avertissement après l'enregistrement.

**Recherche en ligne** (`GET /api/products/by-category?barcode=…`, publique, lue par la caisse en ligne avec `limit=1&pageSize=1`) :
le code scanné, mis en forme canonique, trouve l'article dont il est le code OU celui d'une de ses variantes ; l'article est rendu
avec toutes ses variantes et **`matchedVariantId`** (la variante désignée ; `null` si le code est celui de l'article, et sur toute
liste sans recherche par code). Un code inconnu rend `data: []` (la caisse se replie alors sur son pack). `meta.total` ne tient pas
compte du code (inchangé). La boutique ne reçoit toujours que le code de l'article.

La création d'une fiche par l'IA pose le code sur l'ARTICLE, comme avant le lot (elle crée toujours au moins une variante).

Impression des étiquettes (variantes, 50 × 30 mm, prix facultatif) : lot CB-2.

## 6. Mise en ligne et retour arrière

- La migration se joue AVANT l'échange du code (`docs/mise-en-ligne.md`) : le code d'avant le lot marche sur la base migrée (il écrit
  des codes par les mêmes colonnes ; un doublon, jusque-là accepté, devient une erreur).
- L'entité `ProductVariant` change (64 caractères) : `cache:clear` après l'échange du code.
- Retour arrière : le code d'avant marche sur la base migrée, la migration peut rester. Son `down` retire la règle (déclencheurs,
  fonction, index uniques, séquence) et laisse la colonne de la variante à 64 caractères (la rétrécir pourrait couper un code).

## 7. Tests

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
