# Lignes d'achat — application de gestion (lot L0, 10/11/2026)

Contrat validé par l'application (« new easymakemoney ») et le propriétaire, avec cinq corrections et l'amendement « rattacher
idempotent » de la session de la caisse. Prérequis de la confirmation des factures fournisseurs (fin du lot L1) et de la réception de
stock (lot R1). Code : `Entity/CommandeLine` (table `commande_line`), `Repository/CommandeLineRepository`,
`Services/CommandeService/CommandeLineService` (seule écriture), `Services/CommandeService/CommandeProductAttachmentService`
(rattacher, détacher, « à classer »), `UseCase/CommandeUseCase/CommandeLineUseCase` + `Controller/CommandeController/CommandeLineController`,
`Services/GestionService/CommandeLinesVerifier` + `Command/VerifyCommandeLinesCommand`. Tests : `tests/Functional/Gestion/CommandeLinesApiTest.php`,
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

## 6. Hors L0

- Confirmation des factures fournisseurs lues par IA (fin de L1) : créera des lignes `source = document`.
- Réception (R1, contrat à part, recommandations de la caisse) : `POST /api/commandes/{id}/lignes/{lineId}/reception`, mouvement de stock
  type 5 « Réception fournisseur » par variante, annulation par mouvement inverse type 4, jamais automatique, sans effet sur la vente.
