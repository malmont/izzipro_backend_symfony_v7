# Lignes d'achat — application de gestion (lot L0, 10/11/2026)

Contrat validé par l'application (« new easymakemoney ») et le propriétaire, avec cinq corrections et l'amendement « rattacher
idempotent » de la session de la caisse. Prérequis de la confirmation des factures fournisseurs (fin du lot L1) et de la réception de
stock (lot R1). Code : `Entity/CommandeLine` (table `commande_line`), `Repository/CommandeLineRepository`,
`Services/CommandeService/CommandeLineService` (seule écriture), `Services/CommandeService/CommandeProductAttachmentService`
(rattacher, détacher, « à classer »), `UseCase/CommandeUseCase/CommandeLineUseCase` + `Controller/CommandeController/CommandeLineController`,
`Gestion/Services/CommandeLinesVerifier` + `Command/VerifyCommandeLinesCommand`. Tests : `tests/Functional/Gestion/CommandeLinesApiTest.php`,
`tests/Functional/Boutique/CommandeProductAttachmentApiTest.php`.

## 1. Pourquoi

Avant L0, un article n'avait qu'une commande fournisseur (`product.commande_id`) : un réassort du même article dans une autre commande
finissait en 409 `PRODUCT_ALREADY_ATTACHED`, ce qui bloquait la lecture des factures. Une **ligne d'achat** = un article (ou une variante)
acheté dans une commande ; un article peut en avoir dans plusieurs commandes.

## 2. Modèle

`commande_line { id, commande_id (ON DELETE CASCADE), product_id (ON DELETE CASCADE), product_variant_id (facultatif, ON DELETE SET NULL),
quantity_ordered, quantity_received, unit_cost_cents (devise de la commande ; NULL = coût inconnu, jamais le prix de vente), estimated,
attached_at (date de saisie = coupure des résultats de période), source manual | reprise | document | migration, created_by, created_at,
updated_at }`. Unicité par **index partiels** (NULL est distinct de NULL dans un index unique ordinaire) : `(commande_id, product_id)`
quand la variante est NULL, `(commande_id, product_id, product_variant_id)` sinon.

- `product.commande_id`, `commande_cost_cents`, `commande_quantity`, `commande_attached_at`, `commande_cost_estimated` deviennent des
  **raccourcis** de la dernière ligne saisie du produit (`CommandeLineService::refreshShortcuts`), remis à NULL sans ligne ; plus jamais la
  vérité. L'écran produit d'EasyAdmin les montre en lecture seule (« Dernière commande fournisseur »).
- **Rien d'autre du produit n'est écrit** par les lignes : ni `quantity` (stock, dérivé des variantes, voir `docs/caisse.md`), ni le prix de
  vente, ni le prix d'achat de la fiche, ni le coefficient, ni la visibilité.
- Lignes créées par rattacher, par la création d'un produit dans une commande, par la reprise d'historique et par la migration : réputées
  **reçues** (`quantity_received = quantity_ordered`, l'article est déjà en stock). Les lignes « à recevoir » arrivent avec R1.

## 3. Migration

`Version20261202100000` et `scripts/migrate_all_v2_gestion_lignes.sh` (additifs, idempotents, toutes les bases et le modèle des tests) :
table, index, puis une ligne `source = migration` par produit rattaché (`CommandeLineService::BACKFILL_SQL`, même texte que la migration,
vérifié par un test) : quantité = `commande_quantity ?? quantity`, reçue = commandée, coût = `commande_cost_cents`, sauf coût inconnu
(ni coût ni prix d'achat, ou coût égal au prix de vente sans prix d'achat : ancien repli du rattachement) → NULL, sinon `purchase_price` ;
`attached_at = commande_attached_at ?? date de la commande`.

**Preuve** : `php bin/console app:gestion:lignes:verifier [--tenant=code]` (lecture seule) calcule chaque tableau de bord (commandes,
collections, périodes, combinés) et chaque résultat de période avec l'ancien calcul (`PurchaseMetricsCalculator::legacy`,
`PeriodResultCalculator::compute(..., legacy: true)`) et avec les lignes. Code 1 au moindre écart de tableau de bord, ou d'un résultat de
période sans vente antérieure à la date de l'achat (seule cause admise : la règle du § 4).

## 4. Calculs

