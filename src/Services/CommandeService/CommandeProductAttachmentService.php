<?php

namespace App\Services\CommandeService;

use App\Entity\Commande;
use App\Entity\Product;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Rattachement de produits existants à une commande fournisseur (09/11/2026, application de gestion) : liste des produits sans commande
 * (recherche, pagination), rattachement tout ou rien, détachement. Jamais `setPurchasePrice` ni `setCoefficientMultiplier` (ils recalculent
 * le prix de vente) : seuls `commande`, `commande_cost_cents` et, si fournie, `quantity` sont écrits. Montants en cents.
 */
class CommandeProductAttachmentService
{
    public const MAX_LIMIT = 100;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null, private readonly ?\App\Services\GestionService\PeriodCalendar $calendar = null) // devise du site (lot G6) ; calendrier des périodes (lot P1, 10/11/2026)
    {
    }

    /**
     * Produits sans commande, recherche sur nom (traduction ou nom de base), code et code-barres, tri par nom.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function available(?string $q, int $page, int $limit, string $productsBaseUrl, string $locale = 'fr'): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $page = max(1, $page);
        $em = $this->emProvider->getEntityManager();
        // Identifiants d'abord (DISTINCT sur des scalaires : la fiche produit porte des colonnes json sans opérateur d'égalité), puis les entités
        $qb = $em->createQueryBuilder()->select('DISTINCT p.id AS id, p.name AS name')->from(Product::class, 'p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')->setParameter('locale', $locale)
            ->andWhere('p.commande IS NULL');
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
            if (isset($seen[$productId])) {
                $errors[] = ['path' => "products[$i].productId", 'message' => 'produit en double dans la demande'];
                continue;
            }
            $seen[$productId] = true;
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
            $plan[] = ['productId' => $productId, 'cost' => $cost, 'coefficient' => $coefficient, 'quantity' => $quantity, 'path' => "products[$i]"];
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
            if ($product->getCommande() !== null && $product->getCommande()->getId() !== $commande->getId()) {
                throw new CommandeProductException('PRODUCT_ALREADY_ATTACHED', sprintf('Le produit %d est déjà dans la commande %d', $p['productId'], $product->getCommande()->getId()), 409, [], ['productId' => $p['productId'], 'commandeId' => $product->getCommande()->getId()]);
            }
            $products[] = [$product, $p];
        }
        $attached = [];
        $before = [];
        foreach ($products as [$product, $p]) {
            $before[] = ['productId' => $product->getId(), 'commandeId' => $product->getCommande()?->getId(), 'commandeCostCents' => $product->getCommandeCostCents(), 'quantity' => $product->getQuantity()];
            $product->setCommande($commande);
            // coût retenu : purchasePrice du corps (cents), sinon celui déjà posé, sinon le prix d'achat du produit, sinon son prix de vente ; jamais setPurchasePrice (recalcul du prix)
            $product->setCommandeCostCents($p['cost'] ?? $product->getCommandeCostCents() ?? (int) round((float) ($product->getPurchasePrice() ?? $product->getPrice() ?? 0)));
            if ($p['quantity'] !== null) {
                $product->setQuantity($p['quantity']);
            }
            $attached[] = [
                'productId' => $product->getId(), 'name' => $product->getName(), 'commandeCostCents' => $product->getCommandeCostCents(),
                'coefficientMultiplier' => $p['coefficient'] ?? $product->getCoefficientMultiplier(), 'quantity' => $product->getQuantity(), 'price' => $product->getPrice(),
            ];
        }
        $em->flush();
        $this->audit->record('commande-products', $commande->getId(), 'attach', $before, $attached, array_map(fn ($a) => 'product:' . $a['productId'], $attached));

        return ['attached' => $attached];
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
        if ($product === null || $product->getCommande()?->getId() !== $commande->getId()) {
            throw new CommandeProductException('PRODUCT_NOT_IN_COMMANDE', sprintf('Le produit %d n\'est pas dans cette commande', $productId), 404, [], ['productId' => $productId, 'commandeId' => $commande->getId()]);
        }
        $before = ['productId' => $productId, 'commandeId' => $commande->getId(), 'commandeCostCents' => $product->getCommandeCostCents()];
        $product->setCommande(null);
        $product->setCommandeCostCents(null);
        $em->flush();
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
