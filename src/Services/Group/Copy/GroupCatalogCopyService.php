<?php

namespace App\Services\Group\Copy;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Import\CatalogImportService;
use App\Import\ImportException;
use App\Import\ImportImageFetcher;
use App\Import\ImportJobStore;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Group\GroupException;
use App\Services\Group\GroupRepository;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Lot G-4 (19/11/2026, contrat validé par le chef, `docs/ecrans-et-contextes.md` § 15) : copie du catalogue d'une boutique du groupe vers
 * une autre, par le DIRECTEUR (administrateur du siège, son compte, sur le site du siège).
 *
 * - La tâche est un `import_job` de la CIBLE (source `group`) : planificateur, écrivain, rejeu sans doublon et annulation de 7 jours de
 *   l'import (`CatalogImportService`). La cible voit la copie dans ses imports et peut l'annuler elle-même.
 * - La source est lue dans une transaction READ ONLY ; son catalogue est figé en JSON dans la cible (forme d'une lecture Shopify).
 * - Copiés : nom, description, catégorie, variantes (taille, couleur, options), prix, code, visibilité de la source ; photos RECOPIÉES.
 *   Jamais le stock. Codes-barres : gardés si la cible ne les porte pas (CB-1).
 * - Ajouts du chef : coût d'achat copié seulement si la source est le siège (ou `withCost: true`) ; articles MASQUÉS par défaut (ni en
 *   ligne ni en caisse) ; avis indispensable aux administrateurs de la cible à la confirmation.
 * - Devises différentes : 409 `COPY_CURRENCY_DIFFERS` (aucune conversion). Correspondance article et variante dans la base maître.
 * - Le site du siège est repris quoi qu'il arrive (`finally`).
 */
class GroupCatalogCopyService
{
    public const MAX_IMAGES = 10;

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupCopyRepository $copies,
        private readonly ModuleAccessService $modules,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenants,
        private readonly TenantCurrencyProvider $currency,
        private readonly TaxEngine $taxes,
        private readonly CatalogImportService $imports,
        private readonly ImportJobStore $jobs,
        private readonly GroupCopyNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Aperçu : rien n'est écrit dans le catalogue de la cible (une tâche « analysée » seulement).
     *
     * @param array<string, mixed> $body { source?: string, target: string, articles?: list<int> }
     * @return array<string, mixed>
     */
    public function analyse(User $by, array $body): array
    {
        $group = $this->directedGroup($by);
        $head = (string) $group['head_tenant_code'];
        $source = is_string($body['source'] ?? null) && $body['source'] !== '' ? $body['source'] : $head;
        $target = is_string($body['target'] ?? null) ? $body['target'] : '';
        $articles = $body['articles'] ?? null;
        if ($target === '' || $source === $target || ($articles !== null && (!is_array($articles) || !array_is_list($articles) || count($articles) > CatalogImportService::GROUP_MAX_PRODUCTS
            || array_filter($articles, static fn ($id) => !is_int($id) || $id <= 0) !== []))) {
            throw new GroupException('VALIDATION_FAILED', 422, 'Copie : une boutique cible différente de la source, et des articles en entiers positifs.');
        }
        $shops = $this->shops((int) $group['id']);
        if (!isset($shops[$source], $shops[$target])) {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est pas dans votre groupe.');
        }
        $home = $this->home();
        try {
            [$products, $currency, $inclusive] = $this->readSource($shops[$source], $source, $articles);
            $this->emProvider->switchTenant((string) $shops[$target]['dbname'], $target);
            if ($this->currency->code(true) !== $currency) {
                throw new GroupException('COPY_CURRENCY_DIFFERS', 409, 'Les deux boutiques n\'ont pas la même devise : aucune conversion n\'est faite.');
            }
            $view = $this->imports->createFromProducts('Copie depuis ' . ($shops[$source]['name'] ?: $source), $products, $by->getUserIdentifier());
            $copyId = $this->copies->create((int) $group['id'], $source, $target, (int) $view['id'], count($products), $inclusive, $by->getUserIdentifier());
        } catch (ImportException $e) {
            throw new GroupException($e->errorCode, $e->status, $e->getMessage(), $e->extra);
        } finally {
            $this->restore($home);
        }

        return $this->present($this->copies->find($copyId), $view);
    }

