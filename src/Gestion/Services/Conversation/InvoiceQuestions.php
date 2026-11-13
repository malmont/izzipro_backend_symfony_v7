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
 *
 * Lot IA-ACT-5 (13/11/2026, § 13 de la fiche), en fin de liste, toutes PROPOSÉES (`proposed: true`, elles ne comptent pas parmi les
 * questions ouvertes) : `coefficient` (le coefficient des articles créés : celui proposé pour chacun d'après ses semblables, le
 * fournisseur ou le réglage du site, ou un nombre pour toute la facture), `publish.pos` et `publish.web` (mise en ligne des articles
 * créés, par canal : non, tout de suite, à la réception), `price.mode` (prix des articles déjà au catalogue : les garder, appliquer à
 * chacun le coefficient proposé, ou régler article par article) et, sur « article par article » seulement, `price:<productId>` (le
 * garder, un coefficient, un prix saisi).
 */
class InvoiceQuestions
{
    public const ABANDON = 'abandon';
    /** Prix des articles déjà au catalogue, pour toute la facture (lot IA-ACT-5) : les garder, le coefficient proposé à chacun, ou article par article */
    public const PRICE_MODES = ['keep', 'suggested', 'each'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly TenantCurrencyProvider $currency,
        private readonly TranslatorInterface $translator,
        private readonly \App\Gestion\Services\Document\InvoiceTargetPolicy $targets, // lot IA-FACT-2-A : ajout à une commande ouverte
        private readonly \App\Gestion\Analysis\CoefficientSuggestion $suggestions, // lot IA-ACT-5 : coefficient proposé
        private readonly \App\Gestion\Services\GestionPriceSettingsService $settings,
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
        // La question est toujours rendue (lot IA-FACT-2-A) : un fournisseur reconnu par la lecture y figure comme réponse PROPOSÉE
        // (`proposed: true`, elle ne compte pas parmi les questions ouvertes), pour que le gérant puisse en choisir un autre
        $options = [];
        $seen = [];
        foreach (is_array($read['candidates'] ?? null) ? $read['candidates'] : [] as $candidate) {
            $options[] = ['value' => ['id' => (int) $candidate['id']], 'label' => (string) $candidate['name'], 'score' => $candidate['score'] ?? null];
            $seen[(int) $candidate['id']] = true;
        }
        $name = trim((string) ($read['name'] ?? ''));
        foreach ([$read['id'] ?? null, is_array($supplierAnswer) ? ($supplierAnswer['id'] ?? null) : null] as $id) {
            $known = $id !== null && !isset($seen[(int) $id]) ? $em->find(Fournisseur::class, (int) $id) : null;
            if ($known !== null) {
                array_unshift($options, ['value' => ['id' => (int) $id], 'label' => (string) $known->getName(), 'score' => null]);
                $seen[(int) $id] = true;
            }
        }
        $options[] = ['value' => ['create' => ['name' => $name]], 'label' => $this->tr('conversation.options.supplier_create', $locale, ['%name%' => $name !== '' ? $name : '…'])];
        $questions[] = $this->question('supplier', 'supplier', 'supplier', true, ['type' => 'choice'], $options, $supplierValue, $locale, ['%name%' => $name !== '' ? $name : '?'])
            + ['proposed' => $supplierAnswer === null && $supplierValue !== null];
        if ($supplierValue !== null) {
            $body['supplier'] = $supplierValue;
        }
        $supplier = isset($supplierValue['id']) ? $em->find(Fournisseur::class, (int) $supplierValue['id']) : null;

        // Cible (lot IA-FACT-2-A), juste après le fournisseur : une nouvelle commande, ou une commande ouverte du même fournisseur.
        // Posée seulement s'il en existe une. Recalculée à chaque lecture : une réponse donnée pour un autre fournisseur, ou pour une
        // commande qui n'est plus admise, est oubliée et la question se repose.
        $targetCommande = null;
        $eligible = $supplier !== null ? $this->targets->eligible($supplier) : [];
        if ($eligible !== []) {
            $targetAnswer = $answers['target'] ?? null;
            $options = [['value' => 'new', 'label' => $this->tr('conversation.options.target_new', $locale)]];
            foreach ($eligible as $commande) {
                $options[] = ['value' => ['commandeId' => (int) $commande->getId()], 'label' => trim((string) $commande->getName()) !== '' ? (string) $commande->getName() : '#' . $commande->getId(),
                    'date' => $commande->getDate()?->format('Y-m-d'), 'budget' => $commande->getBudget(), 'currency' => strtoupper($commande->getCurrency() ?? $site),
                    'linesCount' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM commande_line WHERE commande_id = ?', [$commande->getId()])];
                if (is_array($targetAnswer) && (int) ($targetAnswer['commandeId'] ?? 0) === (int) $commande->getId()) {
                    $targetCommande = $commande;
                }
            }
            $targetAnswer = $targetAnswer === 'new' ? 'new' : ($targetCommande !== null ? ['commandeId' => (int) $targetCommande->getId()] : null);
            $questions[] = $this->question('target', 'target', 'target', true, ['type' => 'choice'], $options, $targetAnswer, $locale, ['%supplier%' => (string) $supplier->getName()]);
            if ($targetCommande !== null) {
                $body['target'] = ['commandeId' => (int) $targetCommande->getId()];
            }
        }

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
                $out['create'] = array_filter(['name' => (string) ($answer['create']['name'] ?? ''), 'coefficient' => $answer['create']['coefficient'] ?? null, 'groupKey' => $grouped ? (string) $line['groupKey'] : null,
                    'publishPos' => $answer['create']['publishPos'] ?? null, 'publishWeb' => $answer['create']['publishWeb'] ?? null], fn ($v) => $v !== null);
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
                // sans coefficient : celui de la question `coefficient` s'applique ; `create.coefficient` reste accepté pour régler une seule ligne (lot IA-ACT-5)
                $options[] = ['value' => ['create' => ['name' => $label]], 'label' => $this->tr('conversation.options.product_create', $locale, ['%name%' => $label !== '' ? $label : '…'])];
                $questions[] = $this->question($key, 'line.product', "lines[$n]", true, ['type' => 'choice'], $options, $answer, $locale, ['%label%' => $label !== '' ? $label : '?']) + ['lines' => $grouped ? null : [$n], 'groupKey' => $grouped ? (string) $line['groupKey'] : null];
            }
            if (($line['unitCost'] ?? null) === null || isset($answers["unitCost:$n"])) {
                $questions[] = $this->question("unitCost:$n", 'line.unitCost', "lines[$n].unitCost", true, ['type' => 'money', 'currency' => strtoupper((string) ($invoice['currency'] ?? $site))], [], $answers["unitCost:$n"] ?? null, $locale, ['%label%' => (string) ($line['label'] ?? '?')]);
            }
            $body['lines'][] = array_filter($out, fn ($v) => $v !== null);
        }