- Tableaux de bord, combinés, export comptable : sur les lignes. Coût = `COALESCE(unit_cost_cents, purchase_price, price, 0) × taux` (le
  repli sur le prix de vente des tableaux de bord est inchangé) ; quantité = `quantity_ordered` ; modèles = articles distincts.
- **Coût d'une vente** (résultat de période P2, reprise P3) : **moyenne pondérée glissante** des lignes de l'article au coût connu dont la
  commande est datée au plus tard le jour de la vente (jour du magasin), pondérée par `quantity_ordered`, × taux ; pour une période fermée,
  lignes saisies avant la coupure seulement (règle P2 inchangée). Sans telle ligne : `purchase_price` de la fiche, sinon coût inconnu
  (exclu des marges). Un achat daté après une vente ne lui est jamais imputé. Vente « estimée » si une ligne de la moyenne l'est.

## 5. Routes (`ROLE_ADMIN`)

- `POST /api/commandes/{id}/products/rattacher { products: [{ productId, variantId?, purchasePrice?, coefficientMultiplier?, quantity? }] }`
  → 200 `{ attached: [{ productId, name, commandeCostCents, coefficientMultiplier, quantity, price, lineId, variantId }], lines: [ligne],
  updated: [lineId], commande: { id, usedBudget, remainingBudget } }`. Une ligne par entrée ; **même article (et même variante) déjà dans
  cette commande : ligne mise à jour**, idempotent (réessai après coupure) ; acheté dans une autre commande : ligne de plus (réassort).
  `PRODUCT_ALREADY_ATTACHED` n'existe plus. **`attached[].commandeCostCents` est le coût effectif des tableaux de bord** (repli sur le prix
  d'achat de la fiche puis le prix de vente quand le coût est inconnu) ; **`lines[].unitCost` est le vrai coût de la ligne, NULL quand inconnu** :
  l'application lit `lines[].unitCost` pour afficher un coût d'achat. Quantité absente : celle de la ligne déjà là, sinon le **stock actuel** de l'article ; coût
  absent : celui de la ligne déjà là, sinon `purchase_price`, sinon inconnu. `commandeCostCents` rend le coût retenu par les tableaux de
  bord (repli sur le prix de vente). 422 `VALIDATION_FAILED` (dont `VARIANT_NOT_IN_PRODUCT`), 404, 409 `COMMANDE_CLOSED` / `PERIOD_CLOSED`.