    /**
     * @param array<string, mixed> $body { visibility?: hidden|as_source, pos?: bool, withCost?: bool }
     * @return array<string, mixed>
     */
    public function confirm(User $by, int $copyId, array $body): array
    {
        $group = $this->directedGroup($by);
        $copy = $this->ownCopy($group, $copyId);
        $errors = [];
        $visibility = $body['visibility'] ?? 'hidden';
        if (!in_array($visibility, ['hidden', 'as_source'], true)) {
            $errors['visibility'] = 'INVALID_VALUE';
        }
        foreach (['pos', 'withCost'] as $flag) {
            if (array_key_exists($flag, $body) && !is_bool($body[$flag])) {
                $errors[$flag] = 'MUST_BE_BOOLEAN';
            }
        }
        if ($errors !== []) {
            throw new GroupException('VALIDATION_FAILED', 422, 'Choix de la copie invalides.', ['fields' => $errors]);
        }
        // ajout 1 du chef : le coût d'achat n'est copié par défaut que depuis le siège ; d'une autre boutique, seulement sur demande explicite
        $fromHead = $copy['source_tenant_code'] === $group['head_tenant_code'];
        $withCost = array_key_exists('withCost', $body) ? (bool) $body['withCost'] : $fromHead;
        if (!$this->copies->confirm($copyId, $withCost)) {
            throw new GroupException('COPY_NOT_READY', 409, 'Cette copie est déjà confirmée ou annulée.');
        }
        $shops = $this->shops((int) $group['id']);
        $target = (string) $copy['target_tenant_code'];
        if (!isset($shops[$target])) {
            $this->copies->unconfirm($copyId);
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est plus dans votre groupe.');
        }
        $home = $this->home();
        try {
            $this->emProvider->switchTenant((string) $shops[$target]['dbname'], $target);
            // ajout 2 du chef : masqués par défaut, ni en ligne (`hidden`) ni en caisse (`pos` faux)
            $view = $this->imports->confirm((int) $copy['target_job_id'], ['visibility' => $visibility, 'pos' => (bool) ($body['pos'] ?? false),
                'pricesIncludeTax' => $copy['source_prices_include_tax']], $by->getUserIdentifier(), ['withoutCost' => !$withCost]);
            // ajout 3 du chef : chaque administrateur de la cible est prévenu
            $this->notifier->copied($copyId, (string) $group['name'], (int) ($view['report']['products']['importable'] ?? $copy['articles']));
        } catch (ImportException $e) {
            $this->copies->unconfirm($copyId);
            throw new GroupException($e->errorCode, $e->status, $e->getMessage(), $e->extra);
        } finally {
            $this->restore($home);
        }

        return $this->present($this->copies->find($copyId), $view);
    }

    /** @return array<string, mixed> */
    public function cancel(User $by, int $copyId): array
    {
        $group = $this->directedGroup($by);
        $copy = $this->ownCopy($group, $copyId);
        $tenant = $this->tenantRow((string) $copy['target_tenant_code']);
        $home = $this->home();
        try {
            $this->emProvider->switchTenant((string) $tenant['dbname'], (string) $copy['target_tenant_code']);
            $view = $this->imports->cancel((int) $copy['target_job_id'], $by->getUserIdentifier());
            $this->copies->cancel($copyId);
        } catch (ImportException $e) {
            throw new GroupException($e->errorCode, $e->status, $e->getMessage(), $e->extra);
        } finally {
            $this->restore($home);
        }

        return $this->present($this->copies->find($copyId), $view);
    }

    /** @return array<string, mixed> */
    public function show(User $by, int $copyId): array
    {
        $group = $this->directedGroup($by);
        $copy = $this->ownCopy($group, $copyId);
        $tenant = $this->tenantRow((string) $copy['target_tenant_code']);
        $home = $this->home();
        try {
            $this->emProvider->switchTenant((string) $tenant['dbname'], (string) $copy['target_tenant_code']);
            $view = $this->imports->show((int) $copy['target_job_id']);
        } catch (ImportException) {
            $view = null;
        } finally {
            $this->restore($home);
        }

        return $this->present($copy, $view);
    }

    /** @return list<array<string, mixed>> les copies du groupe, sans relire chaque cible */
    public function list(User $by): array
    {
        $group = $this->directedGroup($by);

        return array_map(fn (array $c): array => $this->present($c, null), $this->copies->ofGroup((int) $group['id']));
    }