        // Frais de port lus sur la facture. Ajoutée à une commande qui en a déjà un : ajouter au montant existant, ou ne pas le compter
        if (is_array($x['shipping'] ?? null) && isset($x['shipping']['amount'])) {
            $amount = (int) $x['shipping']['amount'];
            $body['shipping'] = ['amount' => $amount];
            $current = $targetCommande !== null ? $em->getRepository(\App\Entity\FraisDePort::class)->findOneBy(['commande' => $targetCommande]) : null;
            if ($current !== null) {
                $shippingAnswer = in_array($answers['shipping'] ?? null, ['add', 'skip'], true) ? $answers['shipping'] : null;
                $money = fn (int $cents) => number_format($cents / 100, 2, $locale === 'en' ? '.' : ',', ' ') . ' ' . strtoupper($targetCommande->getCurrency() ?? $site);
                $params = ['%amount%' => $money($amount), '%current%' => $money((int) $current->getPrice()), '%total%' => $money((int) $current->getPrice() + $amount)];
                $questions[] = $this->question('shipping', 'shipping', 'shipping', true, ['type' => 'choice'], [
                    ['value' => 'add', 'label' => $this->tr('conversation.options.shipping_add', $locale, $params)], ['value' => 'skip', 'label' => $this->tr('conversation.options.shipping_skip', $locale, $params)],
                ], $shippingAnswer, $locale, $params) + ['shipping' => ['current' => (int) $current->getPrice(), 'added' => $amount, 'total' => (int) $current->getPrice() + $amount]];
                if ($shippingAnswer === 'add') {
                    $body['shipping']['mode'] = 'add';
                } elseif ($shippingAnswer === 'skip') {
                    unset($body['shipping']);
                }
            }
        }

