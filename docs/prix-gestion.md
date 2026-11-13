# Appliquer un prix — application de gestion (lot P4, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, sur `docs/propositions-pilotage.md` de l'application : « appliquer le
coefficient à un ou plusieurs articles après simulation : le prix de vente doit bien être recalculé ; route dédiée, et le prix ne change qu'après
confirmation ». Suite du guide (`docs/guide-ia-gestion.md`) : un conseil FAST_SELLER (+5 %) s'applique sans retoucher chaque fiche.
Tout est additif ; `ROLE_ADMIN` (`access_control` `/api/gestion`) ; montants en cents.

Code : `Gestion/Services/GestionPriceService`, `Gestion/UseCase/GestionPriceUseCase`, `Gestion/Controller/GestionPriceController`,
tables `gestion_price_batch`, `gestion_price_change` (entités `GestionPriceBatch`, `GestionPriceChange`, en SQL, jamais en cache applicatif).
Migration `Version20261212100000`, script `scripts/migrate_all_v2_gestion_prix.sh`. Test : `tests/Functional/Gestion/PriceApiTest.php`.

## 1. Chemin d'écriture et ce qu'il déclenche

Les prix sont partagés avec la boutique et la caisse : P4 écrit **par le chemin d'une modification d'article** (`PATCH /api/products/{id}`,
`PATCH /api/product-variants/{id}` → `PatchLandingContentUseCase`), un article ou une variante à la fois, jamais en SQL en masse :
1. `LandingContentEditor::validate` (mêmes règles que l'écran article : prix de 0 à 1 000 000 $) ;
2. `LandingContentHooks::before` (rien pour un prix) ;
3. écriture Doctrine + flush → `CacheInvalidationSubscriber` invalide l'étiquette `PRODUCTS` du site (listes, fiche, sélections de la boutique),
   appliqué en fin de requête par `CacheInvalidationFinalizer` ;
4. `DemoContentMarker` (un article d'exemple modifié devient définitif) ;
5. `ContentAuditRecorder` : une entrée par article ou variante (`products` / `product-variants`, avant / après).

Après chaque ligne, l'unité de travail Doctrine est vidée (sinon chaque flush repasse sur tous les articles déjà chargés : 500 lignes en 19 s au
lieu de 1,7 s, mesuré dans la base de test avec le débogage).

En aval, sans rien de plus : devis du panier recalculé côté serveur à la commande ; caisse en ligne au prix courant ; pack hors ligne recalculé à
la lecture (nouvel `ETag`, nouveau `packVersion`, repris par les tablettes au démarrage et toutes les heures ; une vente hors ligne faite entre-temps
est recalculée sur sa version de pack, l'écart seulement signalé, `currentPriceDrift`) ; pas d'index de recherche externe ; pas de Stripe pour les
articles. **Cache public** : les pages publiques peuvent montrer l'ancien prix jusqu'à 10 minutes (stale-while-revalidate 600 s et cache Next) ;
le panier est toujours au bon prix.

**À savoir** : un prix appliqué ici peut être recalculé plus tard si quelqu'un modifie le prix d'achat ou le coefficient de la fiche dans
l'administration (`Product::updatePrice()` : prix = prix d'achat × coefficient), comme pour toute modification de prix à la main.

## 2. Aperçu (aucune écriture)

`POST /api/gestion/prix/apercu { productIds? | commandeId? | adviceId?, mode: "percent" | "coefficient", value, rounding?: "none" | "ends_99" | "up_50" | "up_100", includeVariants?: true }`

- Périmètre (un seul, sinon 422 `SCOPE_REQUIRED` / `SCOPE_AMBIGUOUS`) : `productIds` (500 au plus), `commandeId` (articles des lignes de la commande
  fournisseur), `adviceId` (articles du sujet du conseil ; 422 `ADVICE_WITHOUT_PRODUCTS` sans article). 2 000 lignes au plus (articles et variantes) :
  422 `TOO_MANY_ROWS`.
- `percent` : nouveau prix = prix actuel × (1 + value / 100), value de −90 à +300.
- `coefficient` (value de 0,5 à 20) : **même règle que le coefficient de la fiche** (`Product::updatePrice()`, reprise d'historique P3, conseil R6) :
  **prix affiché = coût × coefficient**, sans calcul de taxe (TTC si le site affiche ses prix taxes comprises, HT sinon). Coût : celui de la ligne de
  la commande (× taux) avec `commandeId`, sinon le coût courant de l'article (moyenne pondérée des lignes d'achat, règle L0, sinon prix d'achat de la
  fiche). Exemple : article acheté 12,00 $ sur la commande, coefficient 2 → **24,00 $** affichés (même si la fiche indique un prix d'achat de 5,00 $).
- Arrondi, appliqué en dernier : `ends_99` (le plus petit X,99 ≥ prix : 26,24 → 26,99 ; 26,00 → 26,99), `up_50` (0,50 supérieur), `up_100`
  (dollar supérieur), `none` (au cent).
- Lignes : une ligne « article » (`level: product`, `product.price`, que suivent les variantes sans prix propre : `inheritingVariants`) et une ligne
  par variante qui a son propre prix (`level: variant`, sauf `includeVariants: false`), avec `variantLabel` (« XL · Noir » : taille puis couleur,
  celles qui existent ; aussi dans le détail d'un lot).
- Réponse : `{ rows: [{ productId, variantId, level, name, inheritingVariants?, oldPrice, newPrice, cost, marginBefore, marginAfter, marginRateBefore,
  marginRateAfter, stock, changed, alerts }], summary { products, rows, changed, skipped }, pricesIncludeTax, previewHash, caveats }`.
- Alertes : `NOT_A_SALE_ITEM` (location, abonnement : grille ou formule, jamais modifié), `PRICE_MISSING` (prix nul en `percent`), `COST_UNKNOWN`
  (`coefficient`), `NO_CHANGE`, `BELOW_COST`, `BIG_CHANGE` (écart de plus de 50 %), `SPECIAL_PRICE_ACTIVE` (promotion en cours : le prix
  promotionnel **n'est pas touché** et reste vendu jusqu'à sa fin).
- Marge sur le prix affiché (`marginRate` = taux de marque en %) ; `PRICES_INCLUDE_TAX` dans `caveats` quand le site vend TTC.
- `previewHash` : empreinte du corps et des prix actuels de toutes les lignes, sans état (invalide dès qu'un prix bouge).

## 3. Application

`POST /api/gestion/prix/appliquer { …même corps…, previewHash, allowBelowCost?: false, allowBigChange?: false, markAdviceDone?: true }` → 201
`{ batchId, changed, rows, undoUntil, advice }`.
- L'aperçu est recalculé : empreinte différente → 409 `PRICES_CHANGED` avec l'aperçu à jour dans `preview` ; sans `previewHash` → 422.
- Lignes écrites : `changed`, sauf `BELOW_COST` sans `allowBelowCost` et `BIG_CHANGE` sans `allowBigChange` (une faute de saisie, 300 au lieu de
  30 %, ne passe pas en un geste) ; aucune → 422 `NOTHING_TO_APPLY`. Une seule transaction : tout ou rien (un refus du chemin d'écriture → 422
  `PRICE_REFUSED`).
- **Guide** : avec `adviceId` et `markAdviceDone` (vrai par défaut), le conseil encore `new` est marqué fait dans le même appel, `applied { pricePercent
  (value en `percent`, écart moyen pondéré en `coefficient`), productIds, appliedOn: aujourd'hui }` : la mesure G1b part de ce jour.

## 4. Journal et annulation

- `GET /api/gestion/prix/lots?limit=20` → `[{ id, mode, value, rounding, adviceId, changed, createdBy, createdAt, undoneAt, undoneBy, undoable,
  undoBlockedBy, undoUntil, undoSkipped }]` ; `GET /api/gestion/prix/lots/{id}` → idem + `rows [{ productId, variantId, level, name, oldPrice, newPrice }]` ;
  404 `BATCH_NOT_FOUND`. Le journal d'audit garde en plus chaque changement article par article.
- `POST /api/gestion/prix/annuler { batchId, onlyUntouched?: false }` → `{ batchId, restored, skipped, adviceRestored }` : anciens prix remis par le
  même chemin, dans les **24 h** (409 `UNDO_EXPIRED`), une fois (409 `ALREADY_UNDONE`). **Tout ou rien** : si un prix n'est plus celui du lot →
  409 `PRICE_EDITED_SINCE { rows: [{ productId, variantId, level, oldPrice, newPrice, currentPrice }] }` ; avec `onlyUntouched: true`, seules les
  lignes intactes sont remises, les autres listées dans `skipped`. Le conseil marqué fait par ce lot repasse en `new` (sa mesure s'efface).

## 5. Réglages de prix du site (lot IA-ACT-5, 13/11/2026)

Trois réglages par site, sur la fiche Entreprise (`gestion_default_coefficient`, `gestion_min_coefficient`, `gestion_price_rounding`,
migration `Version20261228100000`, script `scripts/migrate_all_v2_gestion_prix_reglages.sh`). Une colonne vide vaut la valeur par défaut.

| Réglage | Par défaut | Rôle |
|---|---|---|
| `defaultCoefficient` | 2 | coefficient d'un article acheté quand rien de mieux n'est connu (prix de vente = coût × coefficient) |
| `minCoefficient` | 1,6 | plancher : le serveur ne **propose** jamais un coefficient plus bas |
| `rounding` | `psy_99` | arrondi d'un prix **calculé** |

`GET /api/gestion/reglages/prix` → `{ defaultCoefficient, minCoefficient, rounding, roundings: [...], stored: { … } }` (`stored` : ce que le
gérant a réglé, `null` = valeur par défaut). `PUT` du même chemin : seules les clés envoyées changent, `null` remet la valeur par défaut.
422 `OUT_OF_RANGE` (coefficient hors de 1 à 20), `MUST_BE_NUMBER`, `INVALID_ROUNDING`, `MIN_ABOVE_DEFAULT` (le minimum dépasse le
coefficient par défaut). Journal `gestion-reglages-prix`. Code : `GestionPriceSettingsService`.

**Arrondi** (`Gestion/Pricing/PriceRounding`), toujours **vers le haut** : la marge n'est jamais sous celle du coefficient.

| Mode | Règle | Exemples |
|---|---|---|
| `psy_99` | sous 100 : la terminaison ,99 supérieure ; à partir de 100 : le prochain entier finissant par 9 | 54,78 → 54,99 ; 9,99 et 99,99 inchangés ; 100,00 → 109,00 ; 154,78 → 159,00 |
| `ends_95` | la terminaison ,95 supérieure | 22,50 → 22,95 |
| `integer` | l'entier supérieur | 108,10 → 109,00 |
| `none` | au cent, sans arrondi | |

L'arrondi porte sur le prix affiché au client, celui que la fiche enregistre : aucun calcul de taxe. **Un prix saisi par le gérant n'est
jamais arrondi.** **Aucun prix existant ne change** : ni la migration, ni le réglage, ni sa lecture ne touchent au prix d'un article ou
d'une variante ; seul un prix que le serveur CALCULE (coût × coefficient, ou pourcentage sur le prix actuel) est arrondi, au moment où
le gérant le confirme. Un article déjà à 25,00 reste à 25,00 tant que personne ne recalcule son prix (test de garde :
`PriceSetActionApiTest::testPriceSettingsOfTheSite`). Il s'applique : au prix d'un article créé par une facture (aperçu et confirmation, `GestionInvoiceDraft::groupPrice`), au
prix d'un article déjà au catalogue recalculé par coefficient sur une facture, et, par défaut, à l'action `price.set`. La route du lot P4
(`POST /api/gestion/prix/apercu`) garde ses arrondis (`ends_99`, `up_50`, `up_100`) et accepte aussi ceux du site.

## 6. Comment se calcule le coefficient proposé (lot IA-ACT-5)

Quand une facture fait entrer un article, le serveur propose un coefficient **sans aucune IA**. Il regarde, dans cet ordre :

1. **Ses semblables d'une saison terminée**, s'il y en a au moins 5.
2. Sinon, **le coefficient constaté chez ce fournisseur**, s'il a au moins 5 articles.
3. Sinon, **le coefficient par défaut du site**.

Le gérant voit toutes les propositions qui existent, chacune avec ses chiffres ; la première est proposée, il peut en choisir une autre ou
saisir un nombre. Aucune proposition n'est sous le coefficient minimum du site.

### Qui sont les semblables d'un article

Les articles achetés chez **le même fournisseur**, à un **coût d'achat proche (à 30 % près)** et, quand l'article est déjà au catalogue,
de **la même catégorie**. Un article qu'une facture vient de créer n'a pas encore de catégorie : seuls les deux premiers critères
comptent, et le libellé dit « semblables de ce fournisseur ».

On ne regarde qu'une **saison terminée** : la même période du calendrier du site, un an plus tôt (pour une facture d'octobre 2026 sur
un site en trimestres : l'automne 2025). Les articles de la saison en cours n'ont pas fini de se vendre : leur taux serait faussement
bas, ils sont exclus.

### Les chiffres, et leur définition

| Chiffre | Définition |
|---|---|
| Part vendue | unités vendues pendant la saison ÷ unités achetées pour la saison. Un semblable jamais vendu y compte, pour 0 vendu. Les retours sont déduits. |
| Délai | nombre de jours entre l'achat et la vente, en moyenne pondérée par les quantités, **sur les seules unités vendues** (le libellé dit « pour les unités vendues ») |
| Coefficient affiché | prix catalogue au moment de la vente ÷ coût d'achat ; pour un semblable jamais vendu, son prix actuel ÷ coût. Moyenne des semblables. |
| Coefficient obtenu | prix réellement payé ÷ coût d'achat, sur les unités vendues. Un semblable jamais vendu n'y entre pas. |
| Rabais | (valeur au prix affiché − prix payé) ÷ valeur au prix affiché, sur les unités vendues |

Ventes aux règles du résultat de période : hors commandes annulées ou en attente, hors location et abonnement.

### La règle (validée par le propriétaire le 13/11/2026)

On part du coefficient **affiché** des semblables.

| Les semblables se sont vendus… | sans rabais | avec rabais |
|---|---|---|
| à 70 % ou plus | affiché **+ 0,2** | le coefficient **obtenu**, sans autre ajustement |
| entre 40 % et 70 % | affiché, inchangé | affiché, inchangé |
| à moins de 40 % | affiché **− 0,2** | le coefficient **obtenu** |

Le rabais est déjà dans le coefficient obtenu : un article mal vendu n'est jamais pénalisé deux fois.

Constantes (`Gestion/Pricing/CoefficientRule`, `Gestion/Analysis/SimilarArticlesAnalysis`, `CoefficientSuggestion`) :

| Constante | Valeur | Sens |
|---|---|---|
| `SOLD_WELL` | 70 % | à partir de là, les semblables se sont bien vendus |
| `SOLD_POORLY` | 40 % | en dessous, ils se sont mal vendus |
| `STEP` | 0,2 | le cran d'ajustement |
| `MIN_SIMILAR` | 5 | il faut au moins 5 semblables pour appliquer la règle |
| `DISCOUNT_FROM` | 5 % | « avec rabais » à partir de 5 % de rabais ; en dessous (quelques unités soldées en fin de saison), l'article est dit « sans rabais » |
| `COST_BAND` | 30 % | écart de coût admis entre un article et ses semblables |
| `MIN_SUPPLIER_ARTICLES` | 5 | il faut au moins 5 articles chez le fournisseur pour proposer son coefficient constaté |
| `KEEP_UNDER` | 0,1 | bilan d'un article déjà en vente : sous un demi-cran d'écart, le verdict est « garder » |
| plancher | 1,6 | le coefficient minimum du site (réglable, § 5) |

### Ce que lit le gérant

Le libellé porte toujours les chiffres et le sens de l'ajustement :

- « 2,7 : 8 semblables de ce fournisseur affichés à 2,5, vendus à 85 % en 3 semaine(s) pour les unités vendues, sans rabais (+0,2) » ;
- « 2 : 5 semblables affichés à 2,5, vendus à 80 % en 2 semaine(s) pour les unités vendues, avec 20 % de rabais (coefficient obtenu) » ;
- « 2,3 : constaté chez ce fournisseur, sur 12 articles » ;
- « 2 : le coefficient par défaut du site » ;
- quand le plancher joue : « … (relevé au coefficient minimum du site, 1,6) ».

Le coefficient constaté chez un fournisseur est la moyenne du prix de vente actuel ÷ coût d'achat de ses articles achetés au coût connu.

### Une analyse nommée, réutilisable

`SimilarArticlesAnalysis` (clé `articles.vs_similar`) est la première **analyse nommée** de la gestion : un service sans IA, en lecture
seule, une entrée et une sortie chiffrée (`season`, `criteria`, `count`, `purchased`, `sold`, `soldShare`, `weeksToSell`,
`displayedCoefficient`, `obtainedCoefficient`, `discountRate`, `rows` bornées à 50, `more`). Le fil d'une facture s'en sert ; la console
IA (lot W2, `docs/console-gestion.md`) l'appellera telle quelle. Le verdict d'un article déjà en vente (garder, monter, baisser) est
calculé par le serveur (`CoefficientRule::verdict`), jamais par l'IA.

Tests : `SimilarArticlesAnalysisTest` (chaque ligne de la règle sur des ventes réelles, saison en cours exclue, moins de 5 semblables,
plancher, fournisseur, site), `CoefficientRuleTest`, `PriceRoundingTest`.

## 7. Portées et mode ajoutés au service des prix (lot IA-ACT-5)

`POST /api/gestion/prix/apercu` et `…/appliquer` acceptent, en plus de `productIds`, `commandeId` et `adviceId` (une seule portée par
demande) :

- `periodId` : les articles achetés dans une période (ceux des lignes de ses commandes) ; 422 `PERIOD_NOT_FOUND` ;
- `collectionId` : les articles d'une collection d'achats ; 422 `COLLECTION_NOT_FOUND` ;
- `categoryId` : les articles d'une catégorie de la boutique ; 422 `CATEGORY_NOT_FOUND`.

Pour une période ou une collection, le coût est le **coût courant** de l'article (règle L0, celui que la marge du site retient), pas
celui de ces seules commandes. 500 articles au plus par demande (`TOO_MANY`).

Mode `fixed` : `value` est un prix en cents, écrit tel quel, jamais arrondi ; pour une liste d'articles seulement (422 `mode`
`FIXED_NEEDS_LIST` avec une autre portée ; 422 `value` `INVALID_AMOUNT`, `MUST_BE_POSITIVE`, `OUT_OF_RANGE`).

Arrondis `psy_99`, `ends_95`, `integer` (§ 5) en plus de `none`, `ends_99`, `up_50`, `up_100`.

L'action `price.set` du fil (`docs/conversation-gestion.md` § 13) mène ces mêmes appels : son aperçu, son lot et son annulation sont
ceux de ce service.