    /**
     * Worker, dans la CIBLE : après l'exécution d'une tâche de source `group`, la correspondance article et variante source → copie est
     * écrite dans la base maître. Les références de l'import portent la source : `<site>-<article>` et `#<rang de la variante>`.
     */
    public function recordLinks(int $jobId): int
    {
        $copy = $this->copies->byJob((string) $this->tenants->getTenantCode(), $jobId);
        if ($copy === null) {
            return 0;
        }
        $sourceVariants = [];
        foreach ($this->imports->groupProducts($jobId) as $product) {
            $sourceVariants[mb_strtolower((string) $product['handle'])] = $product['sourceVariantIds'] ?? [];
        }
        $prefix = mb_strtolower((string) $copy['source_tenant_code']) . '-';
        $products = [];
        foreach ($this->jobs->itemsOf($jobId, ImportJobStore::ITEM_PRODUCT) as $row) {
            $products[(string) $row['ref']] = (int) $row['entity_id'];
        }
        $links = [];
        foreach ($products as $ref => $targetProduct) {
            if (!str_starts_with($ref, $prefix) || !ctype_digit(substr($ref, strlen($prefix)))) {
                continue;
            }
            $links[] = ['sourceProduct' => (int) substr($ref, strlen($prefix)), 'sourceVariant' => null, 'targetProduct' => $targetProduct, 'targetVariant' => null];
        }
        foreach ($this->jobs->itemsOf($jobId, ImportJobStore::ITEM_VARIANT) as $row) {
            [$productRef, $rank] = array_pad(explode('#', (string) $row['ref'], 2), 2, null);
            if (!isset($products[$productRef]) || $rank === null || !ctype_digit($rank) || !str_starts_with($productRef, $prefix)) {
                continue;
            }
            $links[] = ['sourceProduct' => (int) substr($productRef, strlen($prefix)), 'sourceVariant' => isset($sourceVariants[$productRef][(int) $rank]) ? (int) $sourceVariants[$productRef][(int) $rank] : null,
                'targetProduct' => $products[$productRef], 'targetVariant' => (int) $row['entity_id']];
        }
        return $this->copies->addLinks($copy, $links);
    }