        // Taux de change : la devise de la facture est celle du fournisseur (règle de la confirmation), sinon celle lue sur le document
        $purchaseCurrency = $supplier?->getCurrency() ?? (is_array($supplierValue['create'] ?? null) ? ($supplierValue['create']['currency'] ?? null) : null)
            ?? ($supplier === null ? ($invoice['currency'] ?? null) : null);
        $purchaseCurrency = is_string($purchaseCurrency) && $purchaseCurrency !== '' ? strtoupper($purchaseCurrency) : $site;
        $rateAnswer = isset($answers['exchangeRate']) && is_numeric($answers['exchangeRate']) ? (float) $answers['exchangeRate'] : null;
        if ($purchaseCurrency !== $site && $targetCommande === null) { // ajoutée à une commande : son taux s'applique, pas de question
            $questions[] = $this->question('exchangeRate', 'exchangeRate', 'invoice.exchangeRate', true, ['type' => 'number', 'min' => 0], [], $rateAnswer, $locale, ['%from%' => $purchaseCurrency, '%to%' => $site]);
            if ($rateAnswer !== null) {
                $body['invoice']['exchangeRate'] = $rateAnswer;
            }
        }

        // Période de la date de la facture fermée : classer à aujourd'hui, ou abandonner (lecture seule : aucune période n'est créée ici)
        $periodAnswer = $answers['period'] ?? null;
        $period = $date !== null ? $this->calendar->findByDate(new \DateTimeImmutable($date)) : null;
        if ($targetCommande === null && (($period !== null && $this->calendar->isClosed($period)) || $periodAnswer !== null)) { // ajoutée à une commande : sa date et sa période s'appliquent
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

        // ---- Prix de vente et mise en ligne (lot IA-ACT-5), après tout ce qui fixe les coûts ----
        $pricingCurrency = $targetCommande !== null ? strtoupper($targetCommande->getCurrency() ?? $site) : $purchaseCurrency;
        $rate = GestionInvoiceDraft::effectiveRate($pricingCurrency, $site, $targetCommande !== null ? $targetCommande->getExchangeRate() : $rateAnswer);
        $day = $date !== null ? new \DateTimeImmutable($date) : $this->calendar->today();
        $amount = fn (int $cents) => number_format($cents / 100, 2, $locale === 'en' ? '.' : ',', ' ') . ' ' . $site;
        $number = fn (float $v) => \App\Gestion\Analysis\CoefficientSuggestion::number($v, $locale);
        $groups = [];
        $existing = [];
        foreach ($body['lines'] as $i => $l) {
            if (isset($l['create'])) {
                $groups[$l['create']['groupKey'] ?? 'line-' . $l['n']][] = $i;
            } elseif (isset($l['productId'])) {
                $existing[(int) $l['productId']][] = $i;
            }
        }
        // Coût unitaire moyen pondéré d'un groupe de lignes, dans la devise du site ; null tant qu'un coût ou le taux manque
        $cost = function (array $idx) use (&$body, $rate): ?int {
            $quantity = $total = 0;
            foreach ($idx as $i) {
                $l = $body['lines'][$i];
                if (is_int($l['quantity'] ?? null) && is_int($l['unitCost'] ?? null) && $l['quantity'] > 0) {
                    $quantity += $l['quantity'];
                    $total += $l['quantity'] * $l['unitCost'];
                }
            }

            return $quantity > 0 && $rate !== null ? GestionInvoiceDraft::convert((int) round($total / $quantity), $rate) : null;
        };
        if ($groups !== []) {
            // Coefficient des articles créés : pour chacun, d'après ses semblables, sinon le fournisseur, sinon le réglage du site (aucune
            // IA) ; un nombre répondu vaut pour toute la facture ; `create.coefficient` d'une ligne passe avant tout
            $coefficientAnswer = $answers['coefficient'] ?? null;
            $articles = [];
            $proposals = [];
            $shared = [];
            foreach ($groups as $key => $idx) {
                $unitCost = $cost($idx);
                $suggestion = $this->suggestions->suggest($supplier?->getId(), $unitCost, [], $day, $locale);
                $explicit = $body['lines'][$idx[0]]['create']['coefficient'] ?? null;
                $coefficient = (float) ($explicit ?? (is_int($coefficientAnswer) || is_float($coefficientAnswer) ? $coefficientAnswer : $suggestion['proposed']));
                foreach ($idx as $i) {
                    $body['lines'][$i]['create']['coefficient'] = $coefficient;
                }
                $articles[] = ['lines' => array_map(fn (int $i) => $body['lines'][$i]['n'], $idx), 'groupKey' => $body['lines'][$idx[0]]['create']['groupKey'] ?? null, 'name' => $body['lines'][$idx[0]]['create']['name'] ?? null,
                    'cost' => $unitCost, 'coefficient' => $coefficient, 'source' => $explicit !== null ? 'line' : (is_int($coefficientAnswer) || is_float($coefficientAnswer) ? 'invoice' : $suggestion['source']),
                    'proposed' => $suggestion['proposed'], 'proposedSource' => $suggestion['source'], 'label' => $suggestion['options'][0]['label'], 'similar' => $suggestion['options'][0]['source'] === 'similar' ? $suggestion['options'][0]['figures'] : null,
                    'price' => $unitCost === null ? null : $this->settings->price($unitCost, $coefficient)];
                $proposals[] = $suggestion['proposed'];
                foreach ($suggestion['options'] as $option) {
                    if ($option['source'] !== 'similar') {
                        $shared[$option['source']] = $option; // les mêmes pour toute la facture : le fournisseur, le site
                    }
                }
            }
            $sources = array_values(array_unique(array_column($articles, 'proposedSource')));
            $suggestedLabel = count($articles) === 1 || (count(array_unique($proposals)) === 1 && $sources !== ['similar']) ? $articles[0]['label']
                : $this->tr(min($proposals) === max($proposals) ? 'conversation.options.coefficient_each_same' : 'conversation.options.coefficient_each', $locale, ['%min%' => $number(min($proposals)), '%max%' => $number(max($proposals))]);
            $options = [['value' => 'suggested', 'label' => $suggestedLabel]];
            foreach ($shared as $source => $option) {
                if ($sources !== [$source]) { // déjà dit par « proposé » quand tous les articles en viennent
                    $options[] = ['value' => $option['value'], 'label' => $option['label'], 'source' => $source];
                }
            }
            $questions[] = $this->question('coefficient', 'coefficient', 'lines', true, ['type' => 'choice', 'number' => ['min' => \App\Gestion\Services\GestionPriceSettingsService::COEFFICIENT_FROM, 'max' => GestionInvoiceDraft::COEFFICIENT_MAX]],
                $options, $coefficientAnswer ?? 'suggested', $locale) + ['proposed' => $coefficientAnswer === null, 'articles' => $articles];

            // Mise en ligne des articles créés, par canal : non (proposé), tout de suite, à la réception du stock ; une ligne peut en décider autrement
            foreach (['pos' => 'publishPos', 'web' => 'publishWeb'] as $channel => $field) {
                $id = "publish.$channel";
                $publishAnswer = in_array($answers[$id] ?? null, GestionInvoiceDraft::PUBLISH, true) ? $answers[$id] : null;
                $questions[] = $this->question($id, $id, 'lines', true, ['type' => 'choice'], array_map(fn (string $v) => ['value' => $v, 'label' => $this->tr("conversation.options.publish_$v", $locale)], GestionInvoiceDraft::PUBLISH),
                    $publishAnswer ?? 'no', $locale) + ['proposed' => $publishAnswer === null];
                foreach ($groups as $idx) {
                    foreach ($idx as $i) {
                        $value = $body['lines'][$i]['create'][$field] ?? $publishAnswer ?? 'no';
                        unset($body['lines'][$i]['create'][$field]);
                        if ($value !== 'no') {
                            $body['lines'][$i]['create'][$field] = $value;
                        }
                    }
                }
            }
        }
        // Prix des articles déjà au catalogue. UNE question pour la facture, `price.mode`, proposée à « keep » : garder tous les prix,
        // appliquer à chacun le coefficient proposé (« suggested »), ou régler article par article (« each »). Les questions
        // `price:<productId>` n'existent que sur « each » : une facture de 30 articles reste lisible.
        $priced = [];
        foreach ($existing as $productId => $idx) {
            $product = $em->find(Product::class, $productId);
            if ($product === null) {
                continue;
            }
            $unitCost = $cost($idx);
            $current = $product->getPrice() !== null ? (int) round((float) $product->getPrice()) : null;
            $categories = array_map('intval', $em->getConnection()->fetchFirstColumn('SELECT categories_id FROM product_categories WHERE product_id = ?', [$productId]));
            $priced[$productId] = ['idx' => $idx, 'name' => (string) $product->getName(), 'cost' => $unitCost, 'current' => $current,
                'currentCoefficient' => $unitCost !== null && $unitCost > 0 && $current !== null && $current > 0 ? round($current / $unitCost, 2) : null,
                'suggestion' => $this->suggestions->suggest($supplier?->getId(), $unitCost, $categories, $day, $locale, $productId)];
        }
        if ($priced !== []) {
            $modeAnswer = in_array($answers['price.mode'] ?? null, self::PRICE_MODES, true) ? $answers['price.mode'] : null;
            $priceMode = $modeAnswer ?? 'keep';
            $questions[] = $this->question('price.mode', 'price.mode', 'lines', true, ['type' => 'choice'], array_map(fn (string $m) => ['value' => $m, 'label' => $this->tr("conversation.options.price_mode_$m", $locale)], self::PRICE_MODES),
                $priceMode, $locale, ['%count%' => (string) count($priced)])
                + ['proposed' => $modeAnswer === null, 'articles' => array_values(array_map(fn (int $productId, array $a) => ['productId' => $productId, 'name' => $a['name'], 'lines' => array_map(fn (int $i) => $body['lines'][$i]['n'], $a['idx']),
                    'currentPrice' => $a['current'], 'cost' => $a['cost'], 'currentCoefficient' => $a['currentCoefficient'], 'proposed' => $a['suggestion']['proposed'], 'proposedSource' => $a['suggestion']['source'],
                    'label' => $a['suggestion']['options'][0]['label'], 'price' => $a['cost'] === null ? null : $this->settings->price($a['cost'], $a['suggestion']['proposed'])], array_keys($priced), $priced))];
            foreach ($priced as $productId => $a) {
                $decision = null;
                if ($priceMode === 'suggested') {
                    $decision = $a['cost'] === null ? null : ['mode' => 'coefficient', 'coefficient' => (float) $a['suggestion']['proposed']];
                } elseif ($priceMode === 'each') {
                    // Article par article : le garder (proposé), un coefficient sur le coût de cette facture, ou un prix saisi
                    $id = "price:$productId";
                    $priceAnswer = is_array($answers[$id] ?? null) && in_array($answers[$id]['mode'] ?? null, GestionInvoiceDraft::PRICE_MODES, true) ? $answers[$id] : null;
                    $options = [['value' => ['mode' => 'keep'], 'label' => $a['current'] === null ? $this->tr('conversation.options.price_keep_none', $locale)
                        : $this->tr($a['currentCoefficient'] === null ? 'conversation.options.price_keep' : 'conversation.options.price_keep_coefficient', $locale, ['%price%' => $amount($a['current']), '%coefficient%' => $a['currentCoefficient'] === null ? '' : $number($a['currentCoefficient'])])]];
                    if ($a['cost'] !== null) {
                        foreach ($a['suggestion']['options'] as $option) {
                            $price = $this->settings->price($a['cost'], $option['value']);
                            $options[] = ['value' => ['mode' => 'coefficient', 'coefficient' => $option['value']], 'label' => $option['label'] . ' → ' . $amount($price), 'price' => $price, 'source' => $option['source']];
                        }
                    }
                    $questions[] = $this->question($id, 'line.price', 'lines[' . $body['lines'][$a['idx'][0]]['n'] . '].price', true, ['type' => 'choice', 'money' => ['currency' => $site], 'number' => ['min' => \App\Gestion\Services\GestionPriceSettingsService::COEFFICIENT_FROM, 'max' => GestionInvoiceDraft::COEFFICIENT_MAX]],
                        $options, $priceAnswer ?? ['mode' => 'keep'], $locale, ['%label%' => $a['name']])
                        + ['proposed' => $priceAnswer === null, 'product' => ['id' => $productId, 'name' => $a['name']], 'lines' => array_map(fn (int $i) => $body['lines'][$i]['n'], $a['idx']),
                            'currentPrice' => $a['current'], 'cost' => $a['cost'], 'currentCoefficient' => $a['currentCoefficient']];
                    if ($priceAnswer !== null && $priceAnswer['mode'] !== 'keep') {
                        $decision = $priceAnswer['mode'] === 'fixed' ? ['mode' => 'fixed', 'price' => (int) $priceAnswer['price']] : ['mode' => 'coefficient', 'coefficient' => (float) ($priceAnswer['coefficient'] ?? $a['suggestion']['proposed'])];
                    }
                }
                if ($decision !== null) {
                    foreach ($a['idx'] as $i) {
                        $body['lines'][$i]['price'] = $decision;
                    }
                }
            }
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

                    foreach (['publishPos', 'publishWeb'] as $channel) { // lot IA-ACT-5 : mise en ligne réglée pour cette seule ligne
                        if (isset($value['create'][$channel]) && !in_array($value['create'][$channel], GestionInvoiceDraft::PUBLISH, true)) {
                            $fail('INVALID_VALUE');
                        }
                    }

                    return ['create' => array_filter(['name' => mb_substr(trim((string) $value['create']['name']), 0, 255), 'coefficient' => $coefficient !== null ? (float) $coefficient : null,
                        'publishPos' => $value['create']['publishPos'] ?? null, 'publishWeb' => $value['create']['publishWeb'] ?? null], fn ($v) => $v !== null)];
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
            case 'coefficient': // lot IA-ACT-5 : « proposé pour chaque article », ou un nombre pour toute la facture
                if ($value === 'suggested') {
                    return $value;
                }
                if (!is_int($value) && !is_float($value) || $value <= 0) {
                    $fail('MUST_BE_POSITIVE');
                }

                return $value <= GestionInvoiceDraft::COEFFICIENT_MAX ? round((float) $value, 2) : $fail('OUT_OF_RANGE');
            case 'line.price': // lot IA-ACT-5 : prix d'un article déjà au catalogue
                $mode = is_array($value) ? ($value['mode'] ?? null) : null;
                if ($mode === 'keep') {
                    return ['mode' => 'keep'];
                }
                if ($mode === 'fixed') {
                    return is_int($value['price'] ?? null) && $value['price'] > 0 ? ['mode' => 'fixed', 'price' => $value['price']] : $fail('INVALID_AMOUNT');
                }
                if ($mode === 'coefficient') {
                    $coefficient = $value['coefficient'] ?? null;
                    if ($coefficient !== null && (!is_int($coefficient) && !is_float($coefficient) || $coefficient <= 0)) {
                        $fail('MUST_BE_POSITIVE');
                    }
                    if ($coefficient !== null && $coefficient > GestionInvoiceDraft::COEFFICIENT_MAX) {
                        $fail('OUT_OF_RANGE');
                    }

                    return ['mode' => 'coefficient'] + ($coefficient !== null ? ['coefficient' => round((float) $coefficient, 2)] : []);
                }
                $fail('INVALID_VALUE');
                // no break
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
