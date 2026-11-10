# Promotions datées — application de gestion (lot P5, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026 ; décision du propriétaire du 10/11/2026 : pour un article qui dort
(conseils SLOW_STOCK, END_CLEARANCE du guide), le gérant crée une promotion depuis l'application ; son prix revient tout seul à la fin, sans
toucher au prix normal. Tout est additif ; `ROLE_ADMIN` (`access_control` `/api/gestion`) ; montants en cents.

Code : `Services/GestionService/GestionPromotionService` (écriture par `GestionPriceService::writeFields`, P4), `UseCase/GestionUseCase/GestionPromotionUseCase`,
`Controller/GestionController/GestionPromotionController`, tables `gestion_promotion_batch`, `gestion_promotion_change` (en SQL, hors cache
applicatif). Migration `Version20261213100000`, script `scripts/migrate_all_v2_gestion_promotions.sh`. Test : `tests/Functional/Gestion/PromotionApiTest.php`.

## 1. Mécanisme existant réutilisé

- Champs de l'article : `product.special_price` (cents), `special_price_from`, `special_price_to` (horodatages sans fuseau ; bornes nulles = ouvertes).
- Règle partout : prix d'une ligne = **prix propre de la variante**, sinon prix effectif de l'article (`Product::getEffectivePrice` : le prix
  promotionnel si maintenant est entre les bornes, comprises), sinon le prix normal. Boutique (`ProductCommerceDto.pricing { regular, special
  { amount, from, to, active }, amount }`), panier et paiement (`CartQuoteCalculator` → `ProcessOrderItemsUseCase`, aussi pour la caisse en ligne),
  favoris de la caisse, pack hors ligne (`products[].specialPrice`, `specialPriceFrom`, `specialPriceTo` ; la tablette applique la règle
  `pos-offline-v1` à la date de vente, `PosOfflineSyncService` la rejoue).
- **Fin automatique** par les dates, sans tâche planifiée ; le prix normal n'est jamais modifié. **Une seule promotion par article.**
- **Les variantes à prix propre n'ont pas la promotion** (partout) : alerte `VARIANT_OWN_PRICE`. Étendre la règle touche la boutique, la caisse
  (règle hors ligne partagée) et les fronts : décision du propriétaire à part.
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
  d'exemple le reste). Appliquée le 11/11/2026 aux 3 seules promotions existantes (demo n° 3 et 6, gmasuite n° 2), accord du chef de projet.
- Test : `tests/Functional/Gestion/PromotionTimezoneTest.php` (formats du PATCH, même instant pour le prix effectif, la boutique, le pack et la
  règle pos-offline-v1, champ EasyAdmin, commande).

