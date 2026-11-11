# Promotions datées — application de gestion (lot P5, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026 ; décision du propriétaire du 10/11/2026 : pour un article qui dort
(conseils SLOW_STOCK, END_CLEARANCE du guide), le gérant crée une promotion depuis l'application ; son prix revient tout seul à la fin, sans
toucher au prix normal. Tout est additif ; `ROLE_ADMIN` (`access_control` `/api/gestion`) ; montants en cents.

Code : `Gestion/Services/GestionPromotionService` (écriture par `GestionPriceService::writeFields`, P4), `Gestion/UseCase/GestionPromotionUseCase`,
`Gestion/Controller/GestionPromotionController`, tables `gestion_promotion_batch`, `gestion_promotion_change` (en SQL, hors cache
applicatif). Migration `Version20261213100000`, script `scripts/migrate_all_v2_gestion_promotions.sh`. Test : `tests/Functional/Gestion/PromotionApiTest.php`.

## 1. Mécanisme existant réutilisé

- Champs de l'article : `product.special_price` (cents), `special_price_from`, `special_price_to` (horodatages sans fuseau ; bornes nulles = ouvertes).
- Règle partout : prix d'une ligne = **prix propre de la variante**, sinon prix effectif de l'article (`Product::getEffectivePrice` : le prix
  promotionnel si maintenant est entre les bornes, comprises), sinon le prix normal. Boutique (`ProductCommerceDto.pricing { regular, special
  { amount, from, to, active }, amount }`), panier et paiement (`CartQuoteCalculator` → `ProcessOrderItemsUseCase`, aussi pour la caisse en ligne),
  favoris de la caisse, pack hors ligne (`products[].specialPrice`, `specialPriceFrom`, `specialPriceTo` ; la tablette applique la règle
  hors ligne en vigueur, `pos-offline-v3`, à la date de vente, `PosOfflineSyncService` la rejoue ; la règle du prix unitaire n'a pas changé
  depuis v1).
- **Fin automatique** par les dates, sans tâche planifiée ; le prix normal n'est jamais modifié. **Une seule promotion par article.**
- **Les variantes à prix propre n'ont pas la promotion de l'article** (partout) : alerte `VARIANT_OWN_PRICE`. Pour elles (et pour toute
  variante), la **promotion par variante** du lot VAR (§ 8), activable par site.
- **Fuseau** : les instants sont comparés en UTC (PHP du serveur en UTC). P5 écrit **le début (00:00) et la fin (23:59:59) des jours du
  magasin convertis en UTC** (Toronto en hiver : début stocké 05:00, fin 04:59:59 le lendemain). EasyAdmin affiche donc ces bornes décalées
  (par exemple « 05:00 ») ; depuis le lot P5b (§ 7), toutes les saisies suivent la même règle.

## 2. Aperçu (aucune écriture)

`POST /api/gestion/promotions/apercu { productIds? | commandeId? | adviceId?, percent, rounding?, startDate?, endDate }`

- Périmètre comme P4 (`docs/prix-gestion.md`) : 500 articles au plus ; 422 `SCOPE_REQUIRED`, `SCOPE_AMBIGUOUS`, `PRODUCT_NOT_FOUND`,
  `COMMANDE_NOT_FOUND`, `ADVICE_NOT_FOUND`, `ADVICE_WITHOUT_PRODUCTS`, `TOO_MANY`.