    /**
     * Le catalogue de la source, lu dans une transaction READ ONLY, sous la forme d'une lecture de fichier Shopify (`ShopifyProductCsv`).
     *
     * @param array<string, mixed> $shop
     * @param list<int>|null $articles
     * @return array{0: list<array<string, mixed>>, 1: string, 2: bool} produits, devise, prix taxes comprises
     */
    private function readSource(array $shop, string $code, ?array $articles): array
    {
        $this->emProvider->switchTenant((string) $shop['dbname'], $code);
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement('SET TRANSACTION READ ONLY');
            $currency = $this->currency->code(true);
            $inclusive = $this->taxes->pricesIncludeTax();
            $criteria = $articles === null ? [] : ['id' => $articles];
            $products = [];
            $line = 1;
            foreach ($em->getRepository(Product::class)->findBy($criteria, ['id' => 'ASC'], CatalogImportService::GROUP_MAX_PRODUCTS + 1) as $product) {
                $class = $product instanceof \Doctrine\Persistence\Proxy ? get_parent_class($product) : $product::class;
                if ($class !== Product::class) {
                    continue; // une fiche d'un autre type (véhicule, location…) n'est pas un article du catalogue copiable
                }
                $products[] = $this->export($product, $code, ++$line);
            }
            if (count($products) > CatalogImportService::GROUP_MAX_PRODUCTS) {
                throw new GroupException('COPY_TOO_MANY', 422, sprintf('Au plus %d articles par copie : choisissez des articles.', CatalogImportService::GROUP_MAX_PRODUCTS));
            }

            return [$products, $currency, $inclusive];
        } finally {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $em->clear();
        }
    }

    /** @return array<string, mixed> un article, sous la forme d'une lecture Shopify ; ses variantes en gardent l'identifiant source */
    private function export(Product $product, string $code, int $line): array
    {
        $variants = array_values(array_filter($product->getVariants()->toArray(), static fn ($v) => $v instanceof ProductVariant));
        usort($variants, static fn (ProductVariant $a, ProductVariant $b): int => (int) $a->getId() <=> (int) $b->getId());
        // positions des options : taille, couleur, puis la première autre option (trois au plus, comme un fichier Shopify)
        $hasSize = array_filter($variants, static fn (ProductVariant $v) => $v->getSize() !== null) !== [];
        $hasColor = array_filter($variants, static fn (ProductVariant $v) => $v->getColor() !== null) !== [];
        $otherName = null;
        foreach ($variants as $v) {
            foreach ($v->getOptionValues() as $value) {
                $otherName ??= $value->getProductOption()?->getName();
            }
        }
        $names = array_values(array_filter([$hasSize ? 'Taille' : null, $hasColor ? 'Couleur' : null, $otherName]));
        $names = array_pad(array_slice($names, 0, 3), 3, '');
        $cents = static fn (int|float|null $c): string => $c === null ? '' : sprintf('%.2f', $c / 100);
        $rows = [];
        foreach ($variants as $i => $v) {
            $values = [];
            foreach ($names as $name) {
                $values[] = match (true) {
                    $name === 'Taille' => (string) $v->getSize()?->getName(),
                    $name === 'Couleur' => (string) $v->getColor()?->getName(),
                    $name !== '' => (string) (array_values(array_filter(array_map(static fn ($val) => $val->getProductOption()?->getName() === $name ? $val->getValue() : null, $v->getOptionValues()->toArray())))[0] ?? ''),
                    default => '',
                };
            }
            $rows[] = ['line' => $line, 'options' => $values, 'sku' => $i === 0 ? (string) $product->getCode() : '', 'priceRaw' => $cents($v->getPrice() ?? $product->getPrice()),
                'compareAtRaw' => '', 'costRaw' => $i === 0 ? $cents($product->getPurchasePrice()) : '', 'qtyRaw' => '0', 'barcode' => (string) $v->getBarcode(), 'image' => ''];
        }
        $images = [];
        foreach (array_merge([(string) $product->getImage()], array_map(static fn ($p) => (string) $p->getImageUrl(), $product->getPictures()->toArray())) as $file) {
            $ref = ImportImageFetcher::LOCAL_PREFIX . basename($file);
            if ($file !== '' && ImportImageFetcher::allowedLocal($ref) && !in_array($ref, $images, true) && count($images) < self::MAX_IMAGES) {
                $images[] = $ref;
            }
        }
        $category = $product->getCategory()->first();

        return [
            'handle' => $code . '-' . $product->getId(), 'line' => $line, 'title' => (string) $product->getName(), 'body' => (string) $product->getDescription(), 'vendor' => '',
            'type' => $category ? (string) $category->getName() : '', 'category' => '', 'tags' => (string) $product->getTags(), 'published' => (bool) $product->isWeb(), 'status' => 'active',
            'optionNames' => $names, 'variants' => $rows, 'images' => $images,
            'sourceVariantIds' => array_map(static fn (ProductVariant $v): int => (int) $v->getId(), $variants),
        ];
    }

    /** @return array<string, mixed> le groupe que dirige ce compte sur le site courant (le siège) */
    private function directedGroup(User $user): array
    {
        $current = (string) $this->tenants->getTenantCode();
        $group = $current === '' ? null : $this->groups->ofSite($current);
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true) || PlatformAccessJournal::isPlatformAccount($user->getRoles())
            || $group === null || $group['head_tenant_code'] !== $current || !$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
            throw new GroupException('GROUP_COPY_FORBIDDEN', 403, 'Copie du catalogue : réservée au directeur du groupe, sur le site du siège.');
        }

        return $group;
    }

    /** @return array<string, mixed> */
    private function ownCopy(array $group, int $copyId): array
    {
        $copy = $this->copies->find($copyId);
        if ($copy === null || $copy['group_id'] !== (int) $group['id']) {
            throw new GroupException('COPY_NOT_FOUND', 404, 'Copie introuvable.');
        }

        return $copy;
    }

    /** @return array<string, array<string, mixed>> les boutiques ouvertes du groupe, par code */
    private function shops(int $groupId): array
    {
        $shops = [];
        foreach ($this->groups->shopDatabases($groupId) as $row) {
            if (($row['dbname'] ?? '') !== '') {
                $shops[(string) $row['tenant_code']] = $row;
            }
        }

        return $shops;
    }

    /** @return array<string, mixed> */
    private function tenantRow(string $code): array
    {
        foreach ($this->groups->shopDatabases((int) ($this->groups->ofSite((string) $this->tenants->getTenantCode())['id'] ?? 0)) as $row) {
            if ($row['tenant_code'] === $code && ($row['dbname'] ?? '') !== '') {
                return $row;
            }
        }
        throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est plus dans votre groupe.');
    }

    /** @return array{0: string, 1: ?string} */
    private function home(): array
    {
        return [(string) ($this->emProvider->getEntityManager()->getConnection()->getParams()['dbname'] ?? ''), $this->tenants->getTenantCode()];
    }

    /** @param array{0: string, 1: ?string} $home */
    private function restore(array $home): void
    {
        $this->emProvider->switchTenant($home[0], $home[1]);
    }

    /**
     * @param array<string, mixed>|null $copy
     * @param array<string, mixed>|null $view la tâche d'import de la cible
     * @return array<string, mixed>
     */
    private function present(?array $copy, ?array $view): array
    {
        if ($copy === null) {
            throw new GroupException('COPY_NOT_FOUND', 404, 'Copie introuvable.');
        }

        return [
            'id' => $copy['id'], 'source' => $copy['source_tenant_code'], 'target' => $copy['target_tenant_code'], 'articles' => $copy['articles'],
            'withCost' => $copy['with_cost'], 'createdAt' => $copy['created_at'], 'confirmedAt' => $copy['confirmed_at'], 'cancelledAt' => $copy['cancelled_at'],
            'import' => $view === null ? null : array_intersect_key($view, array_flip(['id', 'status', 'report', 'result', 'error'])),
        ];
    }
}
