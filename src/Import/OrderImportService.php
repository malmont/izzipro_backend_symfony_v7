<?php

namespace App\Import;

use App\Import\Message\RunOrderImportMessage;
use App\Import\Shopify\ShopifyOrderCsv;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot IMP-3 (16/11/2026, `docs/import-boutique.md` § 10) : historique des COMMANDES d'une boutique existante, en ARCHIVE À PART.
 *
 * Même circuit que le catalogue et les clients : le fichier se dépose, s'analyse sans rien écrire, se confirme, puis s'exécute en
 * tâche de fond ; rejoué, il ne crée pas de doublon (le numéro de commande d'origine est unique par source) ; il s'annule.
 *
 * Les commandes vont dans deux tables neuves (`import_order`, `import_order_line`), JAMAIS dans les commandes du site : rien n'entre
 * dans le journal de caisse, la numérotation des factures, les taxes, les exports comptables, le stock, la fidélité ni le résultat des
 * périodes. Aucun courriel n'est envoyé. Elles ne se lisent que par `OrderArchive` (série « ventes d'avant », comparaison « an
 * dernier » marquée « avant la migration », fiche d'un client côté administration).
 *
 * Données personnelles : le courriel du fichier ne sert qu'à retrouver le compte du site (client, ou invité repris par IMP-2) ; il
 * n'est écrit nulle part, pas plus que les adresses, le téléphone, les notes ou le moyen de paiement, qui ne sont pas lus. Le rapport
 * ne garde que des numéros de ligne et des raisons. Le fichier est supprimé du serveur dès la fin de l'exécution.
 *
 * Ce qui est repris : les `MONTHS` derniers mois avant le dépôt ; une commande dans une autre devise que celle du site est refusée.
 * Seules les commandes payées entrent dans la série ; remboursées, annulées ou impayées, elles sont gardées, marquées, hors de la série.
 */
class OrderImportService
{
    public const MAX_BYTES = CatalogImportService::MAX_BYTES;
    /** Profondeur de l'historique repris, en mois avant le dépôt (décision du propriétaire, 15/11/2026) */
    public const MONTHS = 24;
    /** Un import resté « en cours » plus longtemps est tenu pour interrompu : il peut être annulé, le site n'est pas bloqué */
    public const STALE_MINUTES = 60;
    /** Lignes gardées par commande (au-delà, la commande est reprise avec ses premières lignes ; son montant vient de son total) */
    public const MAX_LINES = 200;
    public const PAID = 'paid';
    public const REFUNDED = 'refunded';
    public const CANCELLED = 'cancelled';
    public const UNPAID = 'unpaid';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly ImportJobStore $store,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ContentAuditRecorder $audit,
        private readonly StoreClock $clock,
        private readonly TenantCurrencyProvider $currency,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** Dépose le fichier des commandes et l'analyse, sans rien écrire. @return array<string, mixed> la vue de l'import */
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
        $parsed = (new ShopifyOrderCsv())->read($path);
        if ($parsed['error'] !== null) {
            @unlink($path);
            throw new ImportException('IMPORT_' . $parsed['error'], 422, $parsed['error'] === ShopifyOrderCsv::TOO_MANY_ROWS ? ['maxRows' => ShopifyOrderCsv::MAX_ROWS] : []);
        }
        try {
            $report = $this->report($this->plan($parsed['orders'], (string) $source));
            $id = $this->store->create($source, ImportJobStore::KIND_ORDERS, $original !== '' ? $original : 'commandes.csv', $stored, (string) hash_file('sha256', $path), $parsed['rows'], $by);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            @unlink($path); // deux dépôts au même instant : la base n'en garde qu'un
            throw new ImportException('IMPORT_ALREADY_ACTIVE', 409, ['importId' => $this->store->activeId()]);
        } catch (\Throwable $e) {
            @unlink($path); // aucun import n'a été créé : le fichier ne reste pas sur le serveur
            throw $e;
        }
        $this->store->move($id, ImportJobStore::UPLOADED, ImportJobStore::ANALYSED, ['report' => $report, 'analysed_at' => true]);

        return $this->view($this->job($id));
    }

    /** Confirme l'import analysé et le met en file. Aucune option. @param array<string, mixed> $body */
    public function confirm(int $id, array $body, ?string $by): array
    {
        $job = $this->ordersJob($id);
        if (($job['report']['orders']['importable'] ?? 0) < 1) {
            throw new ImportException('IMPORT_NOTHING_TO_IMPORT', 409);
        }
        if (!$this->store->move($id, ImportJobStore::ANALYSED, ImportJobStore::RUNNING, ['options' => [], 'started_at' => true])) {
            throw new ImportException('IMPORT_NOT_READY', 409, ['status' => $this->job($id)['status']]);
        }
        try {
            $this->bus->dispatch(new RunOrderImportMessage($id, (string) $this->tenants->getCurrentTenantCode(), $by));
        } catch (\Throwable $e) {
            // la file est indisponible : l'import revient à « analysé », le gérant pourra confirmer de nouveau
            $this->logger->error(sprintf('[Import] import de commandes %d non mis en file (%s)', $id, $e::class));
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::ANALYSED);
            throw new ImportException('IMPORT_QUEUE_UNAVAILABLE', 503);
        }

        return $this->view($this->job($id));
    }

    /**
     * Exécute un import de commandes confirmé sur le site courant (worker). Une commande, une transaction : une commande déjà en
     * archive (reprise entre-temps) est passée ; une écriture en échec est comptée, et si elle a fermé la connexion l'import s'arrête
     * (`IMPORT_INTERRUPTED`) : déposer de nouveau le fichier reprend où il s'est arrêté, sans doublon. Le fichier est supprimé du
     * serveur à la fin, quelle que soit l'issue. Un import annulé pendant qu'il tourne s'arrête au contrôle suivant.
     */
    public function run(int $id, ?string $by): void
    {
        $job = $this->store->find($id);
        if ($job === null || $job['status'] !== ImportJobStore::RUNNING || $job['kind'] !== ImportJobStore::KIND_ORDERS) {
            return;
        }
        $result = ['created' => 0, 'paid' => 0, 'linkedToAccount' => 0, 'lines' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => []];
        $path = $this->directory() . '/' . $job['stored_name'];
        try {
            $parsed = (new ShopifyOrderCsv())->read($path);
            if ($parsed['error'] !== null) {
                throw new \RuntimeException('fichier illisible : ' . $parsed['error']);
            }
            $conn = $this->conn();
            $done = 0;
            // le plan est refait : le site a pu changer depuis l'analyse. La profondeur de l'historique se compte depuis le DÉPÔT
            foreach ($this->plan($parsed['orders'], (string) $job['source'], new \DateTimeImmutable((string) $job['created_at'], new \DateTimeZone('UTC'))) as $item) {
                if ($item['status'] !== 'ok') {
                    $item['status'] === 'already' ? ++$result['skipped'] : null;
                    continue;
                }
                $conn->beginTransaction();
                try {
                    $orderId = (int) $conn->fetchOne(
                        'INSERT INTO import_order (job_id, source, ref, ordered_at, day, financial_status, state, total_cents, tax_cents, shipping_cents, refunded_cents, net_cents, currency, user_id, items_count)'
                        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                        [$id, (string) $job['source'], $item['ref'], $item['orderedAt'], $item['day'], $item['financial'], $item['state'], $item['total'], $item['taxes'], $item['shipping'], $item['refunded'],
                            $item['net'], $item['currency'], $item['userId'], $item['items']]
                    );
                    foreach ($item['lines'] as $position => $line) {
                        $conn->executeStatement('INSERT INTO import_order_line (order_id, position, name, quantity, unit_price_cents, product_id) VALUES (?, ?, ?, ?, ?, ?)',
                            [$orderId, $position + 1, $line['name'], $line['quantity'], $line['price'], $line['productId']]);
                    }
                    $conn->commit();
                } catch (\Throwable $e) {
                    if ($conn->isTransactionActive()) {
                        $conn->rollBack();
                    }
                    if ($e instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                        ++$result['skipped']; // la commande a été reprise entre l'analyse et l'écriture : elle n'est pas doublée
                    } else {
                        ++$result['failed'];
                        if (count($result['failures']) < 200) {
                            $result['failures'][] = ['line' => $item['line'], 'code' => 'WRITE_FAILED'];
                        }
                        $this->logger->error(sprintf('[Import] import de commandes %d, ligne %d non écrite (%s)', $id, $item['line'], $e::class));
                    }
                    if (!$conn->isConnected()) {
                        throw new \RuntimeException('connexion fermée après une écriture en échec (' . $e::class . ')');
                    }
                    continue;
                }
                ++$result['created'];
                $result['paid'] += $item['state'] === self::PAID ? 1 : 0;
                $result['linkedToAccount'] += $item['userId'] !== null ? 1 : 0;
                $result['lines'] += count($item['lines']);
                if (++$done % 50 === 0) {
                    $this->store->saveResult($id, $result); // l'avancement se lit pendant l'exécution
                    if (($this->store->find($id)['status'] ?? null) !== ImportJobStore::RUNNING) {
                        return; // annulé entre-temps : on n'écrit plus rien sous un import annulé (ce qu'il a écrit est retiré par l'annulation)
                    }
                }
            }
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::DONE, ['result' => $result, 'finished_at' => true]);
            $this->journal($id, 'run', ['kind' => ImportJobStore::KIND_ORDERS, 'by' => $by, 'created' => $result['created'], 'paid' => $result['paid']], ['status']);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] import de commandes %d arrêté (%s)', $id, $e::class));
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::FAILED, ['result' => $result, 'error' => 'IMPORT_INTERRUPTED', 'finished_at' => true]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Annule un import de commandes : abandonné s'il n'a pas commencé (le fichier est supprimé) ; sinon les commandes d'archive qu'il
     * a reprises sont retirées (rien d'autre ne dépend d'elles). Possible à tout moment tant qu'il en reste ; un import encore « en
     * cours » ne s'annule que s'il est tenu pour interrompu.
     */
    public function cancel(int $id, ?string $by): array
    {
        $job = $this->ordersJob($id);
        if (in_array($job['status'], [ImportJobStore::UPLOADED, ImportJobStore::ANALYSED], true)) {
            if (!$this->store->move($id, (string) $job['status'], ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
                throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
            }
            @unlink($this->directory() . '/' . $job['stored_name']);

            return $this->view($this->job($id));
        }
        if (!$this->cancellable($job)) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $job['status']]);
        }
        // un import encore « en cours » quitte d'abord cet état : son worker, s'il vit encore, s'arrête au contrôle suivant
        if ($job['status'] === ImportJobStore::RUNNING && !$this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::FAILED, ['error' => 'IMPORT_INTERRUPTED', 'finished_at' => true])) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
        }
        @unlink($this->directory() . '/' . $job['stored_name']); // un import interrompu a pu laisser son fichier
        // les commandes sont retirées AVANT que l'import soit dit « annulé » : si le retrait s'interrompt, l'annulation se redemande
        $removed = (int) $this->conn()->executeStatement('DELETE FROM import_order WHERE job_id = ?', [$id]); // ses lignes partent avec elle
        $status = (string) $this->job($id)['status'];
        if (!$this->store->move($id, $status, ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
        }
        $result = (array) ($this->store->find($id)['result'] ?? []);
        $result['cancel'] = ['removed' => $removed];
        $this->store->saveResult($id, $result);
        $this->journal($id, 'cancel', ['kind' => ImportJobStore::KIND_ORDERS, 'by' => $by, 'removed' => $removed], ['status']);

        return $this->view($this->job($id));
    }

    public function show(int $id): array
    {
        return $this->view($this->ordersJob($id));
    }

    /** @param array<string, mixed> $job @return array<string, mixed> la vue courte d'un import de commandes, pour la liste */
    public function summary(array $job): array
    {
        return $this->view($job, false);
    }

    /** Le fichier des lignes signalées (numéro de ligne et raison, jamais un courriel ni un nom), pour un tableur */
    public function issuesCsv(int $id): string
    {
        $job = $this->ordersJob($id);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', 'code'], ';', '"', '');
        foreach (array_merge((array) ($job['report']['issues'] ?? []), (array) ($job['result']['failures'] ?? [])) as $issue) {
            fputcsv($out, [(string) ($issue['line'] ?? ''), CatalogImportService::safeCell((string) ($issue['code'] ?? ''))], ';', '"', '');
        }
        rewind($out);

        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /**
     * Le plan d'un fichier de commandes, SANS RIEN ÉCRIRE : pour chaque commande, ce qu'elle deviendrait.
     * `ok` (à reprendre), `already` (ce numéro est déjà en archive), `duplicate` (numéro déjà vu plus haut : ne peut pas arriver après
     * le regroupement du lecteur, gardé par sûreté), `too_old` (plus de `MONTHS` mois avant le dépôt), `refused` (avec sa raison).
     *
     * @param list<array<string, mixed>> $orders les commandes lues par `ShopifyOrderCsv`
     * @return list<array<string, mixed>>
     */
    public function plan(array $orders, string $source, ?\DateTimeImmutable $uploadedAt = null): array
    {
        $uploadedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $oldest = $uploadedAt->modify('-' . self::MONTHS . ' months');
        $siteCurrency = strtoupper($this->currency->code(true));
        $conn = $this->conn();
        $refs = array_values(array_unique(array_filter(array_map(static fn (array $o): string => mb_substr((string) $o['ref'], 0, 80), $orders), static fn (string $r): bool => $r !== '')));
        $known = [];
        foreach (array_chunk($refs, 500) as $chunk) {
            foreach ($conn->fetchFirstColumn('SELECT ref FROM import_order WHERE source = ? AND ref IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', [$source, ...$chunk]) as $ref) {
                $known[(string) $ref] = true;
            }
        }
        $accounts = $this->accountsOf(array_map(static fn (array $o): string => (string) $o['email'], $orders));
        $products = $this->productsOf(array_merge(...array_map(static fn (array $o): array => array_column($o['lines'], 'sku'), $orders ?: [['lines' => []]])));
        $seen = [];
        $plan = [];
        foreach ($orders as $order) {
            $ref = mb_substr((string) $order['ref'], 0, 80);
            $item = ['line' => (int) $order['line'], 'ref' => $ref, 'status' => 'refused', 'code' => null];
            $refuse = static function (string $code) use (&$item, &$plan): void {
                $item['code'] = $code;
                $plan[] = $item;
            };
            if ($ref === '') {
                $refuse('REF_MISSING');
                continue;
            }
            if (isset($seen[$ref])) {
                $item['status'] = 'duplicate';
                $plan[] = $item;
                continue;
            }
            $seen[$ref] = true;
            if (isset($known[$ref])) {
                $item['status'] = 'already';
                $plan[] = $item;
                continue;
            }
            $at = self::date((string) ($order['paidAt'] !== '' ? $order['paidAt'] : $order['createdAt']));
            if ($at === null) {
                $refuse('DATE_INVALID');
                continue;
            }
            if ($at < $oldest) {
                $item['status'] = 'too_old';
                $plan[] = $item;
                continue;
            }
            if ($at > $uploadedAt->modify('+1 day')) {
                $refuse('DATE_INVALID'); // une commande datée dans l'avenir
                continue;
            }
            $currency = strtoupper(trim((string) $order['currency']));
            if ($currency !== $siteCurrency) {
                $refuse($currency === '' ? 'CURRENCY_MISSING' : 'CURRENCY_MISMATCH');
                continue;
            }
            $amounts = [];
            foreach (['total', 'taxes', 'shipping', 'refunded'] as $field) {
                $amounts[$field] = self::cents((string) $order[$field]);
            }
            if ($amounts['total'] === null) {
                $refuse('AMOUNT_INVALID');
                continue;
            }
            foreach (['taxes', 'shipping', 'refunded'] as $field) {
                $amounts[$field] ??= 0; // absent ou illisible : tenu pour nul, le total fait foi
            }
            $financial = mb_strtolower(trim((string) $order['financial']));
            $state = match (true) {
                trim((string) $order['cancelledAt']) !== '', $financial === 'voided' => self::CANCELLED,
                $financial === 'refunded' => self::REFUNDED,
                in_array($financial, ['paid', 'partially_refunded'], true) => self::PAID,
                default => self::UNPAID,
            };
            // le montant qui entre dans la série : hors taxes et hors port, moins ce qui a été remboursé. `total − taxes − port` vaut le
            // hors taxes que les prix d'origine aient contenu les taxes ou non
            $net = max(0, $amounts['total'] - $amounts['taxes'] - $amounts['shipping'] - $amounts['refunded']);
            $lines = [];
            $items = 0;
            foreach (array_slice($order['lines'], 0, self::MAX_LINES) as $line) {
                $quantity = is_numeric($line['quantity']) ? (int) max(0, min(100000, (float) $line['quantity'])) : 1;
                $sku = mb_strtolower(trim((string) $line['sku']));
                $lines[] = ['name' => mb_substr((string) $line['name'], 0, 255), 'quantity' => $quantity, 'price' => self::cents((string) $line['price']) ?? 0,
                    'productId' => $sku !== '' ? ($products[$sku] ?? null) : null];
                $items += $quantity;
            }
            $email = mb_strtolower(trim((string) $order['email']));
            $plan[] = ['status' => 'ok', 'orderedAt' => $at->format('Y-m-d H:i:s'), 'day' => $this->clock->day($at), 'financial' => mb_substr($financial, 0, 30), 'state' => $state,
                'total' => $amounts['total'], 'taxes' => $amounts['taxes'], 'shipping' => $amounts['shipping'], 'refunded' => $amounts['refunded'], 'net' => $net, 'currency' => $currency,
                'userId' => $email !== '' ? ($accounts[$email] ?? null) : null, 'items' => $items, 'lines' => $lines] + $item;
        }

        return $plan;
    }

    /**
     * Le rapport d'analyse : des comptes et des numéros de ligne, jamais un courriel ni un nom.
     *
     * @param list<array<string, mixed>> $plan
     * @return array<string, mixed>
     */
    public function report(array $plan): array
    {
        $orders = ['total' => count($plan), 'importable' => 0, 'paid' => 0, 'refunded' => 0, 'cancelled' => 0, 'unpaid' => 0, 'already' => 0, 'tooOld' => 0, 'refused' => 0,
            'linkedToAccount' => 0, 'withoutAccount' => 0, 'lines' => 0, 'linesWithProduct' => 0, 'netCents' => 0, 'firstDay' => null, 'lastDay' => null];
        $issues = [];
        foreach ($plan as $item) {
            if ($item['status'] === 'ok') {
                ++$orders['importable'];
                ++$orders[$item['state']];
                $item['userId'] !== null ? ++$orders['linkedToAccount'] : ++$orders['withoutAccount'];
                $orders['lines'] += count($item['lines']);
                $orders['linesWithProduct'] += count(array_filter($item['lines'], static fn (array $l): bool => $l['productId'] !== null));
                if ($item['state'] === self::PAID) {
                    $orders['netCents'] += $item['net'];
                }
                $orders['firstDay'] = $orders['firstDay'] === null ? $item['day'] : min($orders['firstDay'], $item['day']);
                $orders['lastDay'] = $orders['lastDay'] === null ? $item['day'] : max($orders['lastDay'], $item['day']);
                continue;
            }
            if ($item['status'] === 'already') {
                ++$orders['already'];
                continue;
            }
            if ($item['status'] === 'too_old') {
                ++$orders['tooOld'];
                continue;
            }
            ++$orders['refused'];
            if (count($issues) < 1000) {
                $issues[] = ['line' => $item['line'], 'code' => $item['status'] === 'duplicate' ? 'DUPLICATE_ORDER' : (string) $item['code']];
            }
        }

        return ['orders' => $orders, 'months' => self::MONTHS, 'currency' => strtoupper($this->currency->code()), 'issues' => $issues];
    }

    /** Une date du fichier (« 2025-03-05 14:23:11 -0500 », ou une date ISO), rendue en UTC ; null si elle ne se lit pas */
    public static function date(string $text): ?\DateTimeImmutable
    {
        $text = trim($text);
        if ($text === '' || preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?( ?(Z|[+-]\d{2}:?\d{2}|UTC))?$/', $text) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Un montant du fichier (« 1234.50 », « 1 234,50 ») en cents ; null s'il est vide, négatif ou illisible */
    public static function cents(string $text): ?int
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', trim($text));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d+(,\d{1,2})$/', $text) === 1) {
            $text = str_replace(',', '.', $text); // « 12,50 »
        } else {
            $text = str_replace(',', '', $text); // « 1,234.50 »
        }
        if (preg_match('/^\d{1,12}(\.\d{1,4})?$/', $text) !== 1) {
            return null;
        }

        return (int) round((float) $text * 100);
    }

    /**
     * Les comptes du site qui portent ces adresses (client, ou invité repris par IMP-2) : adresse en minuscules → identifiant.
     *
     * @param list<string> $emails
     * @return array<string, int>
     */
    private function accountsOf(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn (string $e): string => mb_strtolower(trim($e)), $emails), static fn (string $e): bool => $e !== '' && mb_strlen($e) <= 180)));
        $out = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            foreach ($this->conn()->fetchAllAssociative('SELECT id, LOWER(email) AS email FROM "user" WHERE LOWER(email) IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id DESC', $chunk) as $row) {
                $out[(string) $row['email']] = (int) $row['id'];
            }
        }

        return $out;
    }

    /**
     * Les articles du site que ces références désignent SANS AMBIGUÏTÉ (l'import du catalogue pose la référence de la première
     * variante comme code de l'article) : référence en minuscules → identifiant. Une référence portée par deux articles ne lie rien.
     *
     * @param list<string> $skus
     * @return array<string, int>
     */
    private function productsOf(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn (string $s): string => mb_strtolower(trim($s)), $skus), static fn (string $s): bool => $s !== '' && mb_strlen($s) <= 100)));
        $out = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($this->conn()->fetchAllAssociative('SELECT LOWER(code) AS code, MIN(id) AS id, COUNT(*) AS n FROM product WHERE LOWER(code) IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY LOWER(code)', $chunk) as $row) {
                if ((int) $row['n'] === 1) {
                    $out[(string) $row['code']] = (int) $row['id'];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $job */
    private function cancellable(array $job): bool
    {
        if (in_array($job['status'], [ImportJobStore::UPLOADED, ImportJobStore::ANALYSED], true)) {
            return true;
        }
        if ($job['status'] === ImportJobStore::RUNNING) {
            $started = $job['started_at'] !== null ? new \DateTimeImmutable((string) $job['started_at'], new \DateTimeZone('UTC')) : null;

            return $started !== null && $started < new \DateTimeImmutable('-' . self::STALE_MINUTES . ' minutes');
        }
        if (!in_array($job['status'], [ImportJobStore::DONE, ImportJobStore::FAILED], true)) {
            return false;
        }

        return $this->archived((int) $job['id']) > 0;
    }

    private function archived(int $jobId): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM import_order WHERE job_id = ?', [$jobId]);
    }

    /** @param array<string, mixed> $after @param list<string> $fields */
    private function journal(int $id, string $action, array $after, array $fields): void
    {
        try {
            $this->audit->record('import_job', $id, $action, null, $after, $fields);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] journal de l\'import %d non écrit (%s)', $id, $e::class));
        }
    }

    /** @return array<string, mixed> */
    private function job(int $id): array
    {
        return $this->store->find($id) ?? throw new ImportException('IMPORT_NOT_FOUND', 404);
    }

    /** @return array<string, mixed> un import de COMMANDES */
    private function ordersJob(int $id): array
    {
        $job = $this->job($id);
        if ($job['kind'] !== ImportJobStore::KIND_ORDERS) {
            throw new ImportException('IMPORT_NOT_FOUND', 404);
        }

        return $job;
    }

    /** @param array<string, mixed> $job */
    private function view(array $job, bool $full = true): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $report = (array) ($job['report'] ?? []);
        $view = [
            'id' => $job['id'], 'source' => $job['source'], 'kind' => $job['kind'], 'status' => $job['status'], 'fileName' => $job['file_name'], 'rows' => $job['row_count'],
            'createdAt' => $iso($job['created_at']), 'analysedAt' => $iso($job['analysed_at']), 'startedAt' => $iso($job['started_at']), 'finishedAt' => $iso($job['finished_at']), 'cancelledAt' => $iso($job['cancelled_at']),
            'cancellable' => $this->cancellable($job), 'cancellableUntil' => null, 'error' => $job['error'], 'options' => $job['options'],
            // les commandes d'avant que cet import a mises en archive, et qui y sont encore
            'archived' => $this->archived((int) $job['id']),
        ];
        if ($full) {
            $issues = (array) ($report['issues'] ?? []);
            $view['report'] = $report === [] ? null : ['issues' => array_slice($issues, 0, 100), 'issuesTotal' => count($issues)] + $report;
            $view['result'] = $job['result'];
        } else {
            $view['summary'] = $report === [] ? null : ['orders' => $report['orders'] ?? null];
        }

        return $view;
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private function directory(): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenants->getCurrentTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/imports/' . $code;
    }
}
