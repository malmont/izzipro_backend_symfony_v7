<?php

namespace App\Gestion\Services\Conversation;

use App\Entity\Fournisseur;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Gestion\Entity\GestionDocument;
use App\Gestion\Repository\GestionDocumentRepository;
use App\Gestion\Services\Document\GestionDocumentReader;
use App\Gestion\Services\Document\GestionInvoiceDraft;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Questions d'une facture fournisseur menée par conversation (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md` § 3) : le serveur
 * les calcule à partir des trous qu'il connaît (lecture du document, catalogue, calendrier, doublons), jamais une IA. De la lecture et
 * des réponses du gérant il bâtit aussi le corps de la confirmation, celui de `POST /api/gestion/documents/{id}/confirmer`.
 *
 * Une question : `{ id, kind, path, title, text, required, input, options, answer }`. `id` est stable (`supplier`, `invoice.date`,
 * `amountsMode`, `exchangeRate`, `period`, `duplicate`, `difference`, `line:<clé>`, `unitCost:<n>`) ; `path` est le chemin du champ de la
 * confirmation qu'elle renseigne ; `answer` est la réponse donnée, null tant qu'il n'y en a pas. Lecture seule.
 */
class InvoiceQuestions
{
    public const ABANDON = 'abandon';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly TenantCurrencyProvider $currency,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $answers réponses du fil (identifiant de question => valeur)
     *
     * @return array{body: array<string, mixed>, questions: list<array<string, mixed>>}
     */
    public function build(GestionDocument $document, array $answers, string $locale): array
    {
        $em = $this->emProvider->getEntityManager();
        $x = $document->getExtraction() ?? [];
        $site = strtoupper($this->currency->code());
        $questions = [];
        $body = ['lines' => []];

        // Fournisseur : reconnu par la lecture, sinon demandé
        $read = is_array($x['supplier'] ?? null) ? $x['supplier'] : [];
        $supplierAnswer = $answers['supplier'] ?? null;
        $supplierValue = is_array($supplierAnswer) ? $supplierAnswer : (isset($read['id']) ? ['id' => (int) $read['id']] : null);
        if (!isset($read['id']) || $supplierAnswer !== null) {
            $options = [];
            foreach (is_array($read['candidates'] ?? null) ? $read['candidates'] : [] as $candidate) {
                $options[] = ['value' => ['id' => (int) $candidate['id']], 'label' => (string) $candidate['name'], 'score' => $candidate['score'] ?? null];
            }
            $name = trim((string) ($read['name'] ?? ''));
            $options[] = ['value' => ['create' => ['name' => $name]], 'label' => $this->tr('conversation.options.supplier_create', $locale, ['%name%' => $name !== '' ? $name : '…'])];
            $questions[] = $this->question('supplier', 'supplier', 'supplier', true, ['type' => 'choice'], $options, $supplierAnswer, $locale, ['%name%' => $name !== '' ? $name : '?']);
        }
        if ($supplierValue !== null) {
            $body['supplier'] = $supplierValue;
        }
        $supplier = isset($supplierValue['id']) ? $em->find(Fournisseur::class, (int) $supplierValue['id']) : null;

        // Facture : numéro lu, date lue ou demandée
        $invoice = is_array($x['invoice'] ?? null) ? $x['invoice'] : [];
        $number = trim((string) ($invoice['number'] ?? ''));
        $date = self::day($answers['invoice.date'] ?? null) ?? self::day($invoice['date'] ?? null);
        if (self::day($invoice['date'] ?? null) === null || isset($answers['invoice.date'])) {
            $questions[] = $this->question('invoice.date', 'invoice.date', 'invoice.date', true, ['type' => 'date'], [], $answers['invoice.date'] ?? null, $locale);
        }
        $body['invoice'] = array_filter(['number' => $number !== '' ? $number : null, 'date' => $date], fn ($v) => $v !== null);

        // Montants hors taxes ou taxes incluses : demandé quand le document ne le dit pas
        $mode = (string) ($x['amountsMode'] ?? 'unknown');
        $modeAnswer = in_array($answers['amountsMode'] ?? null, ['ht', 'ttc'], true) ? $answers['amountsMode'] : null;
        if ($mode === 'unknown') {
            $questions[] = $this->question('amountsMode', 'amountsMode', 'lines', true, ['type' => 'choice'], [
                ['value' => 'ht', 'label' => $this->tr('conversation.options.amounts_ht', $locale)], ['value' => 'ttc', 'label' => $this->tr('conversation.options.amounts_ttc', $locale)],
            ], $modeAnswer, $locale);
        }
        $totals = is_array($x['totals'] ?? null) ? $x['totals'] : [];
        $taxRate = $modeAnswer === 'ttc' ? GestionDocumentReader::taxRate('ttc', is_array($x['taxes'] ?? null) ? $x['taxes'] : [], $totals['subtotal'] ?? null, $totals['taxes'] ?? null, $totals['total'] ?? null) : 0.0;

        // Lignes : article reconnu, choisi ou à créer ; les lignes d'un même article (tailles, couleurs) sont demandées une fois
        $asked = [];
        foreach (is_array($x['lines'] ?? null) ? $x['lines'] : [] as $line) {
            $n = (int) ($line['n'] ?? 0);
            $grouped = (int) ($line['groupSize'] ?? 1) > 1 && ($line['groupKey'] ?? '') !== '';
            $key = 'line:' . ($grouped ? $line['groupKey'] : 'n' . $n);
            $answer = is_array($answers[$key] ?? null) ? $answers[$key] : null;
            $unitCost = $answers["unitCost:$n"] ?? ($line['unitCost'] ?? null);
            if ($modeAnswer === 'ttc' && !isset($answers["unitCost:$n"]) && ($line['taxable'] ?? true) && $taxRate > 0 && isset($line['unitCostRead'])) {
                $unitCost = (int) round((int) $line['unitCostRead'] / (1 + $taxRate));
            }
            $out = ['n' => $n, 'label' => $line['label'] ?? null, 'quantity' => $line['quantity'] ?? null, 'unitCost' => $unitCost, 'size' => $line['size'] ?? null, 'color' => $line['color'] ?? null];
            $recognized = is_array($line['product'] ?? null) ? $line['product'] : null;
            if ($answer !== null && isset($answer['productId'])) {
                $out += ['productId' => (int) $answer['productId']] + (isset($answer['variantId']) ? ['variantId' => (int) $answer['variantId']] : []);
            } elseif ($answer !== null && is_array($answer['create'] ?? null)) {
                $out['create'] = array_filter(['name' => (string) ($answer['create']['name'] ?? ''), 'coefficient' => $answer['create']['coefficient'] ?? null, 'groupKey' => $grouped ? (string) $line['groupKey'] : null], fn ($v) => $v !== null);
            } elseif ($recognized !== null) {
                $out += ['productId' => (int) $recognized['id']] + (isset($recognized['variantId']) ? ['variantId' => (int) $recognized['variantId']] : []);
            }
            if (($recognized === null || $answer !== null) && !isset($asked[$key])) {
                $asked[$key] = true;
                $label = trim((string) ($grouped ? ($line['baseLabel'] ?? $line['label'] ?? '') : ($line['label'] ?? '')));
                $options = [];
                foreach (is_array($line['candidates'] ?? null) ? $line['candidates'] : [] as $candidate) {
                    $options[] = ['value' => ['productId' => (int) $candidate['id']], 'label' => trim((string) $candidate['name'] . (($candidate['code'] ?? '') !== '' ? ' (' . $candidate['code'] . ')' : '')), 'score' => $candidate['score'] ?? null];
                }
                $options[] = ['value' => ['create' => ['name' => $label, 'coefficient' => GestionInvoiceDraft::DEFAULT_COEFFICIENT]], 'label' => $this->tr('conversation.options.product_create', $locale, ['%name%' => $label !== '' ? $label : '…'])];
                $questions[] = $this->question($key, 'line.product', "lines[$n]", true, ['type' => 'choice'], $options, $answer, $locale, ['%label%' => $label !== '' ? $label : '?']) + ['lines' => $grouped ? null : [$n], 'groupKey' => $grouped ? (string) $line['groupKey'] : null];
            }
            if (($line['unitCost'] ?? null) === null || isset($answers["unitCost:$n"])) {
                $questions[] = $this->question("unitCost:$n", 'line.unitCost', "lines[$n].unitCost", true, ['type' => 'money', 'currency' => strtoupper((string) ($invoice['currency'] ?? $site))], [], $answers["unitCost:$n"] ?? null, $locale, ['%label%' => (string) ($line['label'] ?? '?')]);
            }
            $body['lines'][] = array_filter($out, fn ($v) => $v !== null);
        }

        // Frais de port lus sur la facture
        if (is_array($x['shipping'] ?? null) && isset($x['shipping']['amount'])) {
            $body['shipping'] = ['amount' => (int) $x['shipping']['amount']];
        }

        // Taux de change : la devise de la facture est celle du fournisseur (règle de la confirmation), sinon celle lue sur le document
        $purchaseCurrency = $supplier?->getCurrency() ?? (is_array($supplierValue['create'] ?? null) ? ($supplierValue['create']['currency'] ?? null) : null)
            ?? ($supplier === null ? ($invoice['currency'] ?? null) : null);
        $purchaseCurrency = is_string($purchaseCurrency) && $purchaseCurrency !== '' ? strtoupper($purchaseCurrency) : $site;
        $rateAnswer = isset($answers['exchangeRate']) && is_numeric($answers['exchangeRate']) ? (float) $answers['exchangeRate'] : null;
        if ($purchaseCurrency !== $site) {
            $questions[] = $this->question('exchangeRate', 'exchangeRate', 'invoice.exchangeRate', true, ['type' => 'number', 'min' => 0], [], $rateAnswer, $locale, ['%from%' => $purchaseCurrency, '%to%' => $site]);
            if ($rateAnswer !== null) {
                $body['invoice']['exchangeRate'] = $rateAnswer;
            }
        }

        // Période de la date de la facture fermée : classer à aujourd'hui, ou abandonner (lecture seule : aucune période n'est créée ici)
        $periodAnswer = $answers['period'] ?? null;
        $period = $date !== null ? $this->calendar->findByDate(new \DateTimeImmutable($date)) : null;
        if (($period !== null && $this->calendar->isClosed($period)) || $periodAnswer !== null) {
            $questions[] = $this->question('period', 'period', 'classificationDate', true, ['type' => 'choice'], [
                ['value' => 'today', 'label' => $this->tr('conversation.options.period_today', $locale)], ['value' => self::ABANDON, 'label' => $this->tr('conversation.options.abandon', $locale)],
            ], $periodAnswer, $locale, ['%period%' => $period !== null ? PeriodCalendar::displayName($period, $locale) : '', '%date%' => (string) $date]);
            if ($periodAnswer === 'today') {
                $body['classificationDate'] = $this->calendar->today()->format('Y-m-d');
            }
        }

        // Doublon : même fournisseur et même numéro qu'une facture déjà confirmée
        $duplicateAnswer = $answers['duplicate'] ?? null;
        $duplicate = null;
        if ($number !== '' && $supplier !== null) {
            /** @var GestionDocumentRepository $repo */
            $repo = $em->getRepository(GestionDocument::class);
            $other = $repo->findDuplicate(GestionDocument::TYPE_FACTURE, 'supplier', (int) $supplier->getId(), $number, $document->getId());
            $duplicate = $other !== null && $other->isConfirmed() ? $other : null;
        }
        if ($duplicate !== null || $duplicateAnswer !== null) {
            $questions[] = $this->question('duplicate', 'duplicate', 'force', true, ['type' => 'choice'], [
                ['value' => 'force', 'label' => $this->tr('conversation.options.duplicate_force', $locale)], ['value' => self::ABANDON, 'label' => $this->tr('conversation.options.abandon', $locale)],
            ], $duplicateAnswer, $locale, ['%number%' => $number]) + ['duplicate' => $duplicate !== null ? ['documentId' => $duplicate->getId(), 'commandeId' => $duplicate->getCommandeId()] : null];
            if ($duplicateAnswer === 'force') {
                $body['force'] = true;
            }
        }

        // Écart entre le total lu et le total recalculé : à reconnaître avant l'aperçu
        $difference = (int) ($x['computed']['difference'] ?? 0);
        if ($difference !== 0) {
            $questions[] = $this->question('difference', 'difference', 'lines', true, ['type' => 'choice'], [['value' => 'accept', 'label' => $this->tr('conversation.options.difference_accept', $locale)]],
                $answers['difference'] ?? null, $locale, ['%difference%' => number_format(abs($difference) / 100, 2, $locale === 'en' ? '.' : ',', ' ')]) + ['difference' => $difference];
        }

        return ['body' => $body, 'questions' => $questions];
    }