- `percent` de 5 à 90 (`OUT_OF_RANGE`, `MUST_BE_NUMBER`). Arrondi **vers le bas** (une promotion n'annonce jamais moins de remise) :
  `ends_99` (le plus grand X,99 ≤ prix : 15,00 → 14,99), `down_50`, `down_100`, `none` (au cent) ; `INVALID_ROUNDING`.
- Dates (jours du magasin) : `startDate` aujourd'hui par défaut, jamais passée (`DATE_IN_PAST`) ; `endDate` obligatoire (`REQUIRED`), ≥ début
  (`BEFORE_START`), **90 jours au plus** (`TOO_LONG`) ; `INVALID_DATE`.
- Réponse : `{ rows: [{ productId, name, regularPrice, promoPrice, discount, cost, marginRegular, marginPromo, marginRateRegular, marginRatePromo,
  stock, startsAt, endsAt, current: { specialPrice, from, to, active } | null, variantsOwnPrice: { count, variants: [{ variantId, variantLabel }] },
  changed, alerts }], summary { products, changed, skipped }, period { startDate, endDate, startsAt, endsAt, days }, pricesIncludeTax, previewHash, caveats }`.
- Alertes : `NOT_A_SALE_ITEM` (location, abonnement, véhicule ; jamais modifié), `PRICE_MISSING`, `BELOW_COST` (écrite seulement avec
  `allowBelowCost`), `BIG_CHANGE` (remise > 50 %, seulement avec `allowBigChange`), `PROMOTION_EXISTS` (promotion en cours ou à venir ;
  remplacée seulement avec `allowReplace`), `VARIANT_OWN_PRICE` (information : `variantsOwnPrice`), `COST_UNKNOWN` (information), `NO_CHANGE`.
- `previewHash` : empreinte sans état (corps, prix normaux, promotions actuelles, résultat).

## 3. Application

`POST /api/gestion/promotions/appliquer { …même corps…, previewHash, allowBelowCost?, allowBigChange?, allowReplace?, markAdviceDone?: true }` →
201 `{ batchId, changed, rows, advice }` ; 409 `PRICES_CHANGED { preview }` ; 422 `previewHash` `REQUIRED`, `NOTHING_TO_APPLY`, `PRICE_REFUSED`.
- Une transaction, tout ou rien ; écriture `{ specialPrice, specialPriceFrom, specialPriceTo }` par le chemin d'un article (règles de l'éditeur,
  cache `PRODUCTS`, `DemoContentMarker`, audit par article) ; l'ancienne promotion est gardée au journal.
