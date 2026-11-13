<?php

namespace App\Gestion\UseCase;

use App\Dto\CommandeOutputDTO;
use App\Dto\FraisDePortInputDTO;
use App\Dto\FraisDePortOutputDTO;
use App\Dto\NoteDeFraisInputDTO;
use App\Dto\NoteDeFraisOutputDTO;
use App\Entity\Color;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Gestion\Entity\GestionDocument;
use App\Entity\Product;
use App\Entity\ProductPicture;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Gestion\Repository\GestionDocumentRepository;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CommandeService\CommandeService;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\FraisDePortService\FraisDePortService;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\Document\GestionInvoiceDraft;
use App\Gestion\Services\Document\GestionDocumentStorage;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionInput;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodGuard;
use App\Services\NoteDeFraisService\NoteDeFraisService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\MediaUseCase\UploadMediaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * POST /api/gestion/documents/{id}/confirmer (lot L1, 10/11/2026, `docs/lecture-documents-gestion.md`) : crée ce que le gérant a vérifié,
 * tout ou rien, dans une transaction. Facture → commande fournisseur classée à `classificationDate ?? invoice.date` (409 `PERIOD_CLOSED`)
 * et ses LIGNES D'ACHAT (`source = document`, « à recevoir » : quantité reçue 0 ; fin du lot L1 sur les lignes du lot L0, 10/11/2026) :
 * article existant = une ligne de plus (réassort), sans effet sur le stock, le prix ni la visibilité ; nouvel article (un par `groupKey`,
 * variantes taille / couleur) créé invisible en boutique et en caisse avec un STOCK DE 0 (le stock n'entre qu'à la réception, lot R1),
 * photo de ligne comme image ; frais de port facultatif. Note → note de frais. Transport → frais de port d'une commande. Le document devient le justificatif (`document_id`).
 */