    /**
     * Valide une réponse à une question ouverte et rend la valeur à garder (null : réponse retirée).
     *
     * @param array<string, mixed> $question
     */
    public function accept(array $question, mixed $value, int $index): mixed
    {
        if ($value === null) {
            return null;
        }
        $em = $this->emProvider->getEntityManager();
        $fail = fn (string $code) => throw new GestionValidationException(["answers[$index].value" => $code], 'Réponse invalide à « ' . $question['id'] . ' »');
        switch ($question['kind']) {
            case 'supplier':
                if (is_array($value) && isset($value['id']) && is_numeric($value['id'])) {
                    return $em->find(Fournisseur::class, (int) $value['id']) !== null ? ['id' => (int) $value['id']] : $fail('FOURNISSEUR_NOT_FOUND');
                }
                if (is_array($value) && is_array($value['create'] ?? null) && trim((string) ($value['create']['name'] ?? '')) !== '') {
                    $currency = isset($value['create']['currency']) ? strtoupper((string) $value['create']['currency']) : null;
                    if ($currency !== null && !in_array($currency, SiteCurrencyService::SUPPORTED, true)) {
                        $fail('INVALID_CURRENCY');
                    }

                    return ['create' => array_filter(['name' => mb_substr(trim((string) $value['create']['name']), 0, 255), 'typeFournisseurId' => isset($value['create']['typeFournisseurId']) ? (int) $value['create']['typeFournisseurId'] : null, 'currency' => $currency], fn ($v) => $v !== null)];
                }
                $fail('INVALID_VALUE');
                // no break
            case 'line.product':
                if (is_array($value) && isset($value['productId']) && is_numeric($value['productId'])) {
                    $product = $em->find(Product::class, (int) $value['productId']);
                    if ($product === null) {
                        $fail('PRODUCT_NOT_FOUND');
                    }
                    if (isset($value['variantId'])) {
                        $variant = is_numeric($value['variantId']) ? $em->find(ProductVariant::class, (int) $value['variantId']) : null;
                        if ($variant === null || $variant->getProduct()?->getId() !== $product->getId()) {
                            $fail('VARIANT_NOT_IN_PRODUCT');
                        }

                        return ['productId' => (int) $value['productId'], 'variantId' => (int) $value['variantId']];
                    }

                    return ['productId' => (int) $value['productId']];
                }
                if (is_array($value) && is_array($value['create'] ?? null) && trim((string) ($value['create']['name'] ?? '')) !== '') {
                    $coefficient = $value['create']['coefficient'] ?? null;
                    if ($coefficient !== null && (!is_numeric($coefficient) || (float) $coefficient <= 0)) {
                        $fail('MUST_BE_POSITIVE');
                    }

                    return ['create' => array_filter(['name' => mb_substr(trim((string) $value['create']['name']), 0, 255), 'coefficient' => $coefficient !== null ? (float) $coefficient : null], fn ($v) => $v !== null)];
                }
                $fail('INVALID_VALUE');
                // no break
            case 'invoice.date':
                return self::day($value) ?? $fail('INVALID_DATE');
            case 'amountsMode':
                return in_array($value, ['ht', 'ttc'], true) ? $value : $fail('INVALID_VALUE');
            case 'exchangeRate':
                return is_numeric($value) && (float) $value > 0 ? (float) $value : $fail('MUST_BE_POSITIVE');
            case 'line.unitCost':
                return is_int($value) && $value >= 0 ? $value : $fail('INVALID_AMOUNT');
            default: // period, duplicate, difference : une des valeurs proposées
                return in_array($value, array_column($question['options'], 'value'), true) ? $value : $fail('INVALID_VALUE');
        }
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && \DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $options
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function question(string $id, string $kind, string $path, bool $required, array $input, array $options, mixed $answer, string $locale, array $params = []): array
    {
        $key = str_replace('.', '_', $kind);

        return ['id' => $id, 'kind' => $kind, 'path' => $path, 'title' => $this->tr("conversation.questions.$key.title", $locale, $params), 'text' => $this->tr("conversation.questions.$key.text", $locale, $params),
            'required' => $required, 'input' => $input, 'options' => $options, 'answer' => $answer];
    }

    /** @param array<string, string> $params */
    private function tr(string $id, string $locale, array $params = []): string
    {
        return $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
