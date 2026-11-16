<?php

namespace App\Import;

use App\Import\Message\RunCatalogImportMessage;
use App\Import\Shopify\ShopifyProductCsv;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\OrderService\TaxEngine;
use App\Services\ProductService\ProductUsageGuard;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot IMP-1 (15/11/2026, `docs/import-boutique.md`) : import du catalogue d'une boutique Shopify par son fichier d'export des produits.
 *
 * Un import se dépose, s'analyse sans rien écrire, se confirme, puis s'exécute en tâche de fond ; il peut être annulé pendant
 * `CANCEL_DAYS` jours. États : `uploaded` → `analysed` → `running` → `done` | `failed` ; `cancelled`. Un seul import actif par site.
 * Chaque refus porte un code stable (`ImportException`), jamais un message technique.
 */
class CatalogImportService
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const CANCEL_DAYS = 7;
    /** Un import resté « en cours » plus longtemps est tenu pour interrompu : il peut être annulé, le site n'est pas bloqué */
    public const STALE_MINUTES = 60;
    public const MAX_IMAGES_PER_PRODUCT = 10;
    public const VISIBILITIES = ['as_source', 'hidden'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly ImportJobStore $store,
        private readonly CatalogImportPlanner $planner,
        private readonly CatalogImportWriter $writer,
        private readonly ImportImageFetcher $images,
        private readonly TaxEngine $taxes,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly EmailLocale $siteLocale,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly TenantCacheService $cache,
        private readonly ContentAuditRecorder $audit,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ?\App\Security\Entry\EntryContext $entry = null,
    ) {
    }

    /** Dépose un fichier et l'analyse. @return array<string, mixed> la vue de l'import */
    public function upload(?UploadedFile $file, string $source, ?string $by): array
    {
        if ($source !== ImportJobStore::SOURCE_SHOPIFY) {
            throw new ImportException('IMPORT_SOURCE_UNKNOWN', 422);
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new ImportException('IMPORT_FILE_REQUIRED', 422);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ImportException('IMPORT_FILE_TOO_BIG', 422, ['maxBytes' => self::MAX_BYTES]);
        }
        if ($this->store->activeId() !== null) {
            throw new ImportException('IMPORT_ALREADY_ACTIVE', 409, ['importId' => $this->store->activeId()]);
        }
        $stored = bin2hex(random_bytes(16)) . '.csv';
        (new Filesystem())->mkdir($this->directory(), 0750);
        $original = (string) $file->getClientOriginalName();
        $file->move($this->directory(), $stored);
        $path = $this->directory() . '/' . $stored;
        $parsed = (new ShopifyProductCsv())->read($path);
        if ($parsed['error'] !== null) {
            @unlink($path);
            throw new ImportException('IMPORT_' . $parsed['error'], 422, $parsed['error'] === ShopifyProductCsv::TOO_MANY_ROWS ? ['maxRows' => ShopifyProductCsv::MAX_ROWS] : []);
        }
        try {
            $id = $this->store->create($source, ImportJobStore::KIND_CATALOG, $original !== '' ? $original : 'import.csv', $stored, (string) hash_file('sha256', $path), $parsed['rows'], $by);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            @unlink($path); // deux dépôts au même instant : la base n'en garde qu'un
            throw new ImportException('IMPORT_ALREADY_ACTIVE', 409, ['importId' => $this->store->activeId()]);
        }
        $plan = $this->planner->plan($source, $parsed['products']);
        $report = $this->planner->report($plan, $this->categoryNames()) + ['currency' => $this->currency(), 'sitePricesIncludeTax' => $this->taxes->pricesIncludeTax()];
        $this->store->move($id, ImportJobStore::UPLOADED, ImportJobStore::ANALYSED, ['report' => $report, 'analysed_at' => true]);

        return $this->view($this->job($id));
    }

    /**
     * Confirme un import analysé et le met en file.
     *
     * @param array<string, mixed> $body { visibility?: as_source|hidden, pos?: bool, pricesIncludeTax?: bool }
     */
    public function confirm(int $id, array $body, ?string $by): array
    {
        $job = $this->job($id);
        $errors = [];
        $visibility = $body['visibility'] ?? 'as_source';
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            $errors['visibility'] = 'INVALID_VALUE';
        }
        foreach (['pos', 'pricesIncludeTax'] as $flag) {
            if (array_key_exists($flag, $body) && !is_bool($body[$flag])) {
                $errors[$flag] = 'MUST_BE_BOOLEAN';
            }
        }
        if ($errors !== []) {
            throw new ImportException('VALIDATION_FAILED', 422, ['fields' => $errors]);
        }
        $siteInclusive = $this->taxes->pricesIncludeTax();
        $fileInclusive = (bool) ($body['pricesIncludeTax'] ?? $siteInclusive);
        $options = ['visibility' => $visibility, 'pos' => (bool) ($body['pos'] ?? true), 'pricesIncludeTax' => $fileInclusive, 'sitePricesIncludeTax' => $siteInclusive,
            'priceFactor' => $this->priceFactor($fileInclusive, $siteInclusive),
            // lot AG-3b : confirmé par une agence entrée dans le site, l'import n'écrit pas le coût d'achat (il donne la marge du client)
            'withoutCost' => $this->entry?->active() ?? false];
        if (($job['report']['products']['importable'] ?? 0) < 1) {
            throw new ImportException('IMPORT_NOTHING_TO_IMPORT', 409);
        }
        if ($this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM movement_type WHERE name = ?', [CatalogImportWriter::MOVEMENT_TYPE]) === false) {
            throw new ImportException('IMPORT_NOT_AVAILABLE', 409); // le type de mouvement « Import » manque : rien ne pourrait être écrit
        }
        if (!$this->store->move($id, ImportJobStore::ANALYSED, ImportJobStore::RUNNING, ['options' => $options, 'started_at' => true])) {
            throw new ImportException('IMPORT_NOT_READY', 409, ['status' => $this->job($id)['status']]);
        }
        try {
            $this->bus->dispatch(new RunCatalogImportMessage($id, (string) $this->tenants->getCurrentTenantCode(), $by));
        } catch (\Throwable $e) {
            // la file est indisponible : l'import revient à « analysé », le gérant pourra confirmer de nouveau
            $this->logger->error(sprintf('[Import] import %d non mis en file (%s)', $id, $e::class));
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::ANALYSED);
            throw new ImportException('IMPORT_QUEUE_UNAVAILABLE', 503);
        }

        return $this->view($this->job($id));
    }

    /** Exécute un import confirmé sur le site courant (worker). Un article en échec n'arrête pas les autres. */
    public function run(int $id, ?string $by): void
    {
        $job = $this->store->find($id);
        if ($job === null || $job['status'] !== ImportJobStore::RUNNING) {
            return;
        }
        $result = ['created' => 0, 'variants' => 0, 'stockUnits' => 0, 'barcodes' => 0, 'categoriesCreated' => 0, 'imagesStored' => 0, 'imagesFailed' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => []];
        try {
            $parsed = (new ShopifyProductCsv())->read($this->directory() . '/' . $job['stored_name']);
            if ($parsed['error'] !== null) {
                throw new \RuntimeException('fichier illisible : ' . $parsed['error']);
            }
            $options = (array) $job['options'];
            $language = $this->siteLocale->language(null);
            $done = 0;
            foreach ($this->planner->plan($job['source'], $parsed['products'])['items'] as $item) {
                if ($item['status'] !== 'ok') {
                    $item['status'] === 'already' ? ++$result['skipped'] : null;
                    continue;
                }
                try {
                    $written = $this->writer->write($id, $job['source'], $item, ['visibility' => (string) $options['visibility'], 'pos' => (bool) $options['pos'], 'withoutCost' => (bool) ($options['withoutCost'] ?? false)], (float) ($options['priceFactor'] ?? 1.0), $language, $by);
                } catch (\Throwable $e) {
                    ++$result['failed'];
                    if (count($result['failures']) < 200) {
                        $result['failures'][] = ['line' => $item['line'], 'handle' => $item['handle'], 'code' => 'WRITE_FAILED'];
                    }
                    $this->logger->error(sprintf('[Import] import %d, article « %s » non écrit (%s)', $id, $item['handle'], $e::class), ['exception' => $e]);
                    if (!$this->emProvider->getEntityManager()->isOpen()) {
                        throw new \RuntimeException('connexion fermée après une écriture en échec', 0, $e);
                    }
                    $this->emProvider->getEntityManager()->clear(); // ce que cet article avait préparé ne doit pas partir avec le suivant
                    continue;
                }
                ++$result['created'];
                $result['variants'] += $written['variants'];
                $result['stockUnits'] += $written['stock'];
                $result['barcodes'] += $written['barcodes'];
                $result['categoriesCreated'] += $written['categoryCreated'] ? 1 : 0;
                foreach (array_slice($item['images'], 0, self::MAX_IMAGES_PER_PRODUCT) as $url) {
                    $fetched = $this->images->fetch($url);
                    if ($fetched['file'] === null) {
                        ++$result['imagesFailed'];
                        if (count($result['failures']) < 200) {
                            $result['failures'][] = ['line' => $item['line'], 'handle' => $item['handle'], 'code' => (string) $fetched['error']];
                        }
                        continue;
                    }
                    $this->writer->addPicture($id, $job['source'], $written['productId'], $fetched['file']);
                    ++$result['imagesStored'];
                }
                $this->emProvider->getEntityManager()->clear();
                if (++$done % 20 === 0) {
                    $this->store->saveResult($id, $result); // l'avancement se lit pendant l'exécution
                }
            }
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::DONE, ['result' => $result, 'finished_at' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] import %d arrêté (%s)', $id, $e::class), ['exception' => $e]);
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::FAILED, ['result' => $result, 'error' => 'IMPORT_INTERRUPTED', 'finished_at' => true]);
        }
    }

    /**
     * Annule un import : abandonné s'il n'a pas commencé ; s'il est terminé depuis moins de `CANCEL_DAYS` jours (ou resté « en cours »
     * depuis plus de `STALE_MINUTES` minutes, donc interrompu), les articles qu'il a créés et que rien n'utilise sont retirés avec
     * leurs images, et les catégories qu'il a créées et qui restent vides aussi. Un article vendu, mouvementé à la main, commandé à un
     * fournisseur, réservé, abonné ou dans un paiement en cours est GARDÉ et listé (`SOLD`, `IN_USE`).
     *
     * Le retrait se fait en SQL, article par article, chacun dans sa transaction : jamais par l'ORM, dont un rappel de la variante
     * réenregistrerait l'article qu'on supprime.
     */
    public function cancel(int $id, ?string $by): array
    {
        $job = $this->job($id);
        if (in_array($job['status'], [ImportJobStore::UPLOADED, ImportJobStore::ANALYSED], true)) {
            if ($this->store->move($id, $job['status'], ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
                @unlink($this->directory() . '/' . $job['stored_name']);
            }

            return $this->view($this->job($id));
        }
        $utc = new \DateTimeZone('UTC');
        $stale = $job['status'] === ImportJobStore::RUNNING && $job['started_at'] !== null
            && new \DateTimeImmutable((string) $job['started_at'], $utc) < new \DateTimeImmutable('-' . self::STALE_MINUTES . ' minutes', $utc);
        if (!$stale && !in_array($job['status'], [ImportJobStore::DONE, ImportJobStore::FAILED], true)) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $job['status']]);
        }
        if (!$stale && $job['finished_at'] !== null && new \DateTimeImmutable((string) $job['finished_at'], $utc) < new \DateTimeImmutable('-' . self::CANCEL_DAYS . ' days', $utc)) {
            throw new ImportException('IMPORT_CANCEL_WINDOW_PASSED', 409, ['days' => self::CANCEL_DAYS]);
        }
        // l'import quitte d'abord son état : une seconde demande, ou une exécution qui reprendrait, ne trouve plus rien à faire
        if (!$this->store->move($id, $job['status'], ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $importType = (int) $conn->fetchOne('SELECT id FROM movement_type WHERE name = ?', [CatalogImportWriter::MOVEMENT_TYPE]);
        $removed = 0;
        $kept = [];
        $pictures = [];
        foreach ($this->store->itemsOf($id, ImportJobStore::ITEM_PICTURE) as $row) {
            $pictures[(int) $row['entity_id']][] = (string) $row['ref'];
        }
        foreach ($this->store->itemsOf($id, ImportJobStore::ITEM_PRODUCT) as $row) {
            $productId = (int) $row['entity_id'];
            $name = $conn->fetchOne('SELECT name FROM product WHERE id = ?', [$productId]);
            if ($name === false) {
                continue; // déjà supprimé par le gérant
            }
            $variantIds = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM product_variant WHERE product_id = ?', [$productId]));
            $uses = ProductUsageGuard::productUses($conn, $productId);
            // les mouvements « Import » sont ceux de l'import lui-même ; tout autre mouvement est une histoire à garder
            $uses['stockMovements'] = $variantIds === [] ? 0 : (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM inventory_movements WHERE product_variant_id IN (' . implode(',', $variantIds) . ') AND (movement_type_id IS NULL OR movement_type_id <> ?)', [$importType]);
            if (array_sum(array_map('intval', $uses)) > 0) {
                $kept[] = ['productId' => $productId, 'name' => (string) $name, 'reason' => (int) ($uses['sales'] ?? 0) > 0 ? 'SOLD' : 'IN_USE'];
                continue;
            }
            $conn->beginTransaction();
            try {
                if ($variantIds !== []) {
                    $in = implode(',', $variantIds);
                    $conn->executeStatement("DELETE FROM inventory_movements WHERE product_variant_id IN ($in) AND movement_type_id = ?", [$importType]);
                    $conn->executeStatement("DELETE FROM product_variant WHERE id IN ($in)");
                }
                foreach (['product_picture', 'product_translation', 'related_product'] as $table) {
                    $conn->executeStatement("DELETE FROM $table WHERE product_id = ?", [$productId]);
                }
                $conn->executeStatement('DELETE FROM product WHERE id = ?', [$productId]);
                $this->store->forgetProduct($id, $productId, $variantIds);
                $conn->commit();
                ++$removed;
                foreach ($pictures[$productId] ?? [] as $file) {
                    $this->images->remove($file);
                }
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                // autre chose tient l'article (une clé étrangère) : il reste
                $this->logger->warning(sprintf('[Import] annulation de l\'import %d : article %d gardé (%s)', $id, $productId, $e::class));
                $kept[] = ['productId' => $productId, 'name' => (string) $name, 'reason' => 'IN_USE'];
            }
        }
        $categoriesRemoved = 0;
        foreach ($this->store->itemsOf($id, ImportJobStore::ITEM_CATEGORY) as $row) {
            $categoryId = (int) $row['entity_id'];
            if ((int) $conn->fetchOne('SELECT COUNT(*) FROM product_categories WHERE categories_id = ?', [$categoryId]) > 0) {
                continue;
            }
            $conn->beginTransaction();
            try {
                $conn->executeStatement('DELETE FROM categories_translation WHERE category_id = ?', [$categoryId]);
                if ($conn->executeStatement('DELETE FROM categories WHERE id = ?', [$categoryId]) === 1) {
                    ++$categoriesRemoved;
                }
                $this->store->forgetCategory($id, $categoryId);
                $conn->commit();
            } catch (\Throwable) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack(); // une catégorie tenue par autre chose reste
                }
            }
        }
        $em->clear(); // les objets en mémoire ne reflètent plus la base
        $this->cache->invalidateTenant(); // écritures en SQL : le cache du site n'est pas vidé par Doctrine
        @unlink($this->directory() . '/' . $job['stored_name']);
        $result = (array) $job['result'];
        $result['cancel'] = ['removed' => $removed, 'kept' => $kept, 'categoriesRemoved' => $categoriesRemoved, 'by' => $by];
        $this->store->saveResult($id, $result);
        $this->audit->record('import_job', $id, 'cancel', [], ['removed' => $removed, 'kept' => count($kept), 'categoriesRemoved' => $categoriesRemoved, 'by' => $by], ['status']);

        return $this->view($this->job($id));
    }

    /** @return list<array<string, mixed>> */
    public function latest(): array
    {
        return array_map(fn (array $job): array => $this->view($job, false), $this->store->latest());
    }

    public function show(int $id): array
    {
        return $this->view($this->job($id));
    }

    /** @param array<string, mixed> $job @return array<string, mixed> la vue courte d'un import de catalogue, pour la liste (lot IMP-2 : la liste mêle les natures) */
    public function summary(array $job): array
    {
        return $this->view($job, false);
    }

    /**
     * Le fichier des lignes signalées (refusées, codes-barres écartés, images refusées), pour un tableur. Une cellule qui commence par
     * = + - @ (ou une tabulation, un retour) reçoit une apostrophe devant : un tableur ne l'exécute pas comme une formule.
     */
    public function issuesCsv(int $id): string
    {
        $job = $this->job($id);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', 'handle', 'code', 'detail'], ';', '"', '');
        foreach (array_merge((array) ($job['report']['issues'] ?? []), (array) ($job['result']['failures'] ?? [])) as $issue) {
            fputcsv($out, array_map(self::safeCell(...), [(string) ($issue['line'] ?? ''), (string) ($issue['handle'] ?? ''), (string) ($issue['code'] ?? ''), (string) ($issue['detail'] ?? '')]), ';', '"', '');
        }
        rewind($out);

        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /** @return array<string, mixed> */
    private function job(int $id): array
    {
        return $this->store->find($id) ?? throw new ImportException('IMPORT_NOT_FOUND', 404);
    }

    /** @param array<string, mixed> $job */
    private function view(array $job, bool $full = true): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $finished = $job['finished_at'] !== null ? new \DateTimeImmutable((string) $job['finished_at'], new \DateTimeZone('UTC')) : null;
        $started = $job['started_at'] !== null ? new \DateTimeImmutable((string) $job['started_at'], new \DateTimeZone('UTC')) : null;
        $cancellable = in_array($job['status'], [ImportJobStore::UPLOADED, ImportJobStore::ANALYSED], true)
            || (in_array($job['status'], [ImportJobStore::DONE, ImportJobStore::FAILED], true) && $finished !== null && $finished >= new \DateTimeImmutable('-' . self::CANCEL_DAYS . ' days'))
            || ($job['status'] === ImportJobStore::RUNNING && $started !== null && $started < new \DateTimeImmutable('-' . self::STALE_MINUTES . ' minutes'));
        $report = (array) ($job['report'] ?? []);
        $view = [
            'id' => $job['id'], 'source' => $job['source'], 'kind' => $job['kind'], 'status' => $job['status'], 'fileName' => $job['file_name'], 'rows' => $job['row_count'],
            'createdAt' => $iso($job['created_at']), 'analysedAt' => $iso($job['analysed_at']), 'startedAt' => $iso($job['started_at']), 'finishedAt' => $iso($job['finished_at']), 'cancelledAt' => $iso($job['cancelled_at']),
            'cancellable' => $cancellable, 'cancellableUntil' => $finished !== null && $cancellable ? $finished->modify('+' . self::CANCEL_DAYS . ' days')->format(\DateTimeInterface::ATOM) : null,
            'error' => $job['error'], 'options' => $job['options'],
        ];
        if ($full) {
            $issues = (array) ($report['issues'] ?? []);
            $view['report'] = $report === [] ? null : ['issues' => array_slice($issues, 0, 100), 'issuesTotal' => count($issues)] + $report;
            $view['result'] = $job['result'];
        } else {
            $view['summary'] = $report === [] ? null : ['products' => $report['products'] ?? null, 'variants' => $report['variants'] ?? null];
        }

        return $view;
    }

    /**
     * Le facteur qui amène un prix du fichier au mode du site. Même mode : 1. Sinon le taux des taxes de l'adresse du magasin,
     * lu une fois dans la table des taxes du site : hors taxes = taxes comprises / (1 + taux), et l'inverse.
     */
    private function priceFactor(bool $fileInclusive, bool $siteInclusive): float
    {
        if ($fileInclusive === $siteInclusive) {
            return 1.0;
        }
        $raw = $this->taxAddress->forStore();
        $address = $raw ? TaxEngine::normalizeAddress($raw) : null;
        $probe = 100_000_000;
        $computed = $address === null ? null : $this->taxes->compute($address, [['amount' => $probe, 'quantity' => 1, 'kind' => 'product', 'reference' => 'import']], 0, $this->currency(), true);
        $tax = (int) ($computed['total'] ?? 0);
        if ($computed === null || $tax <= 0 || $tax >= $probe) {
            throw new ImportException('IMPORT_TAX_RATE_UNKNOWN', 422);
        }
        $rate = $tax / ($probe - $tax);

        return $fileInclusive ? 1 / (1 + $rate) : 1 + $rate;
    }

    /** @return list<string> */
    private function categoryNames(): array
    {
        return array_map('mb_strtolower', array_map('strval', $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn('SELECT name FROM categories')));
    }

    private function currency(): string
    {
        try {
            return (string) ($this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT currency FROM entreprise ORDER BY id LIMIT 1') ?: 'CAD');
        } catch (\Throwable) {
            return 'CAD';
        }
    }

    private function directory(): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenants->getCurrentTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/imports/' . $code;
    }
}
