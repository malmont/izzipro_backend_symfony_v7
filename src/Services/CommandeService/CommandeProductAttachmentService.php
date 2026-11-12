<?php

namespace App\Services\CommandeService;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Rattachement de produits existants à une commande fournisseur (09/11/2026, application de gestion) : liste des produits à classer
 * (recherche, pagination), rattachement tout ou rien, détachement. Depuis le lot L0 (10/11/2026, `docs/lignes-achat-gestion.md`), un
 * rattachement crée ou met à jour une LIGNE D'ACHAT (`CommandeLineService`) : un article peut être acheté dans plusieurs commandes
 * (réassort), et un rattachement répété dans la même commande met sa ligne à jour (idempotent). Rien d'autre du produit n'est écrit :
 * ni le stock, ni le prix de vente, ni le prix d'achat de la fiche, ni le coefficient. Montants en cents.
 */
class CommandeProductAttachmentService
{
    public const MAX_LIMIT = 100;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit, private readonly CommandeLineService $lines, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null, private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null) // devise du site (lot G6) ; calendrier des périodes (lot P1, 10/11/2026)
    {
    }

    /**
     * Produits à classer (sans ligne d'achat ; `scope=all` : tous), recherche sur nom (traduction ou nom de base), code et code-barres, tri par nom.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function available(?string $q, int $page, int $limit, string $productsBaseUrl, string $locale = 'fr', string $scope = 'unclassified'): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $page = max(1, $page);
        $em = $this->emProvider->getEntityManager();
        // Identifiants d'abord (DISTINCT sur des scalaires : la fiche produit porte des colonnes json sans opérateur d'égalité), puis les entités
        $qb = $em->createQueryBuilder()->select('DISTINCT p.id AS id, p.name AS name')->from(Product::class, 'p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')->setParameter('locale', $locale)
            ;
        if ($scope !== 'all') { // « À classer » (lot L0) : articles sans aucune ligne d'achat ; scope=all : tout le catalogue (réassort)
            $qb->andWhere('NOT EXISTS (SELECT 1 FROM ' . CommandeLine::class . ' l WHERE l.product = p)');
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(t.name) LIKE :q OR LOWER(p.code) LIKE :q OR LOWER(p.barcode) LIKE :q')->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT p.id)')->getQuery()->getSingleScalarResult();
        $ids = array_map(fn ($r) => (int) $r['id'], $qb->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getArrayResult());
        $byId = [];
        foreach ($ids !== [] ? $em->getRepository(Product::class)->findBy(['id' => $ids]) : [] as $product) {
            $byId[(int) $product->getId()] = $product;
        }
        $products = array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)));

        return ['items' => array_map(fn (Product $p) => $this->item($p, $productsBaseUrl), $products), 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * Rattache des produits existants, tout ou rien.
     *
     * @param list<array<string, mixed>> $lines [{ productId, purchasePrice? (cents), coefficientMultiplier?, quantity? }]
     * @return array{attached: list<array<string, mixed>>}
     * @throws CommandeProductException
     */
    public function attach(Commande $commande, array $lines, ?string $by): array
    {
        if ($commande->getIsClosed()) {
            throw new CommandeProductException('COMMANDE_CLOSED', 'Cette commande est close : aucun rattachement possible', 409, [], ['commandeId' => $commande->getId()]);
        }
        if ($this->calendar !== null && $commande->getDate() !== null) { // période fermée par la date (lot P1 de la gestion, 10/11/2026)
            $period = $this->calendar->periodFor($commande->getDate());
            if ($period !== null && $this->calendar->isClosed($period)) {
                throw new CommandeProductException('PERIOD_CLOSED', sprintf('La période « %s » est clôturée : aucun rattachement', $period->getNomCollection()), 409, [], ['commandeId' => $commande->getId(), 'periodId' => $period->getId(), 'periodKey' => $period->getPeriodKey()]);
            }
        }
        $em = $this->emProvider->getEntityManager();
        $errors = [];
        $plan = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line) || !is_numeric($line['productId'] ?? null) || (int) $line['productId'] <= 0) {
                $errors[] = ['path' => "products[$i].productId", 'message' => 'identifiant de produit attendu'];
                continue;
            }
            $productId = (int) $line['productId'];
            $variantId = null;
            if (array_key_exists('variantId', $line) && $line['variantId'] !== null) {
                if (!is_numeric($line['variantId']) || (int) $line['variantId'] <= 0) {
                    $errors[] = ['path' => "products[$i].variantId", 'message' => 'identifiant de variante attendu'];
                    continue;
                }
                $variantId = (int) $line['variantId'];
            }
            if (isset($seen[$productId . '/' . $variantId])) {
                $errors[] = ['path' => "products[$i].productId", 'message' => 'produit en double dans la demande'];
                continue;
            }
            $seen[$productId . '/' . $variantId] = true;
            $cost = null;
            if (array_key_exists('purchasePrice', $line) && $line['purchasePrice'] !== null) {
                if (!is_numeric($line['purchasePrice']) || (float) $line['purchasePrice'] < 0 || (float) $line['purchasePrice'] !== (float) (int) $line['purchasePrice']) {
                    $errors[] = ['path' => "products[$i].purchasePrice", 'message' => 'entier en cents ≥ 0 attendu'];
                } else {
                    $cost = (int) $line['purchasePrice'];
                }
            }
            $coefficient = null;
            if (array_key_exists('coefficientMultiplier', $line) && $line['coefficientMultiplier'] !== null) {
                if (!is_numeric($line['coefficientMultiplier']) || (float) $line['coefficientMultiplier'] <= 0) {
                    $errors[] = ['path' => "products[$i].coefficientMultiplier", 'message' => 'nombre > 0 attendu'];
                } else {
                    $coefficient = (float) $line['coefficientMultiplier'];
                }
            }
            $quantity = null;
            if (array_key_exists('quantity', $line) && $line['quantity'] !== null) {
                if (!is_numeric($line['quantity']) || (int) $line['quantity'] < 0) {
                    $errors[] = ['path' => "products[$i].quantity", 'message' => 'entier ≥ 0 attendu'];
                } else {
                    $quantity = (int) $line['quantity'];
                }
            }
            // commande dans une devise d'achat (lot G6 de la gestion, 10/11/2026) : le coût par défaut (prix d'achat ou prix de vente du produit) est en devise du site, donc refusé
            if ($cost === null && $commande->getCurrency() !== null && $commande->getCurrency() !== strtoupper($this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)) {
                $errors[] = ['path' => "products[$i].purchasePrice", 'message' => sprintf('requis : commande en %s (coût en devise d\'achat)', $commande->getCurrency()), 'code' => 'PURCHASE_PRICE_REQUIRED'];
            }
            $plan[] = ['productId' => $productId, 'variantId' => $variantId, 'cost' => $cost, 'coefficient' => $coefficient, 'quantity' => $quantity, 'path' => "products[$i]"];
        }
        if ($plan === [] && $errors === []) {
            $errors[] = ['path' => 'products', 'message' => 'au moins un produit attendu'];
        }
        if ($errors !== []) {
            throw new CommandeProductException('VALIDATION_FAILED', 'Corps invalide : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], 422, $errors);
        }
        $products = [];
        foreach ($plan as $p) {
            $product = $em->find(Product::class, $p['productId']);
            if ($product === null) {
                throw new CommandeProductException('PRODUCT_NOT_FOUND', sprintf('Produit %d introuvable', $p['productId']), 404, [], ['productId' => $p['productId']]);
            }
            $variant = null;
            if ($p['variantId'] !== null) {
                $variant = $em->find(ProductVariant::class, $p['variantId']);
                if ($variant === null || $variant->getProduct()?->getId() !== $product->getId()) {
                    throw new CommandeProductException('VALIDATION_FAILED', sprintf('%s.variantId : variante %d absente du produit %d', $p['path'], $p['variantId'], $p['productId']), 422, [['path' => $p['path'] . '.variantId', 'message' => 'variante absente du produit', 'code' => 'VARIANT_NOT_IN_PRODUCT']]);
                }
            }
            $products[] = [$product, $variant, $p];
        }
        // lot L0 : plus de PRODUCT_ALREADY_ATTACHED ; un article acheté ailleurs reçoit une ligne de plus (réassort), le même dans cette commande est mis à jour
        $attached = [];
        $lines = [];
        $updated = [];
        $before = [];
        $repo = $em->getRepository(CommandeLine::class);
        foreach ($products as [$product, $variant, $p]) {
            $existing = $repo->findOne($commande, $product, $variant);
            $before[] = ['productId' => $product->getId(), 'variantId' => $variant?->getId(), 'lineId' => $existing?->getId(), 'quantity' => $existing?->getQuantityOrdered(), 'unitCost' => $existing?->getUnitCostCents()];
            // coût : purchasePrice du corps, sinon celui de la ligne déjà là, sinon le prix d'achat de la fiche ; sinon inconnu (NULL), jamais le prix de vente
            $cost = $p['cost'] ?? $existing?->getUnitCostCents() ?? ($product->getPurchasePrice() !== null ? (int) round((float) $product->getPurchasePrice()) : null);
            // quantité de la ligne : celle du corps, sinon celle de la ligne déjà là, sinon le stock actuel de l'article (comme avant L0)
            $quantity = $p['quantity'] ?? $existing?->getQuantityOrdered() ?? (int) ($product->getQuantity() ?? 0);
            $result = $this->lines->upsert($commande, $product, $variant, $quantity, $cost, false, CommandeLine::SOURCE_MANUAL, null, null, $by);
            $line = $result['line'];
            if (!$result['created']) {
                $updated[] = $line->getId();
            }
            $effective = $cost ?? (int) round((float) ($product->getPrice() ?? 0)); // coût retenu par les tableaux de bord (repli sur le prix de vente quand le coût est inconnu, inchangé)
            $attached[] = [
                'productId' => $product->getId(), 'name' => $product->getName(), 'commandeCostCents' => $effective,
                'coefficientMultiplier' => $p['coefficient'] ?? $product->getCoefficientMultiplier(), 'quantity' => $line->getQuantityOrdered(), 'price' => $product->getPrice(),
                'lineId' => $line->getId(), 'variantId' => $variant?->getId(),
            ];
            $lines[] = self::lineArray($line);
        }
        $this->audit->record('commande-products', $commande->getId(), 'attach', $before, $attached, array_map(fn ($a) => 'product:' . $a['productId'], $attached));

        return ['attached' => $attached, 'lines' => $lines, 'updated' => $updated];
    }

    /** Forme d'une ligne d'achat dans les réponses */
    public static function lineArray(CommandeLine $line): array
    {
        $variant = $line->getVariant();
        $product = $line->getProduct();

        return [
            'id' => $line->getId(), 'commandeId' => $line->getCommande()?->getId(),
            'product' => ['id' => $product?->getId(), 'name' => $product?->getName(), 'code' => $product?->getCode()],
            'productId' => $product?->getId(),
            'variant' => $variant === null ? null : ['id' => $variant->getId(), 'size' => $variant->getSize()?->getName(), 'color' => $variant->getColor()?->getName()],
            'variantId' => $variant?->getId(),
            'quantity' => $line->getQuantityOrdered(), 'quantityOrdered' => $line->getQuantityOrdered(), 'quantityReceived' => $line->getQuantityReceived(),
            'remaining' => max(0, $line->getQuantityOrdered() - $line->getQuantityReceived()), // à recevoir (lot R1)
            'unitCost' => $line->getUnitCostCents(), 'currency' => $line->getCommande()?->getCurrency(), 'estimated' => $line->isEstimated(),
            'attachedAt' => $line->getAttachedAt()?->format(\DateTimeInterface::ATOM), 'source' => $line->getSource(),
        ];
    }

    /** @throws CommandeProductException */
    public function detach(Commande $commande, int $productId, ?string $by): void
    {
        if ($commande->getIsClosed()) {
            throw new CommandeProductException('COMMANDE_CLOSED', 'Cette commande est close : aucun détachement possible', 409, [], ['commandeId' => $commande->getId()]);
        }
        if ($this->calendar !== null && $commande->getDate() !== null) { // période fermée par la date (lot P1 de la gestion, 10/11/2026)
            $period = $this->calendar->periodFor($commande->getDate());
            if ($period !== null && $this->calendar->isClosed($period)) {
                throw new CommandeProductException('PERIOD_CLOSED', sprintf('La période « %s » est clôturée : aucun rattachement', $period->getNomCollection()), 409, [], ['commandeId' => $commande->getId(), 'periodId' => $period->getId(), 'periodKey' => $period->getPeriodKey()]);
            }
        }
        $em = $this->emProvider->getEntityManager();
        $product = $em->find(Product::class, $productId);
        $lines = $product !== null ? $em->getRepository(CommandeLine::class)->forCommandeAndProduct($commande, $product) : [];
        if ($lines === []) {
            throw new CommandeProductException('PRODUCT_NOT_IN_COMMANDE', sprintf('Le produit %d n\'est pas dans cette commande', $productId), 404, [], ['productId' => $productId, 'commandeId' => $commande->getId()]);
        }
        // ART-SUPPR (13/11/2026) : une ligne dont le stock est entré par une réception (lot R1 : mouvement de stock rattaché à la ligne), même
        // en partie, ne se détache pas : le stock garderait une entrée sans la ligne d'achat qui l'explique. Une ligne « réputée reçue » (rattacher,
        // reprise : l'article était déjà en stock, aucun mouvement) et une ligne non reçue se détachent comme avant.
        $conn = $em->getConnection();
        $received = array_values(array_filter($lines, fn (CommandeLine $l) => $l->getQuantityReceived() > 0
            && (int) $conn->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE commande_line_id = ?', [$l->getId()]) > 0));
        if ($received !== []) {
            throw new CommandeProductException('LINE_RECEIVED', 'Cet article a déjà été reçu en stock pour cette commande : annulez d\'abord la réception.', 409, [],
                ['productId' => $productId, 'commandeId' => $commande->getId(), 'lines' => array_map(fn (CommandeLine $l) => ['id' => $l->getId(), 'quantityReceived' => $l->getQuantityReceived()], $received)]);
        }
        $before = ['productId' => $productId, 'commandeId' => $commande->getId(), 'lines' => array_map(fn (CommandeLine $l) => ['id' => $l->getId(), 'quantity' => $l->getQuantityOrdered(), 'unitCost' => $l->getUnitCostCents()], $lines)];
        $this->lines->deleteProductLines($commande, $product); // lignes de cet article dans cette commande (lot L0) ; raccourcis du produit recalculés
        $this->audit->record('commande-products', $commande->getId(), 'detach', $before, ['productId' => $productId, 'commandeId' => null], ['product:' . $productId]);
    }

    /** @return array<string, mixed> */
    private function item(Product $p, string $productsBaseUrl): array
    {
        return [
            'id' => $p->getId(), 'name' => $p->getName(), 'code' => $p->getCode(), 'barcode' => $p->getBarcode(),
            'image' => MediaUrlResolver::joinStored($p->getImage(), $productsBaseUrl),
            'price' => $p->getPrice() !== null ? (int) round((float) $p->getPrice()) : null, 'purchasePrice' => $p->getPurchasePrice() !== null ? (int) round((float) $p->getPurchasePrice()) : null,
            'coefficientMultiplier' => $p->getCoefficientMultiplier(), 'quantity' => $p->getQuantity(),
            'categories' => array_values(array_map(fn ($c) => ['id' => $c->getId(), 'name' => $c->getName()], $p->getCategory()->toArray())),
        ];
    }
}