- `DELETE /api/commandes/{id}/products/{productId}` → 204, lignes de l'article dans la commande supprimées (404 `PRODUCT_NOT_IN_COMMANDE`).
  L'article, ses autres achats et son stock ne bougent pas. Depuis le 12/11/2026 (ART-SUPPR) : 409 **`LINE_RECEIVED`**
  `{ context: { productId, commandeId, lines: [{ id, quantityReceived }] } }` si le stock d'une de ces lignes est entré par une
  réception (lot R1 : un mouvement de stock est rattaché à la ligne) : il faut d'abord annuler la réception. Une ligne « réputée
  reçue » (rattacher, reprise : l'article était déjà en stock, aucun mouvement) se détache comme avant.
- **Suppression d'un article ou d'une variante** (ART-SUPPR, 12/11/2026, `Services/ProductService/ProductUsageGuard`,
  `EventSubscriber/ProductUsageGuardSubscriber`) : refusée quand il a une histoire, 409 **`PRODUCT_IN_USE`**
  `{ error, code, message, productId, variantId?, uses }`, rien n'est effacé.
  - `uses` compte, 0 compris : `sales` (lignes de vente), `purchaseLines` (lignes d'achat), `stockMovements` (mouvements de stock,
    un seul suffit), `bookings` (réservations), `subscriptionPlans` (formules d'abonnement), `openCheckouts` (paiements en cours).
  - Message : « Cet article a des ventes, des achats ou des mouvements de stock : retirez-le de la vente au lieu de le supprimer. »
    Pour une variante : mettre son stock à 0. Retirer un article de la vente : `isWeb`, `isPos` et `saleEnabled` à faux. Il n'y a
    pas encore d'archivage.
  - La garde est au niveau de Doctrine : elle couvre `DELETE /api/products/{id}` (servie par API Platform, qui masque la route du
    contrôleur), `DELETE /api/product-variants/{id}` et EasyAdmin (même message à l'écran).
  - Un article ou une variante sans histoire se supprime comme avant (204).
  - Avant : la suppression d'un article effaçait en silence ses lignes d'achat (`commande_line.product_id` ON DELETE CASCADE) et
    l'historique de stock de ses variantes ; une variante supprimée perdait son historique de stock.
  - Ce qui part avec un article sans histoire : ses variantes, images, traductions, son réglage de location (`booking_configuration`,
    s'il n'a aucune réservation), sa fiche véhicule et ses liens d'articles associés (`related_product`). Un contenu « véhicule » de la
    page (`vehicle`, lien facultatif) est gardé et détaché. Une réservation est une histoire (`uses.bookings`).
- `GET /api/commandes/products/disponibles?scope=` : défaut « à classer » = articles **sans aucune ligne d'achat** ; `scope=all` : tout le
  catalogue (réassort).
- `GET /api/commandes/{id}/products` : articles distincts des lignes de la commande.
- `GET /api/commandes/{id}/lignes` → `[{ id, commandeId, product: { id, name, code }, productId, variant: { id, size, color } | null, variantId,
  quantity, quantityOrdered, quantityReceived, unitCost, currency, estimated, attachedAt, source }]`.
- `PATCH /api/commandes/{id}/lignes/{lineId} { quantity?, unitCost? (null = inconnu), variantId? }` → la ligne ; `DELETE …/{lineId}` → 204 ;
  gardes 409 `COMMANDE_CLOSED` / `PERIOD_CLOSED`, 404 `LINE_NOT_FOUND`, 409 `LINE_ALREADY_EXISTS { lineId }` (variante déjà prise),
  422 `VALIDATION_FAILED`. Raccourcis du produit recalculés après chaque écriture.
- Reprise d'historique : un article ayant au moins une ligne est ignoré (`skipped ALREADY_ATTACHED`) ; lignes `source = reprise`, retirées
  par « annuler ». Suppression d'une commande : ses lignes partent avec elle, les raccourcis de leurs articles sont recalculés.

Cache : `CommandeLine` est déclarée dans `CacheTags::ENTITY_TAGS` (commandes, produits) ; l'écriture SQL des raccourcis invalide en plus la
fiche et les listes du produit (`CacheInvalidationQueue`).

## 6. Confirmation des factures fournisseurs (fin du lot L1, 10/11/2026)

La confirmation d'une facture lue par IA (`docs/lecture-documents-gestion.md` § 4) crée la commande et ses lignes `source = document`,
**à recevoir** (quantité reçue 0) : article existant = une ligne de plus (réassort, avec `variantId` facultatif), sans effet sur le stock ni
la fiche ; nouvel article (par `groupKey`) créé invisible avec un **stock de 0**, variantes comprises, une ligne par variante (sinon une pour
l'article) ; deux lignes de facture pour le même article et la même variante sont regroupées (quantités additionnées, coût moyen pondéré).
La réponse de `confirmer` porte `lines` ; l'application enchaîne sur « Marquer comme reçue maintenant » (§ 7). Règle du propriétaire :
aucune écriture de stock hors de la réception.

## 7. Réception de stock (lot R1, 10/11/2026)

Contrat validé par l'application ; partie stock relue par la session de la caisse. Code : `Services/CommandeService/CommandeReceptionService`
(seule écriture de stock de la gestion), `UseCase/OrderUseCase/UpdateStockAndInventoryUseCase::executeReception`, routes dans
`CommandeLineController`. Migration `Version20261203100000`, script `scripts/migrate_all_v2_gestion_reception.sh` (type de mouvement 5
« Réception fournisseur » à id fixe, `inventory_movements.commande_line_id`). Test : `tests/Functional/Gestion/CommandeReceptionApiTest.php`.

- `POST /api/commandes/{id}/lignes/{lineId}/reception { quantity?, variantId? }` → 200 `{ line, movement: { id, variantId, quantity, type,
  stockBefore, stockAfter } }` ; quantité par défaut : tout le reste ; 422 `VALIDATION_FAILED` (`quantity` : `MUST_BE_POSITIVE` |
  `EXCEEDS_REMAINING` ; `variantId` : `VARIANT_NOT_IN_PRODUCT`), 422 `VARIANT_REQUIRED { lineId, variants: [{ id, size, color }] }`,
  409 `NOTHING_TO_RECEIVE`, 404 `LINE_NOT_FOUND`.
- `POST /api/commandes/{id}/reception { lines?: [{ lineId, quantity?, variantId? }] }` (« Marquer comme reçue maintenant ») → 200 `{ received:
  [{ lineId, quantity, movementId }], needsVariant: [{ lineId, product: { id, name, code }, variants }], lines, commande: { id, remaining,
  receptionStatus } }` : reçoit tout ce qui peut l'être (sans corps : tout le reste de la commande) ; les lignes dont la variante est à
  choisir reviennent dans `needsVariant`, sans être reçues ; **idempotent** (commande déjà reçue : `received` vide) ; les vraies erreurs
  (ligne inconnue, quantité invalide, base) annulent tout.
- `POST /api/commandes/{id}/lignes/{lineId}/reception/annuler { quantity? }` → 200 `{ line, movement }` : mouvement inverse **type 4
  « Ajustement »** (quantité négative) sur la variante qui a reçu, jamais une suppression ; 409 `STOCK_INSUFFICIENT { variantId, stock }`
  (jamais de stock négatif), 409 `NOTHING_TO_CANCEL`, 422 `EXCEEDS_RECEIVED`.
- `GET /api/commandes/lignes/a-recevoir?page=&limit=` (50, au plus 200) → lignes au reste > 0, toutes commandes, avec `commande { id, name,
  date }`, triées par date de commande ; `X-Total-Count`, `X-Page`, `X-Limit`. Les lignes portent `remaining`, les commandes `receptionStatus`
  (`none` | `partial` | `complete`).

Règles :
- **Variante reçue** : celle de la ligne ; sinon l'unique variante de l'article ; sinon (plusieurs) le choix du gérant (`variantId`) ; sinon
  (aucune) une **variante par défaut** créée sous verrou : `color_id` et `size_id` NULL, **aucune option, prix NULL** (le prix de la fiche
  s'applique et suit ses changements ; jamais une couleur ou une taille de remplacement : l'application e-commerce afficherait à tort une
  personnalisation), stock initial = stock actuel de l'article (le recalcul `Product::updateQuantity` ne l'efface pas), puis la réception
  s'ajoute. Ce format est le cas courant (5 articles sur 8 dans `gmasuite`) ; les trois fronts ont confirmé, code à l'appui, qu'il s'affiche
  et se vend comme un article simple (boutique web : `getOptionTypes`, `isSimpleProduct`, `resolveSalePrice` ; application e-commerce :
  `optionTypes`, `defaultSelection`, `resolveSalePrice` ; caisse : choix de variante seulement si plus d'une variante (`article_card.dart`, `product_details_view_page.dart`, `barcode_scan_listener.dart`), prix de la fiche par `Variant.priceOr` en ligne et `resolveUnitPrice` hors ligne, scan par `findByBarcode`). Effet visible : un article sans
  variante, invendable jusque-là (le panier exige une variante), devient vendable après sa première réception s'il est actif à la vente.
- **Transaction explicite** (`wrapInTransaction`, les rappels de `ProductVariant` re-flushent) ; **verrous** `SELECT … FOR UPDATE` dans
  l'ordre de la vente, pour éviter tout interblocage avec un ticket de caisse : commande (geste unique), ligne, puis variante ; l'article
  n'est verrouillé que s'il n'a aucune variante (aucune vente possible en face), puis revérifié avant de créer la variante par défaut.
- Limite connue, hors R1 (chemin de la vente, signalée par la session de la caisse) : la vente écrit le stock depuis la valeur en mémoire,
  sans verrou ; une vente et une réception simultanées sur la même variante peuvent perdre l'une des deux mises à jour, comme deux ventes.
- Mouvement type 5 relié à la ligne (`commande_line_id`), stock avant et après ; `product.quantity` se recalcule depuis les variantes.
  Aucun autre effet : prix, prix d'achat, coefficient, visibilité, vente et pack hors ligne de la caisse intacts.
- Réception **permise sur une commande figée ou une période fermée** (c'est du stock) ; les chiffres figés ne changent pas.
- Journal `content_audit_log` (`commande-reception` : receive, cancel).

## 8. Hors de ces lots

- Réception depuis EasyAdmin : non (l'application et la future version web suffisent).

