<?php

namespace App\Services\Group\Transfer;

use App\Entity\User;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Group\GroupCompanyService;
use App\Services\Group\GroupException;
use App\Services\Group\GroupRepository;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Lot G-5 (19/11/2026, contrat validé par le chef, 3 conditions ; `docs/ecrans-et-contextes.md` § 17) : le TRANSFERT LÉGER entre boutiques
 * d'un groupe. Le directeur DEMANDE au siège ; l'administrateur de la SOURCE l'ENVOIE ; la DESTINATION le reçoit par sa réception habituelle.
 * Trois bases sans transaction commune : l'état de la base maître fait foi, chaque passage est conditionnel, chaque écriture d'un site porte
 * sa marque (`GroupTransferSiteWriter`). Ordre de l'envoi : la SORTIE chez la source d'abord, la COMMANDE chez la destination ensuite ; si la
 * destination échoue, le stock revient chez la source, l'état passe à `send_failed` et un incident de la plateforme est ouvert.
 * Boutiques permises : de la même entreprise que le siège (`GroupCompanyService::same`, relu à CHAQUE geste) ; sinon 422 TRANSFER_ASSOCIATED_SHOP.
 */
class GroupTransferService
{
    public const MAX_LINES = 200;
    /** Un envoi resté `sending` plus longtemps est repris par `app:group:transfers:resume` */
    public const STUCK_MINUTES = 5;

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupTransferRepository $transfers,
        private readonly GroupTransferSiteWriter $writer,
        private readonly GroupCompanyService $company,
        private readonly ModuleAccessService $modules,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantDatabaseConnector $databases,
        private readonly GroupTransferMailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Incident\Services\PlatformIncidentService $incidents = null,
    ) {
    }

    /**
     * Le DIRECTEUR demande un transfert (au siège).
     *
     * @param array<string, mixed> $body { source, target, lines: [{ variantId, quantity }] }
     * @return array<string, mixed>
     * @throws GroupException
     */
    public function request(User $by, array $body): array
    {
        $group = $this->directedGroup($by);
        $source = is_string($body['source'] ?? null) ? $body['source'] : '';
        $target = is_string($body['target'] ?? null) ? $body['target'] : '';
        $rawLines = $body['lines'] ?? null;
        $errors = [];
        if ($source === '' || $target === '' || $source === $target) {
            $errors['target'] = 'SOURCE_TARGET_DIFFERENT';
        }
        if (!is_array($rawLines) || !array_is_list($rawLines) || $rawLines === [] || count($rawLines) > self::MAX_LINES) {
            $errors['lines'] = 'LINES_1_TO_' . self::MAX_LINES;
        } else {
            foreach ($rawLines as $i => $l) {
                if (!is_array($l) || !is_int($l['variantId'] ?? null) || $l['variantId'] <= 0 || !is_int($l['quantity'] ?? null) || $l['quantity'] <= 0 || $l['quantity'] > 100000) {
                    $errors["lines[$i]"] = 'VARIANT_AND_POSITIVE_QUANTITY';
                }
            }
        }
        if ($errors !== []) {
            throw new GroupException('VALIDATION_FAILED', 422, 'Transfert : une source, une destination différente, et des lignes (variante, quantité entière positive).', ['fields' => $errors]);
        }
        $shops = $this->shops((int) $group['id']);
        if (!isset($shops[$source], $shops[$target])) {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est pas dans votre groupe.');
        }
        $this->sameCompany((int) $group['id'], $source, $target);
        $currency = $this->currency($shops[$source]);
        if ($currency !== $this->currency($shops[$target])) {
            throw new GroupException('TRANSFER_CURRENCY_DIFFERS', 409, 'Les deux boutiques n\'ont pas la même devise : aucun transfert entre elles.');
        }
        // la variante de la source, son article, et sa correspondance dans la destination (G-4) : sinon, « copiez d'abord l'article »
        $ids = array_values(array_unique(array_map(static fn (array $l): int => $l['variantId'], $rawLines)));
        $products = $this->variantProducts($shops[$source], $ids);
        $lines = [];
        $missing = [];
        foreach ($rawLines as $i => $l) {
            $link = isset($products[$l['variantId']]) ? $this->transfers->link((int) $group['id'], $source, $l['variantId'], $target) : null;
            if ($link === null) {
                $missing[] = ['line' => $i, 'variantId' => $l['variantId']];
                continue;
            }
            $lines[] = ['line_no' => $i + 1, 'source_product_id' => $products[$l['variantId']], 'source_variant_id' => $l['variantId'], 'target_product_id' => $link['target_product_id'],
                'target_variant_id' => $link['target_variant_id'], 'quantity' => $l['quantity']];
        }
        if ($missing !== []) {
            throw new GroupException('TRANSFER_ITEM_NOT_IN_TARGET', 422, 'Des articles n\'existent pas dans la boutique de destination : copiez-les d\'abord (copie du catalogue).', ['lines' => $missing]);
        }
        $id = $this->transfers->create((int) $group['id'], $source, $target, $currency, $lines, self::who($by));
        $this->transfers->event($id, 'requested', ['lines' => count($lines)], self::who($by));
        $this->mailer->send($shops[$source]['dbname'] ?? null, 'requested', ['%group%' => (string) $group['name'], '%site%' => $this->name($shops[$source]), '%target%' => $this->name($shops[$target]), '%count%' => (string) count($lines)], 'fr');

        return $this->present($this->transfers->find($id));
    }

    /** @return list<array<string, mixed>> les transferts du groupe (directeur), l'état de réception relu @throws GroupException */
    public function list(User $by): array
    {
        $group = $this->directedGroup($by);

        return array_map(fn (array $t): array => $this->present($this->refreshReceived($t), false), $this->transfers->list((int) $group['id']));
    }

    /** Plus longue recherche dans le catalogue d'une boutique (millisecondes), comme une boutique de « Toutes les boutiques » */
    public const SEARCH_TIMEOUT_MS = 3000;
    /** @var array<int, array<string, string>> lot G-5c : noms des boutiques par groupe, lus une fois par requête */
    private array $siteNames = [];

    /**
     * Le directeur cherche les VARIANTES d'une boutique du groupe (la source d'un transfert), au siège : nom, référence, code-barres, stock
     * disponible, jamais le coût ; `copiedTo` dit si l'article est déjà dans la boutique `target` (sinon : copiez-le d'abord). Boutique de la
     * même entreprise seulement. Lecture dans une transaction READ ONLY sous `statement_timeout`, retour au siège dans un `finally`.
     *
     * @return list<array{variantId: int, productId: int, name: string, reference: ?string, barcode: ?string, stock: int, copiedToTarget: ?bool}>
     * @throws GroupException
     */
    public function searchVariants(User $by, string $shop, mixed $q, mixed $limit, mixed $target = null): array
    {
        $group = $this->directedGroup($by);
        $shops = $this->shops((int) $group['id']);
        if (!isset($shops[$shop])) {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est pas dans votre groupe.');
        }
        $this->sameCompany((int) $group['id'], $shop, $shop);
        $term = is_string($q) ? mb_substr(trim($q), 0, 80) : '';
        $max = max(1, min(50, is_numeric($limit) ? (int) $limit : 20));
        $targetCode = is_string($target) && isset($shops[$target]) && $target !== $shop ? $target : null;
        $home = $this->home();
        try {
            $this->emProvider->switchTenant((string) $shops[$shop]['dbname'], $shop);
            $conn = $this->emProvider->getEntityManager()->getConnection();
            $conn->beginTransaction();
            try {
                $conn->executeStatement('SET TRANSACTION READ ONLY');
                $conn->executeStatement('SET LOCAL statement_timeout = ' . self::SEARCH_TIMEOUT_MS);
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $rows = $conn->fetchAllAssociative("SELECT v.id AS variant_id, p.id AS product_id, p.name, p.code, COALESCE(v.barcode, p.barcode) AS barcode, v.stock_quantity
                    FROM product_variant v JOIN product p ON p.id = v.product_id
                    WHERE p.product_type IN ('product', 'standard')" . ($term !== '' ? ' AND (p.name ILIKE ? OR p.code ILIKE ? OR v.barcode ILIKE ? OR p.barcode ILIKE ?)' : '') . '
                    ORDER BY p.name, v.id LIMIT ' . $max, $term !== '' ? [$like, $like, $like, $like] : []);
            } finally {
                $conn->isTransactionActive() && $conn->rollBack();
            }
        } finally {
            $this->restore($home);
        }

        return array_map(fn (array $r): array => ['variantId' => (int) $r['variant_id'], 'productId' => (int) $r['product_id'], 'name' => (string) $r['name'], 'reference' => $r['code'] !== null ? (string) $r['code'] : null,
            'barcode' => $r['barcode'] !== null ? (string) $r['barcode'] : null, 'stock' => max(0, (int) $r['stock_quantity']),
            'copiedToTarget' => $targetCode === null ? null : $this->transfers->link((int) $group['id'], $shop, (int) $r['variant_id'], $targetCode) !== null], $rows);
    }

    /** @param array<string, mixed> $group le groupe déjà relu (directeur vérifié par l'appelant, l'assistant) @return list<array<string, mixed>> */
    public function listForGroup(array $group): array
    {
        return array_map(fn (array $t): array => $this->present($this->refreshReceived($t), false), $this->transfers->list((int) $group['id'], null, 50));
    }

    /** @return array<string, mixed> la fiche ; chaque ligne porte `receivedQuantity` (lue dans la commande de destination, null avant l'envoi) @throws GroupException */
    public function show(User $by, int $id): array
    {
        $group = $this->directedGroup($by);
        $transfer = $this->refreshReceived($this->own((int) $group['id'], $id));
        $view = $this->present($transfer);
        $received = $this->receivedByLine($transfer);
        foreach ($view['lines'] as $i => $line) {
            $view['lines'][$i]['receivedQuantity'] = $received[$line['line']] ?? null;
        }

        return $view;
    }

    /**
     * La quantité reçue de chaque ligne, lue dans la commande de destination (une lecture, au moment de la fiche). Deux lignes du transfert sur
     * la même variante n'ont qu'une ligne de commande (fusionnée) : leur quantité reçue est alors inconnue par ligne (null).
     *
     * @param array<string, mixed> $transfer
     * @return array<int, ?int> line_no => quantité reçue
     */
    private function receivedByLine(array $transfer): array
    {
        if ($transfer['target_commande_id'] === null) {
            return [];
        }
        $home = $this->home();
        try {
            $target = $this->tenantRow((string) $transfer['target_tenant_code']);
            $this->emProvider->switchTenant((string) $target['dbname'], (string) $transfer['target_tenant_code']);
            $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT product_id, product_variant_id AS variant_id, quantity_received FROM commande_line WHERE commande_id = ?', [(int) $transfer['target_commande_id']]);
        } catch (\Throwable $e) {
            $this->logger->warning('Transfert : réception illisible pour la fiche (' . $e::class . ').');

            return [];
        } finally {
            $this->restore($home);
        }
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['product_id'] . ':' . ($r['variant_id'] ?? '')] = (int) $r['quantity_received'];
        }
        $lines = $this->transfers->lines((int) $transfer['id']);
        $count = array_count_values(array_map(static fn (array $l): string => $l['target_product_id'] . ':' . ($l['target_variant_id'] ?? ''), $lines));
        $out = [];
        foreach ($lines as $l) {
            $key = $l['target_product_id'] . ':' . ($l['target_variant_id'] ?? '');
            $out[(int) $l['line_no']] = $count[$key] === 1 && array_key_exists($key, $byKey) ? $byKey[$key] : null;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> les transferts à ENVOYER par la boutique courante (son administrateur) */
    public function toSend(string $site): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null) {
            return [];
        }

        return array_values(array_map(fn (array $t): array => $this->present($t, true, false), array_filter($this->transfers->list((int) $group['id'], $site),
            static fn (array $t): bool => in_array($t['state'], ['requested', 'send_failed', 'sending'], true))));
    }

    /**
     * L'administrateur de la SOURCE envoie. Double clic : une seule requête passe à `sending` (passage conditionnel) et tient le verrou du
     * transfert pendant tout l'envoi.
     *
     * @return array<string, mixed>
     * @throws GroupException
     */
    public function send(string $site, int $id, User $by): array
    {
        $group = $this->groups->ofSite($site);
        $transfer = $group !== null ? $this->transfers->find($id) : null;
        if ($group === null || $transfer === null || (int) $transfer['group_id'] !== (int) $group['id'] || $transfer['source_tenant_code'] !== $site) {
            throw new GroupException('TRANSFER_NOT_FOUND', 404, 'Transfert introuvable.');
        }
        $shops = $this->shops((int) $group['id']);
        if (!isset($shops[$transfer['target_tenant_code']])) {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'La boutique de destination n\'est plus dans le groupe.');
        }
        $this->sameCompany((int) $group['id'], $site, (string) $transfer['target_tenant_code']);
        if (!$this->lock($id)) {
            throw new GroupException('TRANSFER_ALREADY_SENDING', 409, 'L\'envoi est déjà en cours.');
        }
        try {
            if (!$this->transfers->move($id, ['requested', 'send_failed'], 'sending', ['attempt' => 'attempt+1', 'sending_since' => 'now', 'sent_by' => self::who($by)])) {
                $state = (string) ($this->transfers->find($id)['state'] ?? '');
                throw match ($state) {
                    'sending' => new GroupException('TRANSFER_ALREADY_SENDING', 409, 'L\'envoi est déjà en cours.'),
                    'cancelled' => new GroupException('TRANSFER_CANCELLED', 409, 'Ce transfert est annulé.'),
                    default => new GroupException('TRANSFER_ALREADY_SENT', 409, 'Ce transfert est déjà envoyé.'),
                };
            }
            $this->transfers->event($id, 'sending', [], self::who($by));

            return $this->present($this->deliver($this->transfers->find($id) ?? [], $group, $shops, self::who($by)), true, false);
        } finally {
            $this->unlock($id);
        }
    }

    /**
     * Le DIRECTEUR annule : demandé, simple fermeture ; en échec d'envoi, envoyé sans réception, ou bloqué en envoi (verrou libre : aucun envoi
     * vivant), la commande de destination éventuelle est annulée puis le stock de TOUTES les tentatives revient chez la source ; une réception
     * commencée : 409 TRANSFER_PARTLY_RECEIVED ; un envoi vivant : 409 TRANSFER_SENDING.
     *
     * @return array<string, mixed>
     * @throws GroupException
     */
    public function cancel(User $by, int $id): array
    {
        $group = $this->directedGroup($by);
        $this->own((int) $group['id'], $id);
        if (!$this->lock($id)) {
            throw new GroupException('TRANSFER_SENDING', 409, 'L\'envoi est en cours : réessayez dans un instant.');
        }
        try {
            $transfer = $this->refreshReceived($this->own((int) $group['id'], $id));
            $who = self::who($by);
            $from = (string) $transfer['state'];
            switch ($from) {
                case 'requested':
                    break;
                case 'send_failed':
                case 'sent':
                case 'sending': // verrou pris : aucun envoi vivant, l'envoi est bloqué (panne) ; on défait ce qui a été fait
                    $this->undo($transfer);
                    break;
                case 'received':
                    throw new GroupException('TRANSFER_RECEIVED', 409, 'Ce transfert est reçu : il ne s\'annule plus.');
                default:
                    throw new GroupException('TRANSFER_CANCELLED', 409, 'Ce transfert est déjà annulé.');
            }
            if (!$this->transfers->move($id, [$from], 'cancelled', ['cancelled_by' => $who, 'cancelled_at' => 'now', 'sending_since' => null])) {
                throw new GroupException('TRANSFER_SENDING', 409, 'L\'état du transfert vient de changer : relisez-le.');
            }
            $this->transfers->event($id, 'cancelled', ['from' => $from], $who);
        } finally {
            $this->unlock($id);
        }

        return $this->present($this->transfers->find($id));
    }

    /**
     * Reprise des envois bloqués en `sending` (passage planifié) : un envoi VIVANT tient le verrou du transfert, il est laissé ; sinon l'envoi
     * est terminé d'après les marques, jamais une seconde sortie. Une erreur sur un transfert n'arrête pas les suivants.
     *
     * @return int repris
     */
    public function resume(): int
    {
        $done = 0;
        foreach ($this->transfers->stuck(self::STUCK_MINUTES) as $id) {
            try {
                if (!$this->lock($id)) {
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Transfert : verrou illisible pendant la reprise (' . $e::class . ').');

                break;
            }
            try {
                $transfer = $this->transfers->find($id);
                $group = $transfer !== null ? $this->groups->find((int) $transfer['group_id']) : null;
                if ($transfer === null || $group === null || $transfer['state'] !== 'sending') {
                    continue;
                }
                $this->transfers->event($id, 'resumed', ['attempt' => (int) $transfer['attempt']], 'reprise');
                $this->deliver($transfer, $group, $this->shops((int) $group['id']), 'reprise');
                ++$done;
            } catch (\Throwable $e) {
                $this->logger->warning('Transfert : reprise terminée en erreur (' . ($e instanceof GroupException ? $e->errorCode : $e::class) . ').');
                $this->transfers->postpone($id); // derrière les autres envois bloqués : un transfert fautif n'en affame aucun
            } finally {
                $this->unlock($id);
            }
        }

        return $done;
    }

    /**
     * L'envoi, le VERROU DU TRANSFERT TENU par l'appelant : les sorties d'une tentative précédente non rendues le sont d'abord ; la SORTIE chez
     * la source (une transaction, marques), le coût figé, la COMMANDE chez la destination (une transaction, marque), `sent`. Destination en échec
     * et AUCUNE commande chez elle : le stock revient chez la source, `send_failed`, incident. Rejouable (reprise) sans doublon.
     *
     * @param array<string, mixed> $transfer
     * @param array<string, mixed> $group
     * @param array<string, array<string, mixed>> $shops
     * @return array<string, mixed>|null
     * @throws GroupException
     */
    private function deliver(array $transfer, array $group, array $shops, string $who): ?array
    {
        $id = (int) $transfer['id'];
        $uid = (string) $transfer['uid'];
        // les marques de SORTIE et de RETOUR sont propres à une tentative d'envoi : après une compensation, le renvoi sort de nouveau ; une
        // reprise garde la même tentative (rien ne sort deux fois) ; la commande de destination, elle, est unique pour le transfert
        $attemptKey = self::attemptKey($transfer);
        $source = $shops[$transfer['source_tenant_code']] ?? $this->tenantRow((string) $transfer['source_tenant_code']);
        $target = $shops[$transfer['target_tenant_code']] ?? null; // une destination sortie du groupe ne reçoit plus rien
        $home = $this->home();
        $commande = null;
        try {
            $this->emProvider->switchTenant((string) $source['dbname'], (string) $transfer['source_tenant_code']);
            // une tentative précédente dont la sortie n'aurait pas été rendue (panne pendant la compensation) : rendue AVANT de sortir de nouveau
            for ($a = 1; $a < (int) $transfer['attempt']; ++$a) {
                $this->writer->sendBack($uid . '#' . $a, $this->sourceLines($id));
            }
            // 1. la SORTIE chez la source
            try {
                $out = $this->writer->sendOut($attemptKey, $this->sourceLines($id));
            } catch (GroupException $e) {
                // stock court ou occupé : rien n'est sorti, la demande revient à son état d'avant l'envoi
                $this->transfers->move($id, ['sending'], (int) $transfer['attempt'] > 1 ? 'send_failed' : 'requested', ['sending_since' => null]);
                $this->transfers->event($id, 'send_refused', ['code' => $e->errorCode], $who);

                throw $e;
            }
            // le coût figé (aussi à la reprise : une panne entre la sortie et l'écriture du coût ne le perd pas)
            $this->transfers->setCosts($id, $out['costs'], true);
            // 2. la COMMANDE chez la destination
            try {
                if ($target === null) {
                    throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'La boutique de destination n\'est plus dans le groupe.');
                }
                $this->emProvider->switchTenant((string) $target['dbname'], (string) $transfer['target_tenant_code']);
                $commande = $this->writer->createOrder($uid, $this->name($source), $this->targetLines($id), $who);
            } catch (\Throwable $e) {
                // une commande a-t-elle quand même été écrite (validée côté base, erreur côté PHP) ? Alors l'envoi est fait : pas de compensation
                $commande = $this->existingOrder($transfer);
                $this->assertLockHeld($id);
                if ($commande === null) {
                    // compensation : le stock revient chez la source ; l'état le dit ; la plateforme est prévenue
                    $this->emProvider->switchTenant((string) $source['dbname'], (string) $transfer['source_tenant_code']);
                    $this->writer->sendBack($attemptKey, $this->sourceLines($id));
                    $this->transfers->move($id, ['sending'], 'send_failed', ['sending_since' => null]);
                    $this->transfers->event($id, 'send_failed', ['error' => $e::class, 'code' => $e instanceof GroupException ? $e->errorCode : null], $who);
                    $this->incidents?->recordPlatformIssue((string) $transfer['source_tenant_code'], 'group_transfer', 'error', 'Transfert entre boutiques : la boutique de destination a refusé la commande ; stock rendu à la source.',
                        ['transfer' => $id, 'error' => $e::class]);
                    $this->mailer->send($shops[$group['head_tenant_code']]['dbname'] ?? null, 'send_failed', ['%group%' => (string) $group['name'], '%site%' => $this->name($source),
                        '%target%' => $this->name($target ?? ['tenant_code' => $transfer['target_tenant_code']])], 'fr');

                    throw new GroupException('TRANSFER_SEND_FAILED', 503, 'L\'envoi n\'a pas pu aboutir : le stock est revenu dans votre boutique. Le directeur est prévenu ; vous pourrez renvoyer.', [], $e);
                }
            }
        } finally {
            $this->restore($home);
        }
        // 3. envoyé (le verrou du transfert doit être encore tenu : une connexion maître recréée l'aurait perdu)
        $this->assertLockHeld($id);
        if ($this->transfers->move($id, ['sending'], 'sent', ['target_commande_id' => $commande, 'sent_at' => 'now', 'sending_since' => null])) {
            $this->transfers->event($id, 'sent', ['commande' => $commande], $who);
            $this->mailer->send($target['dbname'] ?? null, 'sent', ['%group%' => (string) $group['name'], '%site%' => $this->name($target ?? []), '%source%' => $this->name($source), '%count%' => (string) count($this->transfers->lines($id))], 'fr');
        }

        return $this->transfers->find($id);
    }

    /**
     * Défait un transfert (annulation, verrou tenu) : la commande de destination éventuelle est annulée (refusé si une réception a commencé),
     * puis le stock de TOUTES les tentatives revient chez la source. Les deux sites sont relus même sortis du groupe.
     *
     * @param array<string, mixed> $transfer
     * @throws GroupException
     */
    private function undo(array $transfer): void
    {
        $id = (int) $transfer['id'];
        $home = $this->home();
        try {
            $commande = $transfer['target_commande_id'] !== null ? (int) $transfer['target_commande_id'] : $this->existingOrder($transfer);
            if ($commande !== null) {
                $target = $this->tenantRow((string) $transfer['target_tenant_code']);
                $this->emProvider->switchTenant((string) $target['dbname'], (string) $transfer['target_tenant_code']);
                $this->writer->cancelOrder($commande, count($this->transfers->lines($id)));
            }
            $source = $this->tenantRow((string) $transfer['source_tenant_code']);
            $this->emProvider->switchTenant((string) $source['dbname'], (string) $transfer['source_tenant_code']);
            for ($a = 1; $a <= max(1, (int) $transfer['attempt']); ++$a) {
                $this->writer->sendBack((string) $transfer['uid'] . '#' . $a, $this->sourceLines($id));
            }
        } finally {
            $this->restore($home);
        }
    }

    /**
     * La commande de destination déjà écrite pour ce transfert (marque `order`), lue dans le site de destination. Une lecture IMPOSSIBLE n'est
     * jamais « pas de commande » : elle lève (relecture G-5, passe 2), et l'appelant ne compense rien.
     *
     * @param array<string, mixed> $transfer
     * @throws GroupException TRANSFER_SEND_PENDING
     */
    private function existingOrder(array $transfer): ?int
    {
        try {
            $target = $this->tenantRow((string) $transfer['target_tenant_code']);
            $this->emProvider->switchTenant((string) $target['dbname'], (string) $transfer['target_tenant_code']);

            return $this->writer->marks((string) $transfer['uid'], GroupTransferSiteWriter::ORDER)[0] ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning('Transfert : commande de destination illisible (' . $e::class . ') : rien n\'est compensé.');

            throw new GroupException('TRANSFER_SEND_PENDING', 503, 'L\'envoi est en cours de vérification : la boutique de destination ne répond pas. Il sera repris automatiquement.', [], $e);
        }
    }

    /**
     * Le verrou est-il TOUJOURS tenu par cette connexion ? `getPdoMaster()` recrée la connexion après une coupure, et le verrou de session est
     * alors perdu sans bruit : l'envoi s'arrête (état laissé, reprise). NE JAMAIS placer un pooler en mode transaction devant la base maître.
     *
     * @throws GroupException TRANSFER_SEND_PENDING
     */
    private function assertLockHeld(int $id): void
    {
        if (!$this->transfers->holdsLock($id)) {
            throw new GroupException('TRANSFER_SEND_PENDING', 503, 'L\'envoi est en cours de vérification ; il sera repris automatiquement.');
        }
    }

    /** Verrou consultatif du transfert dans la base maître (session) : un seul envoi, une seule reprise, une seule annulation à la fois */
    private function lock(int $id): bool
    {
        return (bool) $this->transfers->tryLock($id);
    }

    private function unlock(int $id): void
    {
        try {
            $this->transfers->unlock($id);
        } catch (\Throwable $e) {
            $this->logger->warning('Transfert : verrou non rendu (' . $e::class . ').');
        }
    }

    /** Un transfert `sent` dont la commande de destination est toute reçue passe à `received` (lu, jamais écrit par la réception) @param array<string, mixed> $transfer @return array<string, mixed> */
    private function refreshReceived(array $transfer): array
    {
        if ($transfer['state'] !== 'sent' || $transfer['target_commande_id'] === null) {
            return $transfer;
        }
        $target = $this->tenantRow((string) $transfer['target_tenant_code']);
        $home = $this->home();
        try {
            $this->emProvider->switchTenant((string) $target['dbname'], (string) $transfer['target_tenant_code']);
            $reception = $this->writer->reception((int) $transfer['target_commande_id']);
        } catch (\Throwable $e) {
            $this->logger->warning('Transfert : réception illisible (' . $e::class . ').');

            return $transfer;
        } finally {
            $this->restore($home);
        }
        if ($reception['all'] && $this->transfers->move((int) $transfer['id'], ['sent'], 'received', ['received_at' => 'now'])) {
            $this->transfers->event((int) $transfer['id'], 'received', [], null);

            return $this->transfers->find((int) $transfer['id']) ?? $transfer;
        }

        return $transfer + ['_partly' => $reception['partly']];
    }

    /** @return list<array{line_no: int, source_variant_id: int, quantity: int}> */
    private function sourceLines(int $id): array
    {
        return array_map(static fn (array $l): array => ['line_no' => (int) $l['line_no'], 'source_variant_id' => (int) $l['source_variant_id'], 'quantity' => (int) $l['quantity']], $this->transfers->lines($id));
    }

    /** @return list<array{line_no: int, target_product_id: int, target_variant_id: ?int, quantity: int, unit_cost_cents: ?int}> */
    private function targetLines(int $id): array
    {
        return array_map(static fn (array $l): array => ['line_no' => (int) $l['line_no'], 'target_product_id' => (int) $l['target_product_id'], 'target_variant_id' => $l['target_variant_id'] === null ? null : (int) $l['target_variant_id'],
            'quantity' => (int) $l['quantity'], 'unit_cost_cents' => $l['unit_cost_cents'] === null ? null : (int) $l['unit_cost_cents']], $this->transfers->lines($id));
    }

    /** @throws GroupException */
    private function sameCompany(int $groupId, string $source, string $target): void
    {
        foreach ([$source, $target] as $code) {
            if (!$this->company->same($groupId, $code)) {
                throw new GroupException('TRANSFER_ASSOCIATED_SHOP', 422, 'Cette boutique est une entreprise associée : un transfert avec elle serait une vente entre deux entreprises (facture, taxes). Ce cas n\'est pas encore permis ; le lien « même entreprise » le permet.', ['shop' => $code]);
            }
        }
    }

    /** @return array<string, mixed> le groupe dont le compte est le DIRECTEUR (administrateur du siège, module actif) @throws GroupException */
    private function directedGroup(User $user): array
    {
        $current = (string) $this->tenant->getTenantCode();
        $group = $current === '' ? null : $this->groups->ofSite($current);
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true) || PlatformAccessJournal::isPlatformAccount($user->getRoles())
            || $group === null || $group['head_tenant_code'] !== $current || !$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
            throw new GroupException('GROUP_TRANSFER_FORBIDDEN', 403, 'Transfert : réservé au directeur du groupe, sur le site du siège.');
        }

        return $group;
    }

    /** @return array<string, mixed> @throws GroupException */
    private function own(int $groupId, int $id): array
    {
        $transfer = $this->transfers->find($id);
        if ($transfer === null || (int) $transfer['group_id'] !== $groupId) {
            throw new GroupException('TRANSFER_NOT_FOUND', 404, 'Transfert introuvable.');
        }

        return $transfer;
    }

    /** @return array<string, array<string, mixed>> les boutiques OUVERTES du groupe avec leur base */
    private function shops(int $groupId): array
    {
        $out = [];
        foreach ($this->groups->shopDatabases($groupId) as $row) {
            if (($row['dbname'] ?? '') !== '') {
                $out[(string) $row['tenant_code']] = $row;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> la ligne d'un site (même sorti du groupe : pour rendre le stock d'un transfert déjà envoyé) @throws GroupException */
    private function tenantRow(string $code): array
    {
        $row = $this->databases->tenant($code);
        if ($row === null || ($row['dbname'] ?? '') === '') {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Boutique inconnue.');
        }

        return ['tenant_code' => $code, 'name' => $row['name'] ?? $code, 'dbname' => $row['dbname']];
    }

    /** @param array<string, mixed> $shop */
    private function currency(array $shop): string
    {
        $code = $this->databases->pdo((string) $shop['dbname'])->query('SELECT currency FROM entreprise ORDER BY id LIMIT 1')->fetchColumn();

        return strtoupper(is_string($code) && $code !== '' ? $code : \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT);
    }

    /** @param array<string, mixed> $shop @param list<int> $ids @return array<int, int> variante => article */
    private function variantProducts(array $shop, array $ids): array
    {
        $rows = $this->databases->pdo((string) $shop['dbname'])->query('SELECT id, product_id FROM product_variant WHERE product_id IS NOT NULL AND id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map('intval', array_column($rows, 'product_id', 'id'));
    }

    /** @param array<string, mixed> $shop */
    private function name(array $shop): string
    {
        return (string) (($shop['name'] ?? '') !== '' ? $shop['name'] : ($shop['tenant_code'] ?? ''));
    }

    /** @param array<string, mixed>|null $t @return array<string, mixed> `$withCost` : le coût n'est montré qu'au directeur et à la destination (§ 17.5) */
    private function present(?array $t, bool $withLines = true, bool $withCost = true): array
    {
        if ($t === null) {
            throw new GroupException('TRANSFER_NOT_FOUND', 404, 'Transfert introuvable.');
        }
        $iso = static fn (mixed $at): ?string => $at === null ? null : (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        $out = ['id' => (int) $t['id'], 'source' => (string) $t['source_tenant_code'], 'target' => (string) $t['target_tenant_code'],
            'sourceName' => $this->siteName((int) $t['group_id'], (string) $t['source_tenant_code']), 'targetName' => $this->siteName((int) $t['group_id'], (string) $t['target_tenant_code']), 'state' => (string) $t['state'],
            'currency' => $t['currency'], 'requestedAt' => $iso($t['requested_at']), 'sentAt' => $iso($t['sent_at']), 'receivedAt' => $iso($t['received_at']),
            'cancelledAt' => $iso($t['cancelled_at']), 'partlyReceived' => (bool) ($t['_partly'] ?? false), 'targetOrderId' => $t['target_commande_id'] === null ? null : (int) $t['target_commande_id']];
        if ($withLines) {
            $lines = $this->transfers->lines((int) $t['id']);
            $names = $this->lineNames((string) $t['source_tenant_code'], array_map(static fn (array $l): int => (int) $l['source_variant_id'], $lines));
            $out['lines'] = array_map(static fn (array $l): array => ['line' => (int) $l['line_no'], 'sourceVariantId' => (int) $l['source_variant_id'], 'targetVariantId' => $l['target_variant_id'] === null ? null : (int) $l['target_variant_id'],
                'quantity' => (int) $l['quantity']] + ($names[(int) $l['source_variant_id']] ?? ['product' => null, 'variant' => null])
                + ($withCost ? ['unitCostCents' => $l['unit_cost_cents'] === null ? null : (int) $l['unit_cost_cents']] : []), $lines);
        }

        return $out;
    }

    /** Lot G-5c : le nom du site (base maître, boutiques du groupe), le code pour une boutique sortie du groupe ou sans nom */
    private function siteName(int $groupId, string $code): string
    {
        if (!isset($this->siteNames[$groupId])) {
            $this->siteNames[$groupId] = [];
            foreach ($this->groups->shopDatabases($groupId) as $row) {
                $this->siteNames[$groupId][(string) $row['tenant_code']] = (string) ($row['name'] ?? '');
            }
        }
        $name = $this->siteNames[$groupId][$code] ?? '';

        return $name !== '' ? $name : $code;
    }

    /**
     * Lot G-5c (20/11/2026, demande de MAUI par le chef) : le nom de l'article et la variante (taille, couleur) de chaque ligne, lus dans la base de
     * la SOURCE (l'administrateur voit ce qu'il envoie ; même forme que les lignes de commande). Lecture seule et bornée ; une base injoignable ou
     * une variante supprimée donnent `null`, jamais une panne de la liste. Aucun coût.
     *
     * @param list<int> $variantIds
     * @return array<int, array{product: array{id: int, name: string}, variant: array{id: int, size: ?string, color: ?string}}>
     */
    private function lineNames(string $source, array $variantIds): array
    {
        $ids = array_values(array_unique(array_filter($variantIds, static fn (int $id): bool => $id > 0)));
        $db = $ids === [] ? null : ($this->databases->tenant($source)['dbname'] ?? null);
        if ($db === null) {
            return [];
        }
        try {
            $pdo = $this->databases->pdo((string) $db);
            $pdo->beginTransaction();
            try {
                $pdo->exec('SET TRANSACTION READ ONLY');
                $pdo->exec('SET LOCAL statement_timeout = ' . self::SEARCH_TIMEOUT_MS);
                $stmt = $pdo->prepare('SELECT v.id AS variant_id, p.id AS product_id, p.name, s.name AS size, c.name AS color FROM product_variant v JOIN product p ON p.id = v.product_id
                    LEFT JOIN size s ON s.id = v.size_id LEFT JOIN color c ON c.id = v.color_id WHERE v.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $stmt->execute($ids);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } finally {
                $pdo->inTransaction() && $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[groupe] noms des lignes d\'un transfert non lus', ['source' => $source, 'error' => $e::class]);

            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['variant_id']] = ['product' => ['id' => (int) $r['product_id'], 'name' => (string) $r['name']],
                'variant' => ['id' => (int) $r['variant_id'], 'size' => $r['size'] !== null ? (string) $r['size'] : null, 'color' => $r['color'] !== null ? (string) $r['color'] : null]];
        }

        return $out;
    }

    /** @return array{0: string, 1: ?string} */
    private function home(): array
    {
        return [(string) ($this->emProvider->getEntityManager()->getConnection()->getParams()['dbname'] ?? ''), $this->tenant->getTenantCode()];
    }

    /** @param array{0: string, 1: ?string} $home */
    private function restore(array $home): void
    {
        $this->emProvider->switchTenant($home[0], $home[1]);
    }

    /** @param array<string, mixed> $transfer */
    private static function attemptKey(array $transfer): string
    {
        return (string) $transfer['uid'] . '#' . max(1, (int) $transfer['attempt']);
    }

    private static function who(User $user): string
    {
        return mb_substr(trim((string) $user->getFirstname()) ?: 'administrateur', 0, 100);
    }
}
