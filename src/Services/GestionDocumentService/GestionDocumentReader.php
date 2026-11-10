<?php

namespace App\Services\GestionDocumentService;

use App\Entity\AiUsage;
use App\Entity\GestionDocument;
use App\Repository\GestionDocumentRepository;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Lecture d'un document par IA (lot L1, 10/11/2026, `docs/lecture-documents-gestion.md`) dans le worker `gestion_ai` : les pages
 * (images ou PDF, lus nativement par Claude) partent au modèle avec une sortie structurée (schéma JSON) par type ; le backend fait ensuite tout
 * ce qui est chiffre ou catalogue : montants ramenés hors taxes (`amountsMode`), totaux recalculés et écart, correspondances
 * (`GestionDocumentMatcher`), regroupement taille / couleur (`groupKey`), doublon de numéro de facture. Un crédit réservé à l'envoi
 * est facturé en succès et libéré en échec (`failed` + `errorCode`, sans crédit retenu).
 */
class GestionDocumentReader
{
    public const MODEL = 'claude-sonnet-5-5';
    private const TIMEOUT = 180.0;
    private const MAX_IMAGE_EDGE = 2400; // pixels, côté le plus long d'une page envoyée au modèle
    private const MAX_IMAGE_SEND_BYTES = 1_500_000;
    /** Remarques ajoutées par le serveur, lues telles quelles par le commerçant (français simple, aucun code) */
    public const NOTE_AMOUNTS_UNKNOWN = 'Le document ne dit pas si les prix sont avec ou sans taxes : ils sont pris sans taxes, à vérifier.';
    public const NOTE_AMOUNT_UNKNOWN = 'Le document ne dit pas si le montant est avec ou sans taxes : il est pris sans taxes, à vérifier.';
    public const DEFAULT_TAX_RATE = 0.14975; // TPS + TVQ, quand ni le document ni le site ne donnent de taux

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingAiClientInterface $client,
        private readonly LandingAiQuotaService $quota,
        private readonly GestionDocumentStorage $storage,
        private readonly GestionDocumentMatcher $matcher,
        private readonly LoggerInterface $logger,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    public function read(int $documentId): void
    {
        $em = $this->emProvider->getEntityManager();
        /** @var GestionDocumentRepository $repo */
        $repo = $em->getRepository(GestionDocument::class);
        $document = $repo->find($documentId);
        if ($document === null || $document->getStatus() !== GestionDocument::STATUS_READING) {
            return;
        }
        $usage = $document->getAiUsageId() !== null ? $em->find(AiUsage::class, $document->getAiUsageId()) : null;
        $stats = new LandingAiUsageStats(self::MODEL);
        try {
            $raw = $this->ask($document, $stats);
            $extraction = match ($document->getType()) {
                GestionDocument::TYPE_FACTURE => $this->invoice($document, $raw),
                GestionDocument::TYPE_TRANSPORT => $this->transport($document, $raw),
                default => $this->note($raw),
            };
            $document->setExtraction($extraction)->setStatus(GestionDocument::STATUS_EXTRACTED)->setErrorCode(null)->setReadAt(new \DateTimeImmutable());
            $em->flush();
            if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->complete($usage, $stats, mb_substr(sprintf('%s %s', $document->getType(), $extraction['invoice']['number'] ?? $extraction['merchant'] ?? ''), 0, 200));
            }
        } catch (\Throwable $e) {
            $code = $e instanceof LandingAiException ? match ($e->getStatusCode()) { 429 => 'AI_TOO_MANY_FAILURES', 422 => 'DOCUMENT_UNREADABLE', default => 'AI_UNAVAILABLE' } : 'AI_UNAVAILABLE';
            $this->logger->error(sprintf('[Lecture de document %d] %s', $documentId, $e->getMessage()));
            $document->setStatus(GestionDocument::STATUS_FAILED)->setErrorCode($code)->setReadAt(new \DateTimeImmutable());
            $em->flush();
            if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($usage, $stats);
            }
        }
    }

    // ----- appel au modèle -----

    /** @return array<string, mixed> entrée brute de l'outil */
    private function ask(GestionDocument $document, LandingAiUsageStats $stats): array
    {
        $content = [];
        foreach ($document->getFiles()['pages'] ?? [] as $page) {
            $data = $this->storage->read($document, $page['file']);
            if ($data === null) {
                throw new LandingAiException(422, 'Fichier absent', 'Page ' . $page['n'] . ' introuvable.');
            }
            $content[] = ['type' => 'text', 'text' => sprintf('Page %d :', $page['n'])];
            [$data, $mime] = $page['mime'] === 'application/pdf' ? [$data, $page['mime']] : $this->reducedImage($data, $page['mime']);
            $content[] = [
                'type' => $mime === 'application/pdf' ? 'document' : 'image',
                'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)],
            ];
        }
        $language = $document->getLocale() === 'en' ? 'anglais' : 'français';
        $content[] = ['type' => 'text', 'text' => $this->instruction($document->getType(), $language)];
        // Sortie structurée (output_config.format, schéma JSON) : depuis Sonnet 5.5 l'API refuse l'outil imposé (tool_choice « tool » : HTTP 400,
        // constaté en production le 10/11/2026) ; la réponse est un bloc texte JSON conforme au schéma
        $response = $this->client->createMessage([
            'model' => self::MODEL, 'max_tokens' => 8000, 'system' => $this->system($document->getType()),
            'messages' => [['role' => 'user', 'content' => $content]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema($document->getType())]],
        ], self::TIMEOUT);
        $stats->attempts++;
        $stats->add($response->usage ?? null);
        $stop = (string) ($response->stop_reason ?? '');
        if ($stop === 'refusal' || $stop === 'max_tokens') {
            throw new LandingAiException(422, 'Document illisible', 'Lecture interrompue (' . $stop . ').');
        }
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (($block->type ?? null) === 'text') {
                $input = json_decode((string) ($block->text ?? ''), true);
                if (is_array($input) && $input !== []) {
                    return $input;
                }
            }
        }
        throw new LandingAiException(422, 'Document illisible', 'Le modèle n\'a rien pu lire sur ce document.');
    }

    /**
     * Photo réduite pour l'envoi au modèle (2400 px de côté, JPEG) : six photos de téléphone dépasseraient sinon la taille de requête de l'API.
     * Le fichier d'origine reste intact (justificatif). WebP ou image illisible par GD : envoyée telle quelle.
     *
     * @return array{0: string, 1: string} contenu et type MIME
     */
    private function reducedImage(string $data, string $mime): array
    {
        $size = @getimagesizefromstring($data);
        if ($size === false || !in_array($mime, ['image/jpeg', 'image/png'], true) || (strlen($data) <= self::MAX_IMAGE_SEND_BYTES && max($size[0], $size[1]) <= self::MAX_IMAGE_EDGE)) {
            return [$data, $mime];
        }
        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return [$data, $mime];
        }
        $source = $this->orient($source, $data, $mime);
        [$width, $height] = [imagesx($source), imagesy($source)];
        $ratio = min(1.0, self::MAX_IMAGE_EDGE / max($width, $height));
        [$w, $h] = [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
        $target = imagecreatetruecolor($w, $h);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255)); // fond blanc : la transparence d'un PNG serait noire en JPEG
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        imagejpeg($target, null, 85);

        return [(string) ob_get_clean(), 'image/jpeg'];
    }

    /** L'orientation EXIF d'une photo de téléphone est perdue au réencodage : elle est appliquée ici */
    private function orient(\GdImage $image, string $data, string $mime): \GdImage
    {
        $angle = $mime === 'image/jpeg' ? match (self::exifOrientation($data)) { 3 => 180, 6 => -90, 8 => 90, default => 0 } : 0;

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    /** Orientation EXIF d'un JPEG, lue directement (l'extension exif n'est pas installée) ; 1 si absente ou illisible */
    public static function exifOrientation(string $jpeg): int
    {
        $length = strlen($jpeg);
        for ($pos = 2; $pos + 4 <= $length && $jpeg[$pos] === "\xFF";) {
            $marker = ord($jpeg[$pos + 1]);
            $size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($marker === 0xDA) { // début des données d'image : plus d'en-tête
                break;
            }
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, $size - 8);
                $little = substr($tiff, 0, 2) === 'II';
                if (strlen($tiff) < 8 || (!$little && substr($tiff, 0, 2) !== 'MM')) {
                    return 1;
                }
                $short = fn (int $at) => $at + 2 <= strlen($tiff) ? unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] : 0;
                $ifd = unpack($little ? 'V' : 'N', substr($tiff, 4, 4))[1];
                for ($i = 0, $n = $short($ifd); $i < $n; $i++) {
                    $entry = $ifd + 2 + 12 * $i;
                    if ($short($entry) === 0x0112) {
                        return $short($entry + 8) ?: 1;
                    }
                }

                return 1;
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private function system(string $type): string
    {
        return "Tu lis des documents comptables d'un commerce (photos ou PDF) et tu rends fidèlement ce qui est écrit, sans rien inventer ni calculer : "
            . "quand une valeur est absente ou illisible, rends null (chaîne vide pour un texte). Le champ notes est lu tel quel par le commerçant : n'y mets que ce qu'il doit vérifier (valeur illisible, devise ou date supposée, ligne douteuse, total qui ne tombe pas juste), en phrases courtes de français simple, sans aucun nom de champ ni code (jamais amountsMode, HT, TTC ni JSON), et rien d'évident (nombre de pages, ce qui est bien lu), ni l'absence d'un renseignement que le document n'a pas à porter (date d'échéance, total des taxes quand chaque taxe est écrite, code article, numéro de suivi) : il n'y a rien à vérifier. Exemple : « La devise n'est pas écrite sur le reçu : dollars canadiens supposés. » Liste vide si tout est clair. Montants en cents entiers (12,50 → 1250), tels qu'ils sont écrits "
            . "(précise dans amountsMode si les lignes sont hors taxes « ht », taxes incluses « ttc », ou « unknown »). Dates au format AAAA-MM-JJ. "
            . "Ne fais aucune correspondance avec un catalogue : le serveur s'en charge. Aucune donnée personnelle au-delà du contenu du document. "
            . 'Type de document attendu : ' . match ($type) { GestionDocument::TYPE_FACTURE => 'facture ou bon de commande d\'un fournisseur (articles achetés par le commerce).', GestionDocument::TYPE_TRANSPORT => 'facture de transport ou de livraison (frais de port).', default => 'reçu ou facture de dépense (note de frais).' };
    }

    private function instruction(string $type, string $language): string
    {
        return match ($type) {
            GestionDocument::TYPE_FACTURE => "Lis cette facture fournisseur (toutes les pages) et rends chaque ligne d'article : baseLabel = désignation sans la taille ni la couleur, size et color séparés quand ils sont écrits, quantité, coût unitaire, total de ligne, code article du fournisseur s'il y en a un. Rends aussi le fournisseur, le numéro, la date, la devise (code ISO), les taxes (libellé, taux, montant), les frais de port s'ils sont facturés, le sous-total, le total des taxes et le total. Libellés en $language quand tu reformules.",
            GestionDocument::TYPE_TRANSPORT => "Lis cette facture de transport (toutes les pages) : transporteur, numéro de facture, date, devise, numéro de suivi, montant hors taxes, taxes, total. Libellés en $language.",
            default => "Lis ce reçu de dépense (toutes les pages) : commerçant, date, montant total payé (taxes comprises), taxes détaillées, nature de la dépense (déplacement, repas, hébergement, fournitures, autre) et une description courte en $language.",
        };
    }

    /**
     * Schéma JSON de la réponse par type (sortie structurée de l'API) : chaque objet porte additionalProperties: false, les valeurs facultatives
     * sont des anyOf [type, null], aucune contrainte numérique (non prises en charge) : les bornes sont appliquées au post-traitement.
     * L'API refuse plus de 16 paramètres à union de types par schéma (HTTP 400, incident du 11/11/2026 sur la facture, 26 unions) : les textes
     * sont des chaînes simples (vide = inconnu, que `short()`, `date()` et `currencyCode()` lisent comme absent), `taxable` un booléen
     * (vrai par défaut), les frais de port un objet toujours présent (montant null = aucun). Seuls montants, taux, quantités et confiances
     * restent nullables. Garde : `MAX_UNION_PARAMETERS` et le test `GestionDocumentsApiTest`.
     *
     * @return array<string, mixed>
     */
    public function schema(string $type): array
    {
        $nullable = fn (string $t): array => ['anyOf' => [['type' => $t], ['type' => 'null']]];
        $object = fn (array $properties, array $required = []): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $money = $nullable('integer');
        $str = ['type' => 'string'];
        $taxes = ['type' => 'array', 'items' => $object(['label' => $str, 'rate' => $nullable('number'), 'amount' => $money], ['label', 'rate', 'amount'])];
        $confidence = fn (array $keys): array => $object(array_fill_keys(array_merge(['overall'], $keys), $nullable('number')), array_merge(['overall'], $keys));
        $notes = ['type' => 'array', 'items' => ['type' => 'string']];
        $amountsMode = ['type' => 'string', 'enum' => ['ht', 'ttc', 'unknown']];

        return match ($type) {
            GestionDocument::TYPE_FACTURE => $object([
                'supplierName' => $str, 'invoiceNumber' => $str, 'invoiceDate' => $str, 'dueDate' => $str, 'currency' => $str, 'amountsMode' => $amountsMode,
                'lines' => ['type' => 'array', 'items' => $object([
                    'baseLabel' => ['type' => 'string'], 'size' => $str, 'color' => $str, 'supplierCode' => $str, 'quantity' => $nullable('number'), 'unitCost' => $money, 'total' => $money, 'taxable' => ['type' => 'boolean'],
                ], ['baseLabel', 'size', 'color', 'supplierCode', 'quantity', 'unitCost', 'total', 'taxable'])],
                'taxes' => $taxes, 'shipping' => $object(['label' => $str, 'amount' => $money], ['label', 'amount']),
                'subtotal' => $money, 'taxTotal' => $money, 'total' => $money,
                'confidence' => $confidence(['number', 'date', 'lines', 'totals']), 'notes' => $notes,
            ], ['supplierName', 'invoiceNumber', 'invoiceDate', 'dueDate', 'currency', 'amountsMode', 'lines', 'taxes', 'shipping', 'subtotal', 'taxTotal', 'total', 'confidence', 'notes']),
            GestionDocument::TYPE_TRANSPORT => $object([
                'carrierName' => $str, 'invoiceNumber' => $str, 'invoiceDate' => $str, 'currency' => $str, 'trackingNumber' => $str, 'amountsMode' => $amountsMode,
                'amount' => $money, 'taxes' => $taxes, 'subtotal' => $money, 'taxTotal' => $money, 'total' => $money,
                'confidence' => $confidence(['number', 'date', 'amount']), 'notes' => $notes,
            ], ['carrierName', 'invoiceNumber', 'invoiceDate', 'currency', 'trackingNumber', 'amountsMode', 'amount', 'taxes', 'subtotal', 'taxTotal', 'total', 'confidence', 'notes']),
            default => $object([
                'merchant' => $str, 'date' => $str, 'currency' => $str, 'amount' => $money, 'taxes' => $taxes, 'kind' => $str, 'description' => $str,
                'confidence' => $confidence(['amount', 'date']), 'notes' => $notes,
            ], ['merchant', 'date', 'currency', 'amount', 'taxes', 'kind', 'description', 'confidence', 'notes']),
        };
    }

    // ----- post-traitement -----

    /** @param array<string, mixed> $raw */
    private function invoice(GestionDocument $document, array $raw): array
    {
        $siteCurrency = $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
        $notes = $this->strings($raw['notes'] ?? null);
        $mode = in_array($raw['amountsMode'] ?? null, ['ht', 'ttc', 'unknown'], true) ? $raw['amountsMode'] : 'unknown';
        $taxes = $this->taxes($raw['taxes'] ?? null);
        $subtotalRead = $this->cents($raw['subtotal'] ?? null);
        $taxTotalRead = $this->cents($raw['taxTotal'] ?? null) ?? ($taxes !== [] ? array_sum(array_column($taxes, 'amount')) : null);
        $totalRead = $this->cents($raw['total'] ?? null);
        $taxRate = $this->taxRate($mode, $taxes, $subtotalRead, $taxTotalRead, $totalRead);
        if ($mode === 'unknown') {
            $notes[] = self::NOTE_AMOUNTS_UNKNOWN;
        }
        $lines = [];
        $linesTotal = 0;
        $groups = [];
        foreach (is_array($raw['lines'] ?? null) ? $raw['lines'] : [] as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $n = count($lines) + 1;
            $baseLabel = mb_substr(trim((string) ($line['baseLabel'] ?? '')), 0, 255) ?: 'Ligne ' . $n;
            $size = $this->short($line['size'] ?? null, 40);
            $color = $this->short($line['color'] ?? null, 40);
            $quantity = isset($line['quantity']) && is_numeric($line['quantity']) && (float) $line['quantity'] > 0 ? (float) $line['quantity'] : 1.0;
            $unitRead = $this->cents($line['unitCost'] ?? null);
            $totalLineRead = $this->cents($line['total'] ?? null);
            if ($unitRead === null && $totalLineRead !== null) {
                $unitRead = (int) round($totalLineRead / $quantity);
            }
            if ($totalLineRead === null && $unitRead !== null) {
                $totalLineRead = (int) round($unitRead * $quantity);
            }
            $taxable = ($line['taxable'] ?? true) !== false;
            $factor = ($mode === 'ttc' && $taxable && $taxRate > 0) ? 1 / (1 + $taxRate) : 1.0;
            $unitCost = $unitRead !== null ? (int) round($unitRead * $factor) : null;
            $total = $unitCost !== null ? (int) round($unitCost * $quantity) : null;
            $linesTotal += $total ?? 0;
            $match = $this->matcher->product($baseLabel, $this->short($line['supplierCode'] ?? null, 60), $size, $color);
            $groupKey = GestionDocumentMatcher::groupKey($baseLabel);
            $groups[$groupKey] = ($groups[$groupKey] ?? 0) + 1;
            $lines[] = [
                'n' => $n, 'label' => trim($baseLabel . ($size !== null ? ' ' . $size : '') . ($color !== null ? ' ' . $color : '')), 'baseLabel' => $baseLabel, 'size' => $size, 'color' => $color,
                'supplierCode' => $this->short($line['supplierCode'] ?? null, 60), 'quantity' => $quantity == floor($quantity) ? (int) $quantity : $quantity,
                'unitCost' => $unitCost, 'total' => $total, 'unitCostRead' => $unitRead, 'totalRead' => $totalLineRead, 'taxable' => $taxable,
                'groupKey' => $groupKey, 'product' => $match['product'], 'candidates' => $match['candidates'],
            ];
        }
        foreach ($lines as &$line) {
            $line['groupSize'] = $groups[$line['groupKey']] ?? 1; // > 1 : l'application propose un seul article avec variantes
        }
        unset($line);
        $shippingRead = is_array($raw['shipping'] ?? null) ? $this->cents($raw['shipping']['amount'] ?? null) : null;
        $shipping = $shippingRead !== null ? ['label' => $this->short($raw['shipping']['label'] ?? null, 120) ?? 'Frais de port', 'amount' => (int) round($shippingRead * (($mode === 'ttc' && $taxRate > 0) ? 1 / (1 + $taxRate) : 1)), 'amountRead' => $shippingRead] : null;
        $computedTotal = $linesTotal + ($shipping['amount'] ?? 0) + (int) round(($linesTotal + ($shipping['amount'] ?? 0)) * ($taxRate > 0 && $mode !== 'unknown' ? $taxRate : 0));
        $supplier = $this->matcher->party('fournisseur', $this->short($raw['supplierName'] ?? null, 255));
        $number = $this->short($raw['invoiceNumber'] ?? null, 80);
        $currency = $this->currencyCode($raw['currency'] ?? null);
        $duplicate = $supplier['id'] !== null && $number !== null ? $this->duplicate(GestionDocument::TYPE_FACTURE, 'supplier', $supplier['id'], $number, $document) : null;
        $confidence = $this->confidence($raw['confidence'] ?? null, ['number', 'date', 'lines', 'totals']);
        if ($mode === 'unknown' && $confidence['totals'] !== null) {
            $confidence['totals'] = round(min($confidence['totals'], 0.5), 2);
        }

        return [
            'supplier' => ['name' => $this->short($raw['supplierName'] ?? null, 255), 'id' => $supplier['id'], 'candidates' => $supplier['candidates'], 'currency' => $currency],
            'invoice' => ['number' => $number, 'date' => $this->date($raw['invoiceDate'] ?? null), 'dueDate' => $this->date($raw['dueDate'] ?? null), 'currency' => $currency, 'exchangeRate' => null],
            'amountsMode' => $mode, 'currency' => $siteCurrency,
            'lines' => $lines, 'taxes' => $taxes, 'shipping' => $shipping,
            'totals' => ['subtotal' => $subtotalRead, 'taxes' => $taxTotalRead, 'total' => $totalRead],
            'computed' => ['linesTotal' => $linesTotal, 'shipping' => $shipping['amount'] ?? 0, 'taxRate' => round($taxRate, 5), 'total' => $computedTotal, 'difference' => $totalRead !== null ? $totalRead - $computedTotal : null],
            'confidence' => $confidence, 'notes' => $notes, 'duplicate' => $duplicate,
        ];
    }

    /** @param array<string, mixed> $raw */
    private function transport(GestionDocument $document, array $raw): array
    {
        $notes = $this->strings($raw['notes'] ?? null);
        $mode = in_array($raw['amountsMode'] ?? null, ['ht', 'ttc', 'unknown'], true) ? $raw['amountsMode'] : 'unknown';
        $taxes = $this->taxes($raw['taxes'] ?? null);
        $subtotalRead = $this->cents($raw['subtotal'] ?? null) ?? $this->cents($raw['amount'] ?? null);
        $taxTotalRead = $this->cents($raw['taxTotal'] ?? null) ?? ($taxes !== [] ? array_sum(array_column($taxes, 'amount')) : null);
        $totalRead = $this->cents($raw['total'] ?? null);
        $taxRate = $this->taxRate($mode, $taxes, $subtotalRead, $taxTotalRead, $totalRead);
        $amountRead = $this->cents($raw['amount'] ?? null) ?? $subtotalRead;
        $amount = $amountRead !== null ? (int) round($amountRead * (($mode === 'ttc' && $taxRate > 0) ? 1 / (1 + $taxRate) : 1)) : null;
        if ($mode === 'unknown') {
            $notes[] = self::NOTE_AMOUNT_UNKNOWN;
        }
        $carrier = $this->matcher->party('transporteur', $this->short($raw['carrierName'] ?? null, 255));
        $number = $this->short($raw['invoiceNumber'] ?? null, 80);
        $tracking = $this->short($raw['trackingNumber'] ?? null, 120);
        $date = $this->date($raw['invoiceDate'] ?? null);
        $computedTotal = $amount !== null ? $amount + (int) round($amount * ($taxRate > 0 && $mode !== 'unknown' ? $taxRate : 0)) : null;

        return [
            'carrier' => ['name' => $this->short($raw['carrierName'] ?? null, 255), 'id' => $carrier['id'], 'candidates' => $carrier['candidates']],
            'invoice' => ['number' => $number, 'date' => $date, 'currency' => $this->currencyCode($raw['currency'] ?? null)],
            'trackingNumber' => $tracking, 'amount' => $amount, 'amountRead' => $amountRead, 'amountsMode' => $mode, 'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'taxes' => $taxes, 'totals' => ['subtotal' => $subtotalRead, 'taxes' => $taxTotalRead, 'total' => $totalRead],
            'computed' => ['taxRate' => round($taxRate, 5), 'total' => $computedTotal, 'difference' => $totalRead !== null && $computedTotal !== null ? $totalRead - $computedTotal : null],
            'commande' => $this->matcher->commandeFor($tracking, $date),
            'confidence' => $this->confidence($raw['confidence'] ?? null, ['number', 'date', 'amount']), 'notes' => $notes,
            'duplicate' => $carrier['id'] !== null && $number !== null ? $this->duplicate(GestionDocument::TYPE_TRANSPORT, 'carrier', $carrier['id'], $number, $document) : null,
        ];
    }

    /** @param array<string, mixed> $raw */
    private function note(array $raw): array
    {
        $type = $this->matcher->noteType($this->short($raw['kind'] ?? null, 80));

        return [
            'amount' => $this->cents($raw['amount'] ?? null), 'date' => $this->date($raw['date'] ?? null), 'currency' => $this->currencyCode($raw['currency'] ?? null) ?? ($this->currency?->code() ?? TenantCurrencyProvider::DEFAULT),
            'merchant' => $this->short($raw['merchant'] ?? null, 255), 'description' => $this->short($raw['description'] ?? null, 500) ?? $this->short($raw['merchant'] ?? null, 255),
            'taxes' => $this->taxes($raw['taxes'] ?? null), 'kind' => $this->short($raw['kind'] ?? null, 80),
            'typeNoteDeFrais' => $type['id'] !== null ? ['id' => $type['id'], 'name' => $type['name']] : null, 'candidates' => $type['candidates'],
            'confidence' => $this->confidence($raw['confidence'] ?? null, ['amount', 'date']), 'notes' => $this->strings($raw['notes'] ?? null),
        ];
    }

    private function duplicate(string $type, string $partyField, int $partyId, string $number, GestionDocument $document): ?array
    {
        /** @var GestionDocumentRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionDocument::class);
        $other = $repo->findDuplicate($type, $partyField, $partyId, $number, $document->getId());

        return $other === null ? null : ['documentId' => $other->getId(), 'commandeId' => $other->getCommandeId(), 'fraisDePortId' => $other->getFraisDePortId(), 'status' => $other->getStatus()];
    }

    /** Taux de taxe global : montants du document, sinon taux lus, sinon taux par défaut */
    private function taxRate(string $mode, array $taxes, ?int $subtotal, ?int $taxTotal, ?int $total): float
    {
        // Base des taxes = total − taxes quand les deux sont lus : elle inclut la livraison, que le sous-total écrit omet souvent
        // (retour du 11/11/2026 : sous-total 378,00 avant 20,00 de livraison, taux 15,77 % au lieu de 14,975 %, écart faux de 3,15)
        if ($mode !== 'unknown' && $taxTotal !== null && $total && $total > $taxTotal) {
            return max(0.0, $taxTotal / ($total - $taxTotal));
        }
        if ($mode === 'ht' && $taxTotal !== null && $subtotal) {
            return max(0.0, $taxTotal / $subtotal);
        }
        $rates = array_filter(array_column($taxes, 'rate'), fn ($r) => $r !== null && $r > 0);
        if ($rates !== []) {
            return array_sum($rates) / 100;
        }
        if ($taxTotal !== null && $subtotal) {
            return max(0.0, $taxTotal / $subtotal);
        }

        return self::DEFAULT_TAX_RATE;
    }

    /** @return list<array{label: ?string, rate: ?float, amount: ?int}> */
    private function taxes(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $rate = isset($t['rate']) && is_numeric($t['rate']) ? (float) $t['rate'] : null;
            if ($rate !== null && $rate > 0 && $rate < 1) {
                $rate *= 100; // 0,05 → 5 %
            }
            $out[] = ['label' => $this->short($t['label'] ?? null, 60), 'rate' => $rate !== null ? round($rate, 3) : null, 'amount' => $this->cents($t['amount'] ?? null)];
        }

        return $out;
    }

    /** @param list<string> $keys */
    private function confidence(mixed $value, array $keys): array
    {
        $out = ['overall' => null];
        foreach (array_merge(['overall'], $keys) as $k) {
            $v = is_array($value) && isset($value[$k]) && is_numeric($value[$k]) ? (float) $value[$k] : null;
            $out[$k] = $v === null ? null : round(max(0.0, min(1.0, $v)), 2);
        }

        return $out;
    }

    private function cents(mixed $value): ?int
    {
        return $value !== null && is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable(substr($value, 0, 10)))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function currencyCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : null;
    }

    private function short(mixed $value, int $max): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 300) : '', is_array($value) ? $value : []), fn ($v) => $v !== ''));
    }
}