class ConfirmGestionDocumentUseCase
{
    public const SHIPPING_CARRIER = 'Sur facture fournisseur';
    public const DEFAULT_COEFFICIENT = GestionInvoiceDraft::DEFAULT_COEFFICIENT;


    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionDocumentService $documents,
        private readonly GestionDocumentStorage $storage,
        private readonly PeriodGuard $guard,
        private readonly CommandeService $commandes,
        private readonly FraisDePortService $shipping,
        private readonly NoteDeFraisService $notes,
        private readonly ContentAuditRecorder $audit,
        private readonly \App\Services\CommandeService\CommandeLineService $lines,
        private readonly ?UploadMediaUseCase $upload = null,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?\App\Gestion\Services\MarketingCampaignService $campaigns = null, // lot A1 : facture de régie confirmée en campagne
    ) {
    }

    /** @param array<string, mixed> $body */
    public function execute(GestionDocument $document, array $body, string $host, ?string $user): array
    {
        $this->documents->assertOpen($document);
        if (!in_array($document->getStatus(), [GestionDocument::STATUS_EXTRACTED, GestionDocument::STATUS_FAILED], true)) {
            throw new GestionConflictException('DOCUMENT_IN_PROGRESS', 'Lecture en cours : attendez son résultat avant de confirmer.');
        }
        $em = $this->emProvider->getEntityManager();
        $em->getConnection()->beginTransaction();
        try {
            $result = match ($document->getType()) {
                GestionDocument::TYPE_FACTURE => $this->invoice($em, $document, $body, $host, $user),
                GestionDocument::TYPE_TRANSPORT => $this->transport($em, $document, $body, $host),
                default => $this->note($em, $document, $body, $host),
            };
            $document->setStatus(GestionDocument::STATUS_CONFIRMED)->setConfirmedAt(new \DateTimeImmutable())->setConfirmed($result['confirmed']);
            $em->flush();
            $em->getConnection()->commit();
        } catch (\Throwable $e) {
            $em->getConnection()->rollBack();
            throw $e;
        }
        $this->audit->record('gestion-documents', $document->getId(), 'confirm', null, ['type' => $document->getType(), 'commandeId' => $document->getCommandeId(), 'noteDeFraisId' => $document->getNoteDeFraisId(), 'fraisDePortId' => $document->getFraisDePortId()]);
        unset($result['confirmed']);

        return $result + ['document' => $this->documents->toArray($document)];
    }

    // ----- facture fournisseur -----

    /** @param array<string, mixed> $body */
    private function invoice(EntityManagerInterface $em, GestionDocument $document, array $body, string $host, ?string $user = null): array
    {
        $errors = [];
        $input = new GestionInput($body);
        $invoiceIn = is_array($body['invoice'] ?? null) ? $body['invoice'] : [];
        $invoiceInput = new GestionInput($invoiceIn);
        $invoiceDate = $invoiceInput->date('date');
        $number = $invoiceInput->text('number', false, 80);
        $exchangeRate = $invoiceInput->decimal('exchangeRate', false);
        $classification = $input->date('classificationDate', false);
        $force = ($body['force'] ?? false) === true;
        $errors += array_map(fn ($c) => $c, $this->prefix('invoice', $invoiceInput->errors()));
        $errors += $input->errors();
        // Fournisseur : existant ou créé
        $supplierIn = is_array($body['supplier'] ?? null) ? $body['supplier'] : [];
        $supplier = null;
        $supplierCreated = false;
        if (isset($supplierIn['id']) && is_numeric($supplierIn['id'])) {
            $supplier = $em->find(Fournisseur::class, (int) $supplierIn['id']);
            if ($supplier === null) {
                $errors['supplier.id'] = 'FOURNISSEUR_NOT_FOUND';
            }
        } elseif (is_array($supplierIn['create'] ?? null)) {
            $createIn = new GestionInput($supplierIn['create']);
            $name = $createIn->text('name');
            $typeId = $createIn->id('typeFournisseurId', false);
            $currency = $createIn->text('currency', false, 3);
            $errors += $this->prefix('supplier.create', $createIn->errors());
            if ($currency !== null && !in_array(strtoupper($currency), \App\Services\BoutiqueSettingsService\SiteCurrencyService::SUPPORTED, true)) {
                $errors['supplier.create.currency'] = 'INVALID_CURRENCY';
            }
            if ($errors === []) {
                $type = $typeId !== null ? $em->find(TypeFournisseur::class, $typeId) : ($em->getRepository(TypeFournisseur::class)->findOneBy(['name' => 'Autre']) ?? $em->getRepository(TypeFournisseur::class)->findOneBy([], ['id' => 'ASC']));
                if ($typeId !== null && $type === null) {
                    $errors['supplier.create.typeFournisseurId'] = 'TYPE_FOURNISSEUR_NOT_FOUND';
                } else {
                    $supplier = (new Fournisseur())->setName((string) $name)->setTypeFournisseur($type)->setCurrency($currency !== null ? strtoupper($currency) : null);
                    $em->persist($supplier);
                    $supplierCreated = true;
                }
            }
        } else {
            $errors['supplier'] = 'REQUIRED';
        }
        // Lignes : lecture partagée avec l'aperçu (mêmes chemins, mêmes codes ; lot APERÇU-FACT)
        [$lines, $lineErrors] = GestionInvoiceDraft::parseLines($body);
        $errors += $lineErrors;
        // Frais de port
        $shippingIn = is_array($body['shipping'] ?? null) ? $body['shipping'] : null;
        $shippingAmount = null;
        $transporteurId = null;
        if ($shippingIn !== null) {
            $shippingInput = new GestionInput($shippingIn);
            $shippingAmount = $shippingInput->cents('amount');
            $transporteurId = $shippingInput->id('transporteurId', false);
            $errors += $this->prefix('shipping', $shippingInput->errors());
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Confirmation invalide : ' . implode(', ', array_keys($errors)));
        }
        /** @var Fournisseur $supplier */
        // Date de classement et période
        $date = $classification ?? $invoiceDate;
        $period = $this->guard->assertDateWritable($date);
        if ($period === null) {
            throw new GestionValidationException([$classification !== null ? 'classificationDate' : 'invoice.date' => 'OUT_OF_CALENDAR'], 'Aucune période du calendrier ne couvre cette date');
        }
        // Doublon de numéro chez ce fournisseur
        if ($number !== null && $supplier->getId() !== null && !$force) {
            /** @var GestionDocumentRepository $repo */
            $repo = $em->getRepository(GestionDocument::class);
            $other = $repo->findDuplicate(GestionDocument::TYPE_FACTURE, 'supplier', (int) $supplier->getId(), $number, $document->getId());
            if ($other !== null && $other->isConfirmed()) {
                throw new GestionConflictException('INVOICE_ALREADY_CONFIRMED', sprintf('La facture %s de ce fournisseur est déjà enregistrée.', $number), 409, ['documentId' => $other->getId(), 'commandeId' => $other->getCommandeId()]);
            }
        }
        // Produits existants : vérifiés avant toute écriture
        $products = [];
        foreach ($lines as $line) {
            if ($line['productId'] === null) {
                continue;
            }
            $product = $em->find(Product::class, $line['productId']);
            if ($product === null) {
                $errors["lines[{$line['n']}].productId"] = 'PRODUCT_NOT_FOUND';
                continue;
            }
            $variant = null;
            if ($line['variantId'] !== null) {
                $variant = $em->find(ProductVariant::class, $line['variantId']);
                if ($variant === null || $variant->getProduct()?->getId() !== $product->getId()) {
                    $errors["lines[{$line['n']}].variantId"] = 'VARIANT_NOT_IN_PRODUCT';
                    continue;
                }
            }
            $products[$line['n']] = [$product, $variant]; // lot L0 : plus de PRODUCT_ALREADY_ATTACHED, un article déjà acheté reçoit une ligne de plus (réassort)
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Confirmation invalide : ' . implode(', ', array_keys($errors)));
        }
        $budget = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unitCost'], $lines)) + ($shippingAmount ?? 0);
        $name = $input->text('name', false) ?? ('Facture ' . ($number ?? $invoiceDate->format('Y-m-d')) . ' — ' . $supplier->getName());
        $em->flush();
        $commande = $this->commandes->createCommande(['budget' => $budget, 'name' => $name, 'date' => $date->format('Y-m-d') . ' 12:00:00', 'exchangeRate' => $exchangeRate], $period, $supplier);
        // Taux des coûts de la facture vers la devise du site : entre dans le prix d'achat et le prix de vente des articles créés (lot
        // APERÇU-FACT, 12/11/2026 ; avant, un article acheté 10,00 EUR au coefficient 2 était créé à 20,00 dans la devise du site)
        $rate = GestionInvoiceDraft::effectiveRate($commande->getCurrency(), $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT, $commande->getExchangeRate()) ?? 1.0;
        $now = new \DateTimeImmutable();
        $attached = [];
        $merged = []; // deux lignes de facture pour le même article (et la même variante) : une ligne d'achat, quantités additionnées, coût moyen pondéré
        foreach ($products as $n => [$product, $variant]) {
            $line = $this->line($lines, $n);
            $key = $product->getId() . '/' . ($variant?->getId() ?? '-');
            $merged[$key] ??= ['product' => $product, 'variant' => $variant, 'quantity' => 0, 'cost' => 0];
            $merged[$key]['quantity'] += $line['quantity'];
            $merged[$key]['cost'] += $line['quantity'] * $line['unitCost'];
            $attached[] = ['lineN' => $n, 'productId' => $product->getId()];
        }
        foreach ($merged as $m) {
            $this->lines->upsert($commande, $m['product'], $m['variant'], $m['quantity'], $m['quantity'] > 0 ? (int) round($m['cost'] / $m['quantity']) : null, false, \App\Entity\CommandeLine::SOURCE_DOCUMENT, $now, 0, $user);
        }
        // Articles créés : un par groupKey
        $created = [];
        $createdProducts = [];
        foreach (GestionInvoiceDraft::groups($lines) as $group) {
            $product = $this->createProduct($em, $document, $commande, $group, $host, $now, $user, $rate);
            $createdProducts[] = $product;
            foreach ($group as $line) {
                $created[] = ['lineN' => $line['n'], 'productId' => $product->getId(), 'name' => $product->getName()];
            }
        }
        $em->flush();
        $fraisDePort = null;
        if ($shippingAmount !== null) {
            $transporteur = $transporteurId !== null ? $em->find(Transporteur::class, $transporteurId) : $this->invoiceCarrier($em);
            if ($transporteur === null) {
                throw new GestionConflictException('TRANSPORTEUR_NOT_FOUND', 'Transporteur inconnu : ' . $transporteurId, 404);
            }
            $fraisDePort = $this->shipping->createFraisDePort($commande, new FraisDePortInputDTO('Frais de port — ' . $name, (string) ($number ?? ''), '', $shippingAmount, (int) $transporteur->getId()));
        }
        $commande->setDocument($document);
        $document->setCommandeId($commande->getId());
        $em->flush();
        $currency = $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;

        return [
            'commande' => new CommandeOutputDTO($commande, $host, $currency), 'attached' => $attached, 'created' => $created,
            'lines' => array_map(fn ($l) => \App\Services\CommandeService\CommandeProductAttachmentService::lineArray($l), $em->getRepository(\App\Entity\CommandeLine::class)->forCommande($commande)), // à recevoir (lot R1)
            'shipping' => $fraisDePort !== null ? new FraisDePortOutputDTO($fraisDePort, $host, $currency) : null,
            'confirmed' => ['supplier' => ['id' => $supplier->getId(), 'name' => $supplier->getName()], 'invoice' => ['number' => $number, 'date' => $invoiceDate->format('Y-m-d'), 'exchangeRate' => $exchangeRate],
                'classificationDate' => $date->format('Y-m-d'), 'lines' => $lines, 'shipping' => $shippingAmount !== null ? ['amount' => $shippingAmount, 'transporteurId' => $fraisDePort?->getTransporteur()?->getId()] : null, 'force' => $force,
                // Ce que cette confirmation a créé, pour l'annuler d'un bloc et constater ce qui a bougé depuis (lot IA-FACT-1, GestionDocumentUndoService)
                'undo' => [
                    'commandeId' => $commande->getId(), 'supplierId' => $supplierCreated ? $supplier->getId() : null,
                    'fraisDePort' => $fraisDePort !== null ? ['id' => $fraisDePort->getId(), 'price' => $fraisDePort->getPrice()] : null,
                    'lines' => array_map(fn (\App\Entity\CommandeLine $l) => ['id' => $l->getId(), 'productId' => $l->getProduct()?->getId(), 'variantId' => $l->getVariant()?->getId(), 'quantity' => $l->getQuantityOrdered(), 'unitCost' => $l->getUnitCostCents()],
                        $em->getRepository(\App\Entity\CommandeLine::class)->forCommande($commande)),
                    'products' => array_map(fn (Product $p) => ['id' => $p->getId(), 'name' => $p->getName(), 'price' => (float) $p->getPrice(), 'purchasePrice' => (float) $p->getPurchasePrice(),
                        'variantIds' => array_values(array_map(fn (ProductVariant $v) => $v->getId(), $p->getVariants()->toArray()))], $createdProducts),
                ]],
        ];
    }

    /** @param list<array> $group lignes de même groupKey */
    private function createProduct(EntityManagerInterface $em, GestionDocument $document, Commande $commande, array $group, string $host, \DateTimeImmutable $now, ?string $user = null, float $rate = 1.0): Product
    {
        $first = $group[0];
        $quantity = array_sum(array_column($group, 'quantity'));
        // Prix de vente et prix d'achat de la fiche dans la devise du site : la règle de l'aperçu (GestionInvoiceDraft::groupPrice). La ligne
        // d'achat garde le coût dans la devise de la commande (lot G6).
        $pricing = GestionInvoiceDraft::groupPrice($group, $rate);
        $cost = $pricing['cost'];
        $coefficient = $pricing['coefficient'];
        $name = (string) $first['create']['name'];
        $product = (new Product())->setName($name)->setDescription(null)->setPurchasePrice((float) $pricing['costConverted'])->setCoefficientMultiplier($coefficient)->setPrice((float) $pricing['price'])
            ->setQuantity(0)->setSlug($this->uniqueSlug($em, $name))->setCreatedAt($now)->setIsWeb(false)->setIsPos(false)->setIsPreOrder(false); // stock 0 : il n'entre qu'à la réception (lot R1)
        $em->persist($product);
        $withAttributes = array_filter($group, fn ($l) => ($l['size'] ?? null) !== null || ($l['color'] ?? null) !== null);
        $variants = [];
        if ($withAttributes !== [] || count($group) > 1) {
            foreach ($group as $line) {
                $variant = (new ProductVariant())->setStockQuantity(0)->setPrice($pricing['price']);
                if (($line['size'] ?? null) !== null) {
                    $variant->setSize($this->size($em, $line['size']));
                }
                if (($line['color'] ?? null) !== null) {
                    $variant->setColor($this->color($em, $line['color']));
                }
                $variant->setProduct($product);
                $product->addVariant($variant);
                $em->persist($variant);
                $variants[$line['n']] = $variant;
            }
        }
        // Photo de ligne → image de l'article (médiathèque publique), facultative
        foreach ($group as $line) {
            $photo = $document->getFiles()['linePhotos'][(string) $line['n']] ?? null;
            if ($photo !== null && $this->upload !== null) {
                $path = $this->storage->path($document, $photo['file']);
                if (is_file($path)) {
                    $tmp = tempnam(sys_get_temp_dir(), 'ligne');
                    copy($path, $tmp);
                    try {
                        $media = $this->upload->execute(new UploadedFile($tmp, $photo['name'], $photo['mime'], null, true), $name, false, $host);
                        $product->setImage($media->url);
                        $product->addPicture((new ProductPicture())->setImageUrl($media->url));
                    } catch (\Throwable) {
                        // la photo n'est jamais bloquante : l'article est créé sans image
                    }
                }
                break;
            }
        }

        $em->flush();
        // lignes d'achat à recevoir : une par variante, sinon une pour l'article
        if ($variants !== []) {
            foreach ($group as $line) {
                $this->lines->upsert($commande, $product, $variants[$line['n']], (int) $line['quantity'], (int) $line['unitCost'], false, \App\Entity\CommandeLine::SOURCE_DOCUMENT, $now, 0, $user);
            }
        } else {
            $this->lines->upsert($commande, $product, null, (int) $quantity, $cost, false, \App\Entity\CommandeLine::SOURCE_DOCUMENT, $now, 0, $user);
        }

        return $product;
    }

    // ----- note de frais -----

    /** @param array<string, mixed> $body */
    private function note(EntityManagerInterface $em, GestionDocument $document, array $body, string $host): array
    {
        if (($body['as'] ?? null) === 'campaign') { // facture d'une régie publicitaire : campagne au lieu d'une note de frais (lot A1)
            $campaign = $this->campaigns->create($body, $document, null);
            $document->setCampaignId($campaign->getId());
            $em->flush();

            return ['campaign' => $this->campaigns->toArray($campaign), 'confirmed' => ['as' => 'campaign', 'campaignId' => $campaign->getId()] + array_intersect_key($body, array_flip(['name', 'channel', 'startDate', 'endDate', 'amount', 'currency', 'exchangeRate']))];
        }
        $input = new GestionInput($body);
        $montant = $input->cents('montant');
        $date = $input->date('date');
        $classification = $input->date('classificationDate', false);
        $description = $input->text('description', false, 500);
        $typeId = $input->id('typeNoteDeFraisId');
        $input->check();
        $classified = $classification ?? $date;
        $period = $this->guard->assertDateWritable($classified);
        if ($period === null) {
            throw new GestionValidationException([$classification !== null ? 'classificationDate' : 'date' => 'OUT_OF_CALENDAR'], 'Aucune période du calendrier ne couvre cette date');
        }
        $note = $this->notes->createNoteDeFrais($period, new NoteDeFraisInputDTO($description ?? ($input->text('name', false) ?? 'Note de frais'), (int) $montant, $classified->format('Y-m-d'), (int) $typeId));
        if ($name = $input->text('name', false)) {
            $note->setName($name);
        }
        $note->setDocument($document);
        $document->setNoteDeFraisId($note->getId());
        $em->flush();

        return [
            'noteDeFrais' => new NoteDeFraisOutputDTO($note, $host, $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT),
            'confirmed' => ['montant' => $montant, 'date' => $date->format('Y-m-d'), 'classificationDate' => $classified->format('Y-m-d'), 'description' => $description, 'typeNoteDeFraisId' => $typeId],
        ];
    }

    // ----- facture de transport -----

    /** @param array<string, mixed> $body */
    private function transport(EntityManagerInterface $em, GestionDocument $document, array $body, string $host): array
    {
        $input = new GestionInput($body);
        $commandeId = $input->id('commandeId');
        $transporteurId = $input->id('transporteurId');
        $price = $input->cents('price');
        $name = $input->text('name', false);
        $tracknumber = $input->text('tracknumber', false, 120);
        $facture = $input->text('facture', false, 120);
        $input->check();
        $force = ($body['force'] ?? false) === true;
        $commande = $em->find(Commande::class, $commandeId);
        if ($commande === null) {
            throw new GestionConflictException('COMMANDE_NOT_FOUND', 'Commande inconnue : ' . $commandeId, 404);
        }
        $extraction = $document->getExtraction() ?? [];
        $number = $facture ?? ($extraction['invoice']['number'] ?? null);
        $carrierId = (int) ($extraction['carrier']['id'] ?? 0) ?: (int) $transporteurId;
        if ($number !== null && !$force) {
            /** @var GestionDocumentRepository $repo */
            $repo = $em->getRepository(GestionDocument::class);
            $other = $repo->findDuplicate(GestionDocument::TYPE_TRANSPORT, 'carrier', $carrierId, (string) $number, $document->getId());
            if ($other !== null && $other->isConfirmed()) {
                throw new GestionConflictException('INVOICE_ALREADY_CONFIRMED', sprintf('La facture de transport %s est déjà enregistrée.', $number), 409, ['documentId' => $other->getId(), 'fraisDePortId' => $other->getFraisDePortId()]);
            }
        }
        $transporteur = $em->find(Transporteur::class, $transporteurId);
        if ($transporteur === null) {
            throw new GestionConflictException('TRANSPORTEUR_NOT_FOUND', 'Transporteur inconnu : ' . $transporteurId, 404);
        }
        $label = $name ?? ('Transport ' . ($number ?? '') . ' — ' . $transporteur->getName());
        $fraisDePort = $this->shipping->createFraisDePort($commande, new FraisDePortInputDTO($label, (string) ($number ?? ''), (string) ($tracknumber ?? ''), (int) $price, (int) $transporteurId));
        $fraisDePort->setDocument($document);
        $document->setFraisDePortId($fraisDePort->getId())->setCommandeId($commande->getId());
        $em->flush();
        $currency = $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;

        return [
            'fraisDePort' => new FraisDePortOutputDTO($fraisDePort, $host, $currency), 'commande' => new CommandeOutputDTO($commande, $host, $currency),
            'confirmed' => ['commandeId' => $commandeId, 'transporteurId' => $transporteurId, 'price' => $price, 'name' => $label, 'tracknumber' => $tracknumber, 'carrier' => ['id' => $transporteur->getId(), 'name' => $transporteur->getName()],
                'invoice' => ['number' => $number, 'date' => $extraction['invoice']['date'] ?? null], 'force' => $force],
        ];
    }

    // ----- aides -----

    private function invoiceCarrier(EntityManagerInterface $em): Transporteur
    {
        $carrier = $em->getRepository(Transporteur::class)->findOneBy(['name' => self::SHIPPING_CARRIER]);
        if ($carrier === null) {
            $carrier = (new Transporteur())->setName(self::SHIPPING_CARRIER);
            $em->persist($carrier);
            $em->flush();
        }

        return $carrier;
    }

    private function line(array $lines, int $n): array
    {
        foreach ($lines as $line) {
            if ($line['n'] === $n) {
                return $line;
            }
        }
        throw new \LogicException("ligne $n");
    }

    /** @param array<string, string> $errors */
    private function prefix(string $prefix, array $errors): array
    {
        $out = [];
        foreach ($errors as $k => $v) {
            $out["$prefix.$k"] = $v;
        }

        return $out;
    }

    private function size(EntityManagerInterface $em, string $name): Size
    {
        $found = $em->getRepository(Size::class)->createQueryBuilder('s')->where('LOWER(s.name) = :n')->setParameter('n', mb_strtolower($name))->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($found instanceof Size) {
            return $found;
        }
        $size = (new Size())->setName($name)->setCode($this->attributeCode($name));
        $em->persist($size);
        $em->flush();

        return $size;
    }

    private function color(EntityManagerInterface $em, string $name): Color
    {
        $found = $em->getRepository(Color::class)->createQueryBuilder('c')->where('LOWER(c.name) = :n')->setParameter('n', mb_strtolower($name))->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($found instanceof Color) {
            return $found;
        }
        $color = (new Color())->setName($name)->setCode($this->attributeCode($name));
        $em->persist($color);
        $em->flush();

        return $color;
    }

    private function attributeCode(string $name): string
    {
        return mb_substr(strtoupper((new AsciiSlugger('fr'))->slug($name, '_')->toString() ?: 'X'), 0, 30);
    }

    private function uniqueSlug(EntityManagerInterface $em, string $name): string
    {
        $base = (new AsciiSlugger('fr'))->slug($name)->lower()->toString() ?: 'produit';
        $slug = $base;
        $repo = $em->getRepository(Product::class);
        for ($i = 2; $repo->findOneBy(['slug' => $slug]) !== null && $i < 1000; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
