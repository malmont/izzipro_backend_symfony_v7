<?php

namespace App\Services\ArchiveService;

use App\Entity\Entreprise;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\StoreClock;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\RetentionService\RetentionPolicy;
use App\Services\TenantEntityManagerProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Archive annuelle des données de caisse (lot A1, 30/10/2026), par site et par exercice (année civile, fuseau du magasin), au format
 * ouvert : un ZIP avec archive.json (rapports Z complets, journal chaîné de l'année avec vérification, tickets et avoirs avec lignes,
 * taxes, paiements, retours, cartes-cadeaux et avoirs émis), archive.pdf (synthèse : un tableau des rapports Z et des pièces, Dompdf)
 * et SHA256SUMS. Produite EN FLUX : les commandes sont parcourues par lots de 200 et écrites au fil de l'eau dans un fichier
 * temporaire, jamais chargées en mémoire d'un bloc. Journal ARCHIVE_EXPORTED (qui, exercice, empreinte, taille).
 */
class PosArchiveService
{
    private const BATCH = 200;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock, private readonly RetentionPolicy $retention, private readonly PosAuditService $audit, private readonly Environment $twig, private readonly string $projectDir)
    {
    }

    /**
     * @return array{path: string, filename: string, sha256: string, size: int, year: int, counts: array<string, int>, chainValid: bool}
     */
    public function build(User $actor, mixed $yearRaw): array
    {
        if (!PosApprovalService::isManager($actor)) {
            throw new CashRegisterException('FORBIDDEN', 'Archive réservée au responsable de caisse', 403);
        }
        $year = is_numeric($yearRaw) ? (int) $yearRaw : (int) (new \DateTimeImmutable('now', $this->clock->timezone()))->format('Y');
        if ($year < 2000 || $year > 2100) {
            throw new CashRegisterException('VALIDATION_FAILED', 'year : année sur quatre chiffres attendue', 422, [['path' => 'year', 'message' => 'année invalide']]);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $tz = $this->clock->timezone();
        $start = (new \DateTimeImmutable("$year-01-01 00:00:00", $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable(($year + 1) . '-01-01 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $dir = $this->projectDir . '/var/archives';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stamp = (new \DateTimeImmutable())->format('Ymd-His');
        $jsonPath = tempnam($dir, 'archive-json-');
        $counts = ['zReports' => 0, 'journal' => 0, 'orders' => 0, 'receipts' => 0, 'returns' => 0, 'giftCards' => 0];
        $json = fopen($jsonPath, 'w');
        fwrite($json, json_encode(['format' => 'izzipro-pos-archive', 'version' => 1, 'site' => ['name' => $entreprise?->getName(), 'currency' => $entreprise?->getCurrency(), 'timezone' => $tz->getName()], 'year' => $year,
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'retention' => $this->retention->settings(), 'rule' => 'Exercice = année civile dans le fuseau du magasin ; rapports Z par date d\'ouverture de session ; commandes et journal par date.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fseek($json, -1, SEEK_END); // remplacer l'accolade finale par les sections
        // 1. Rapports Z (sessions ouvertes dans l'année)
        fwrite($json, ',"zReports":[');
        $first = true;
        $zRows = [];
        foreach ($conn->iterateAssociative('SELECT z.id, z.number, z.cash_register_id, z.caisse_id, z.closed_at, z.closed_by_id, z.counted_cash, z.expected_cash, z.discrepancy, z.discrepancy_reason, z.kept_fund, z.bank_deposit, z.data, c.created_at AS opened_at FROM cash_register_report z JOIN caisse c ON c.id = z.caisse_id WHERE c.created_at >= ? AND c.created_at < ? ORDER BY c.created_at, z.id', [$start, $end]) as $z) {
            $z['data'] = json_decode($z['data'], true);
            $z['openedDay'] = $this->clock->day(new \DateTimeImmutable($z['opened_at']));
            fwrite($json, ($first ? '' : ',') . json_encode($z, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $first = false;
            $counts['zReports']++;
            $zRows[] = ['piece' => sprintf('Z-%d-%d', $z['cash_register_id'], $z['number']), 'day' => $z['openedDay'], 'closedAt' => $z['closed_at'], 'sales' => $z['data']['sales'] ?? null, 'refunds' => $z['data']['refunds'] ?? null, 'expectedCash' => $z['expected_cash'], 'countedCash' => $z['counted_cash'], 'discrepancy' => $z['discrepancy']];
        }
        // 2. Journal chaîné de l'année, vérifié ligne à ligne (hash = sha256(prev_hash + payload))
        fwrite($json, '],"journal":{"entries":[');
        $first = true;
        $chainValid = true;
        $chain = ['firstSequence' => null, 'lastSequence' => null, 'firstPrevHash' => null, 'lastHash' => null];
        foreach ($conn->iterateAssociative('SELECT id, sequence, type, occurred_at, user_id, cash_register_id, session_id, terminal_id, payload, prev_hash, hash FROM pos_audit_log WHERE occurred_at >= ? AND occurred_at < ? ORDER BY sequence', [$start, $end]) as $row) {
            $ok = hash_equals((string) $row['hash'], hash('sha256', ($row['prev_hash'] ?? '') . $row['payload']));
            $chainValid = $chainValid && $ok;
            $row['payload'] = json_decode($row['payload'], true);
            $row['hashValid'] = $ok;
            fwrite($json, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $first = false;
            $counts['journal']++;
            $chain['firstSequence'] ??= (int) $row['sequence'];
            $chain['firstPrevHash'] ??= $row['prev_hash'];
            $chain['lastSequence'] = (int) $row['sequence'];
            $chain['lastHash'] = $row['hash'];
        }
        fwrite($json, '],"chain":' . json_encode($chain + ['valid' => $chainValid]) . '}');
        // 3. Commandes de caisse de l'année (ventes, retours, cartes-cadeaux) avec lignes, taxes, paiements, tickets, retours, par lots
        fwrite($json, ',"orders":[');
        $first = true;
        $lastId = 0;
        while (true) {
            $orders = $conn->fetchAllAssociative('SELECT o.id, o.reference, o.order_date, o.synced_at, o.order_type_id, o.status_id, o.user_id_id AS seller_id, o.customer_id, o.sub_total, o.total_tax, o.total_amount, o.cash_rounding, o.credit_applied, o.gift_card_applied, o.discount_amount, o.discount_reason_code, o.discount_reason_text
                FROM "order" o WHERE o.order_source_id = 2 AND o.order_date >= ? AND o.order_date < ? AND o.id > ? ORDER BY o.id LIMIT ' . self::BATCH, [$start, $end, $lastId]);
            if ($orders === []) {
                break;
            }
            $ids = array_map(fn ($o) => (int) $o['id'], $orders);
            $in = implode(',', $ids);
            $items = $this->groupBy($conn->fetchAllAssociative("SELECT order_associated_id AS oid, id, product_variant_id, product_name, variant_label, product_code, barcode, custom_label, taxable, quantity, unit_price, total_price, discount_amount, discounts, catalog_unit_price, price_reason_code, price_reason_text, returned_from_item_id FROM order_items WHERE order_associated_id IN ($in) ORDER BY id"), 'oid');
            $taxes = $this->groupBy($conn->fetchAllAssociative("SELECT ot.order_tax_id AS oid, t.name AS label, t.rate, ot.amount FROM order_tax ot JOIN tax t ON t.id = ot.tax_id WHERE ot.order_tax_id IN ($in) ORDER BY ot.id"), 'oid');
            $payments = $this->groupBy($conn->fetchAllAssociative("SELECT order_payment_id AS oid, id, payment_method_id, payment_type_id, amount, payment_date, stripe_payment_id, stripe_status, stripe_card_brand, stripe_last4 FROM payments WHERE order_payment_id IN ($in) ORDER BY id"), 'oid');
            $receipts = $this->groupBy($conn->fetchAllAssociative("SELECT order_id AS oid, kind, number, cash_register_id, caisse_id, amount, created_at, reprint_count, provisional_number FROM pos_receipt WHERE order_id IN ($in) ORDER BY id"), 'oid');
            $returns = $this->groupBy($conn->fetchAllAssociative("SELECT return_order_id AS oid, id, order_id, exchange_order_id, cash_register_id, caisse_id, user_id, reason_code, reason_text, restock, refund_method, amount, cash_refund, card_refund, gift_card_refund, store_credit_id, cash_rounding, credit_applied, terminal_payment_id, terminal_refund_id, created_at FROM pos_return WHERE return_order_id IN ($in)"), 'oid');
            $giftTx = $this->groupBy($conn->fetchAllAssociative("SELECT tx.order_id AS oid, tx.kind, tx.amount, tx.balance_after, c.kind AS card_kind, c.last4, c.id AS gift_card_id FROM pos_gift_card_transaction tx JOIN pos_gift_card c ON c.id = tx.gift_card_id WHERE tx.order_id IN ($in) ORDER BY tx.id"), 'oid');
            foreach ($orders as $o) {
                $id = (int) $o['id'];
                $o['items'] = array_map(fn ($i) => ['discounts' => isset($i['discounts']) ? json_decode($i['discounts'], true) : null] + $i, $items[$id] ?? []);
                $o['taxes'] = $taxes[$id] ?? [];
                $o['payments'] = $payments[$id] ?? [];
                $o['receipts'] = $receipts[$id] ?? [];
                $o['return'] = $returns[$id][0] ?? null;
                $o['giftCardMovements'] = $giftTx[$id] ?? [];
                fwrite($json, ($first ? '' : ',') . json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $first = false;
                $counts['orders']++;
                $counts['receipts'] += count($o['receipts']);
                $counts['returns'] += $o['return'] ? 1 : 0;
                $lastId = $id;
            }
        }
        // 4. Cartes-cadeaux et avoirs émis dans l'année (empreinte seule : le code n'est jamais archivé)
        fwrite($json, '],"giftCards":[');
        $first = true;
        foreach ($conn->iterateAssociative('SELECT id, kind, last4, initial_amount, balance, status, expires_at, issued_order_id, issued_return_id, cash_register_id, customer_id, created_at, canceled_at, replaces_id, replaced_by_id FROM pos_gift_card WHERE created_at >= ? AND created_at < ? ORDER BY id', [$start, $end]) as $g) {
            fwrite($json, ($first ? '' : ',') . json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $first = false;
            $counts['giftCards']++;
        }
        fwrite($json, '],"counts":' . json_encode($counts) . '}');
        fclose($json);
        // PDF de synthèse (rapports Z et pièces), empreintes, ZIP
        $pdfPath = tempnam($dir, 'archive-pdf-');
        file_put_contents($pdfPath, $this->pdf(['site' => $entreprise?->getName(), 'year' => $year, 'timezone' => $tz->getName(), 'counts' => $counts, 'zReports' => $zRows, 'chainValid' => $chainValid, 'generatedAt' => (new \DateTimeImmutable())->format('d/m/Y H:i'), 'retention' => $this->retention->settings()]));
        $jsonSum = hash_file('sha256', $jsonPath);
        $pdfSum = hash_file('sha256', $pdfPath);
        $filename = sprintf('archive-caisse_%s_%d_%s.zip', preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($entreprise?->getName() ?? 'site'))), $year, $stamp);
        $zipPath = $dir . '/' . $filename;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new CashRegisterException('ARCHIVE_FAILED', 'Impossible de créer l\'archive', 500);
        }
        $zip->addFile($jsonPath, 'archive.json');
        $zip->addFile($pdfPath, 'archive.pdf');
        $zip->addFromString('SHA256SUMS', "$jsonSum  archive.json\n$pdfSum  archive.pdf\n");
        $zip->addFromString('README.txt', "Archive des données de caisse $year (format ouvert : JSON + PDF). Vérification : sha256sum -c SHA256SUMS. Le code des cartes-cadeaux n'est jamais archivé (empreinte seule). Conservation légale : voir retention dans archive.json.\n");
        $zip->close();
        unlink($jsonPath);
        unlink($pdfPath);
        $sha = hash_file('sha256', $zipPath);
        $size = filesize($zipPath) ?: 0;
        $this->audit->record(PosAuditService::ARCHIVE_EXPORTED, ['year' => $year, 'filename' => $filename, 'sha256' => $sha, 'size' => $size, 'counts' => $counts, 'chainValid' => $chainValid], subjectType: 'archive', user: $actor);

        return ['path' => $zipPath, 'filename' => $filename, 'sha256' => $sha, 'size' => $size, 'year' => $year, 'counts' => $counts, 'chainValid' => $chainValid];
    }

    private function pdf(array $vars): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('pos/archive_summary_pdf.html.twig', $vars));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return array<int, list<array>> */
    private function groupBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (int) $r[$key];
            unset($r[$key]);
            $out[$k][] = $r;
        }

        return $out;
    }
}
