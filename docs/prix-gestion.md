# Appliquer un prix — application de gestion (lot P4, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, sur `docs/propositions-pilotage.md` de l'application : « appliquer le
coefficient à un ou plusieurs articles après simulation : le prix de vente doit bien être recalculé ; route dédiée, et le prix ne change qu'après
confirmation ». Suite du guide (`docs/guide-ia-gestion.md`) : un conseil FAST_SELLER (+5 %) s'applique sans retoucher chaque fiche.
Tout est additif ; `ROLE_ADMIN` (`access_control` `/api/gestion`) ; montants en cents.

Code : `Services/GestionService/GestionPriceService`, `UseCase/GestionUseCase/GestionPriceUseCase`, `Controller/GestionController/GestionPriceController`,
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