- Guide : avec `adviceId`, le conseil `new` passe fait, `applied { promotionPercent, productIds, appliedOn: startDate }` (même à venir : la mesure G1b
  part du début de la promotion, TOO_EARLY jusque-là ; la route publique « fait » garde son refus d'une date future).

## 4. Liste, journal et « terminer maintenant »

- `GET /api/gestion/promotions?status=active|planned|all` : articles en promotion en cours ou à venir, lus sur l'article (quelle qu'en soit
  l'origine, EasyAdmin compris) : `[{ productId, name, regularPrice, specialPrice, startsAt, endsAt, status, batchId | null }]` ; 422 `INVALID_STATUS`.
- `GET /api/gestion/promotions/lots?limit=20`, `GET /api/gestion/promotions/lots/{id}` (+ `rows [{ productId, name, regularPrice, promoPrice,
  replaced }]`, `endable`, `endSkipped`) ; 404 `BATCH_NOT_FOUND`.
- `POST /api/gestion/promotions/terminer { batchId, onlyUntouched?: false }` → `{ batchId, restored, stopped, over, skipped, adviceRestored }` :
  - pas encore commencée → **l'ancien état revient** (la promotion remplacée, s'il y en avait une) ;
  - commencée → **fin à maintenant** (`special_price_to` = maintenant ; le prix promotionnel et ses dates restent pour l'historique, inactifs) ;
  - déjà finie → rien (`over`).
  - Tout ou rien : 409 `PROMOTION_EDITED_SINCE { rows }` si un article a été retouché depuis ; `onlyUntouched` termine le reste. 409 `ALREADY_ENDED`.
  - Le conseil marqué fait par ce lot repasse `new` seulement si aucune promotion du lot n'avait commencé.

## 5. Ce que l'écriture déclenche

Mêmes déclencheurs que P4 (`docs/prix-gestion.md` § 1). La promotion s'applique d'elle-même à `startsAt` et s'arrête à `endsAt` dans la boutique,
le panier, la caisse en ligne et les favoris ; le pack hors ligne change d'`ETag` à l'écriture, les tablettes le reprennent au plus tard à
l'heure suivante puis appliquent début et fin seules, même hors ligne. Cache public : une page en cache (≤ 600 s) peut montrer l'état d'avant
juste autour d'une borne ; le panier est toujours juste.

## 6. Simulateur et guide

- Simulateur (`docs/simulateur-gestion.md`) : pour chaque variante sans prix propre dont l'article a une promotion qui chevauche les jours restants,
  la part des ventes restantes qui tombe pendant la promotion est valorisée à prix moyen × (prix promotionnel ÷ prix normal), à quantités égales ;
  remarque `PROMOTIONS_INCLUDED { productIds }`.
- Guide (`docs/guide-ia-gestion.md`) : R2 SLOW_STOCK et R9 END_CLEARANCE ne proposent pas de promotion pour un article qui en a déjà une (en cours
  ou à venir) ; R1 FAST_SELLER ne propose pas de hausse pendant une promotion.

## 7. Fuseau des dates de promotion (lot P5b, 11/11/2026)

Décision du propriétaire du 10/11/2026 : **jours du magasin stockés en UTC**, comme P5. Avant, les saisies à la main tombaient 4 à 5 h trop tôt
(stockées à l'heure saisie, relues en UTC). Toutes les lectures comparaient déjà des instants UTC et restaient justes entre elles.

- **EasyAdmin** (`ProductCrudController`) : « Promotion du / au (heure du magasin) », `DateTimeField::setTimezone(fuseau du magasin)` ; EasyAdmin
  convertit entre l'heure affichée (magasin) et le stockage (UTC).
- **PATCH `/api/products/{id}`** (`LandingContentEditor::dateValue`, seuls champs de type date de l'éditeur) : avec décalage (`…-05:00`, `…Z`) =
  même instant, converti en UTC ; heure sans décalage (`2026-11-12T23:30:00`) = heure du magasin ; date seule (`2026-11-12`) = jour du magasin,
  00:00 pour `specialPriceFrom`, 23:59:59 pour `specialPriceTo`.
- **Semis** : `BoutiqueDemoSeeder` (heures du jeu d'exemple en heure du magasin) et `ReferenceContentBuilder` (site de référence : du premier jour du
  mois au même jour un an plus tard − 1 s, en heure du magasin du site de référence, sinon America/Toronto) écrivent de l'UTC.
- **Sorties** : format inchangé (`Y-m-d H:i:s` UTC dans `ProductOutputCategoryDto`, `ProductDetailedOutputDTO`) + `specialPriceFromIso`,
  `specialPriceToIso` (ISO 8601 +00:00, additifs) ; `ProductCommerceDto` et le pack hors ligne étaient déjà en ISO.
- **P4** : l'alerte SPECIAL_PRICE_ACTIVE compare des instants (avant : la date seule).
- **Promotions déjà saisies** : `app:gestion:promotions:fuseau --tenant= --ids= [--apply]` relit les dates comme l'heure du magasin voulue et les
  remet en UTC (hiver + 5 h, été + 4 h à Toronto), simulation par défaut, anciennes valeurs dans `gestion_promotion_tz_fix` (`Version20261214100000`,
  `scripts/migrate_all_v2_gestion_promotions_fuseau.sh`), rejeu sans effet, SQL direct avec invalidation du cache de l'article (un article
  d'exemple le reste). À appliquer aux 3 seules promotions existantes (demo n° 3 et 6, gmasuite n° 2), accord du chef de projet du 11/11/2026 ;
  lancée par le propriétaire (`--tenant=demo --ids=3,6 --apply`, puis `--tenant=gmasuite --ids=2 --apply`).
- Test : `tests/Functional/Gestion/PromotionTimezoneTest.php` (formats du PATCH, même instant pour le prix effectif, la boutique, le pack et la
  règle hors ligne, champ EasyAdmin, commande).

## 8. Promotions par variante (lot VAR, 11/11/2026)

Contrat validé par l'application le 11/11/2026 (questions Q1 à Q4 tranchées). Une promotion datée **par variante**, variantes à prix propre
comprises ; **la plus précise gagne** : pendant ses dates, elle remplace la promotion de l'article, sans cumul.

**Interrupteur du site** : `entreprise.variant_promotions_enabled` (défaut faux partout), allumé **par le super admin seulement** (fiche
Entreprise d'EasyAdmin, panneau « Applications mobiles », case « Promotions par variante »), quand la caisse, la boutique Next et l'app mobile
savent l'appliquer (pack hors ligne v4 compris). Lu dans `/api/site/features` (clé `shop.variant_promotions`, lecture seule). **Éteint** :
devis, paiement, commande, favoris, pack et fiche **strictement identiques** à avant le lot (aucune clé ajoutée, promotions en base non lues) ;
l'aperçu de la gestion le signale (`warnings: ["VARIANT_PROMOTIONS_OFF"]`) et l'écriture répond 409 `VARIANT_PROMOTIONS_OFF`. Éteint après usage :
les promotions restent en base, sans effet, et reprennent si on le rallume avant leur fin. Changer l'interrupteur vide aussitôt le cache de
toutes les fiches et listes de produits du site (`CacheTags::FIELD_TAGS`, étiquette `products`) : jamais d'état mélangé.

**Calcul, un seul endroit** : `Services/OrderService/VariantPriceResolver` (table `variant_promotion` : variante, `percent`, `price` figé à
l'application, `base_price`, jours du magasin `start_date` … `end_date` et bornes UTC `starts_at` … `ends_at` [début, fin[ avec l'invariant de la
correction LOC3 : `ends_at` = minuit du magasin le lendemain de `end_date`). Prix d'une ligne = la promotion de variante en vigueur à l'instant,
sinon `variant.price ?? product.getEffectivePrice()` (l'expression d'avant). Une promotion dont le prix n'est plus sous le **prix catalogue**
(prix propre, sinon prix normal de l'article, qui a pu baisser depuis) est ignorée : jamais un prix relevé. Lecteurs : `CartQuoteCalculator`
(devis boutique et caisse, intent Stripe, `create-multi-payment`, commande web), favoris de la caisse, pack hors ligne v4 et son rejeu, fiche
produit. Ordre sur une ligne : prix (promotion) → personnalisation → prix manuel F7 → remises F1 → taxes ; les retours relisent la ligne figée
(prix payé), le résultat de période (P2) et les statistiques suivent le prix réellement vendu.

**Formes** :
- Ligne de devis (`lines[]`, ventes) : `promotion { id, kind: "variant", percent, amount, catalogUnitPrice }` **seulement** quand une promotion de
  variante s'applique ; `unitPrice` est déjà le prix promotionnel. **`catalogUnitPrice` = prix catalogue au moment du devis : le prix propre de
  la variante, sinon le prix NORMAL de l'article, jamais le prix promotionnel de l'article** (même base que `percent` et que `basePrice` ;
  c'est le prix barré que la fiche affichait déjà à côté de la promotion de l'article). `amount` = `catalogUnitPrice − unitPrice`, par rapport
  à ce même prix. Exemple : sweat à 6 900 en promotion d'article à 4 900, promotion de variante de 30 % à 4 830 → `unitPrice` 4 830,
  `catalogUnitPrice` 6 900, `amount` 2 070 (et non 70 par rapport à 4 900).
- Commande : `order_items.variant_promotion_id` (mesures de la gestion), **non exposé** par les API de commande (photo de non-régression).
- Fiche (`ProductCommerceDto.variants[]`, donc `/api/products/{id}`, `by-slug`, `productsid`, `by-category` et les listes) : `promotion { id,
  percent, price, basePrice, startDate, endDate, startsAt, endsAt, active }` (en cours, sinon la prochaine ; `basePrice` = même prix catalogue
  que `catalogUnitPrice`) ; clé absente sans promotion ou interrupteur éteint. `variants[].price` reste brut, `pricing` reste au niveau de l'article.
  La route `GET /api/products/{id}/variants` n'expose aucun prix : inchangée.
- Favoris de la caisse : `price` = prix promotionnel et `promotion` (même forme que la ligne de devis) quand elle s'applique.
- Pack hors ligne : règle **`pos-offline-v4`** seulement si la tablette envoie `X-Pos-Offline-Rules: v4` **et** si l'interrupteur est allumé ;
  ajout `products[].variants[].promotions [{ id, price, from, to, percent, basePrice }]` (en cours et à venir ; `basePrice` = prix catalogue
  pour le prix barré), seule liste que lit la tablette ; l'instantané conservé par le serveur (`prices[vid].variantPromotions`, même contenu) ne
  sort pas du serveur et sert au rejeu. L'ETag dépend du contenu, donc de la règle : une tablette qui passe en v4 avec l'ETag de son pack v3
  reçoit 200 et la v4, jamais 304. Une tablette v3 reçoit un pack identique octet pour octet (même ETag) et son rejeu reste v3 (`docs/caisse.md`).
- **Recoupement** : la promotion appliquée est celle dont `[début, fin[` contient l'instant ; si deux se recoupent, **l'identifiant le plus grand**
  (la plus récemment créée), au serveur comme sur la tablette. En pratique il n'y en a pas : l'écriture refuse le chevauchement sauf
  `allowReplace`, et le remplacement arrête l'ancienne **exactement** au début de la nouvelle (`ends_at` = `starts_at` de la nouvelle, fin
  exclue) ou la vide si elle n'a pas commencé (plage vide, absente du pack) ; la règle reste un filet (écriture directe en base).

**Routes de la gestion** (`ROLE_ADMIN`, `access_control` `/api/gestion`) :
- `POST /api/gestion/promotions/variantes/apercu { variantIds | productIds | commandeId | adviceId (une seule forme), percent (5 à 90, décimal
  admis), rounding? (none | ends_99 | down_50 | down_100, vers le bas comme P5), startDate? (aujourd'hui), endDate (90 jours au plus) }` →
  `{ rows: [{ variantId, productId, name, variantLabel, ownPrice, basePrice, promoPrice, discount, cost, marginBase, marginPromo, marginRateBase,
  marginRatePromo, stock, current: [promotions de variante qui recoupent], productPromotion: { specialPrice, from, to } | null, changed, alerts }],
  summary { variants, changed, skipped }, period { startDate, endDate, startsAt, endsAt, days }, enabled, warnings, pricesIncludeTax, previewHash,
  caveats }`. Prix de départ = prix propre de la variante, sinon prix normal de l'article ; coût de l'article (règle L0, coût de la commande
  pour `commandeId`).
- Alertes : `NOT_A_SALE_ITEM`, `PRICE_MISSING` (ligne bloquée) ; `BELOW_COST`, `BIG_CHANGE` (> 50 %), `PROMOTION_EXISTS` (promotion de variante
  qui recoupe), `VARIANT_PRICE_ABOVE_PRODUCT_PROMOTION` (prix de variante plus haut que la promotion de l'article pendant ces dates : le gérant
  renchérirait) : écrites seulement avec `allowBelowCost`, `allowBigChange`, `allowReplace`, `allowAboveProductPromotion` ; information :
  `PRODUCT_PROMOTION_ACTIVE`, `COST_UNKNOWN` ; `NO_CHANGE` (prix promotionnel non inférieur après arrondi).
- 422 : `percent` `REQUIRED` | `MUST_BE_NUMBER` | `OUT_OF_RANGE`, `rounding` `INVALID_ROUNDING`, `startDate` `INVALID_DATE` | `DATE_IN_PAST`,
  `endDate` `REQUIRED` | `INVALID_DATE` | `BEFORE_START` | `TOO_LONG`, `variantIds` `SCOPE_REQUIRED` | `SCOPE_AMBIGUOUS` | `TOO_MANY` | `INVALID_ID`,
  `variantIds[i]` `VARIANT_NOT_FOUND`, et ceux du périmètre de P4 (`PRODUCT_NOT_FOUND`, `COMMANDE_NOT_FOUND`, `ADVICE_NOT_FOUND`…).
- `POST …/variantes/appliquer { …même corps…, previewHash, allow…? }` → 201 `{ batch, skipped: [{ variantId, alerts }], advice }` ; 409
  `VARIANT_PROMOTIONS_OFF`, `PRICES_CHANGED { preview }` ; 422 `previewHash` `REQUIRED`, `NOTHING_TO_APPLY { preview, skipped }`. Remplacement
  (`allowReplace`) : l'ancienne s'arrête au début de la nouvelle (pas encore commencée : plage vide). Conseil du guide (`adviceId`) marqué fait.
- `POST …/variantes/terminer { batchId }` : en cours → jusqu'à ce soir minuit (`endDate` = aujourd'hui), à venir → plage vide (`endDate` =
  `startDate` − 1) ; 404 `BATCH_NOT_FOUND`, 409 `ALREADY_ENDED`. Les commandes déjà passées ne changent jamais.
- `GET …/variantes?status=active|planned|all` (défaut `all` : en cours et à venir), `GET …/variantes/lots?limit=`, `GET …/variantes/lots/{id}` ;
  chaque promotion `{ id, batchId, variantId, productId, name, variantLabel, percent, price, basePrice, startDate, endDate, startsAt, endsAt,
  status: upcoming | active | ended | cancelled, endedAt }`.

**Code** : `Gestion/Services/GestionVariantPromotionService`, `Gestion/UseCase/GestionVariantPromotionUseCase`,
`Gestion/Controller/GestionVariantPromotionController`, `Services/OrderService/VariantPriceResolver`, entité `VariantPromotion` (cache de l'article,
`CacheTags`), lot `variant_promotion_batch` en SQL. Migration `Version20261221100000`, script `scripts/migrate_all_v2_gestion_promotions_variantes.sh`.
**Tests** : `tests/Functional/Boutique/VariantPromotionPhotoTest.php` (photo de référence prise avec le code d'avant le lot :
`fixtures/variant-promotion-photo.json` ; (a) éteint avec promotions en base, (b) allumé sans promotion, (c) allumé avec une promotion ailleurs :
identiques au tableau près), `tests/Functional/Gestion/VariantPromotionApiTest.php` (routes, prix partout, retour en caisse, P2, pack v4 et rejeu
v3, bornes et promotion périmée), `tests/Functional/Boutique/OrderReturnsTest.php` (retour web au prix payé).

