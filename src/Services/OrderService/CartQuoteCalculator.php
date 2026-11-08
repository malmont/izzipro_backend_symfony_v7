<?php

namespace App\Services\OrderService;

use App\Dto\CartQuoteInputDto;
use App\Entity\BookingConfiguration;
use App\Entity\Carrier;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\RentalPack;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Devis d'un panier, seule source de vérité des montants (cents) : lignes (vente ou location), livraison, taxes,
 * caution, total. Utilisé par POST /api/cart/quote, par create-intent (montant autorisé) et par la création de
 * commande (prix des lignes et frais de livraison) : le navigateur n'envoie aucun montant d'article.
 *
 * Vente : prix de la variante (product_variant.price) sinon prix effectif du produit (promotion en cours) ; stock de la
 * variante vérifié. Location (RentalLineResolver) : rate × unités, le tarif venant du forfait demandé (rateId) ou du
 * premier forfait des catégories du produit ; durationType (hour, halfDay, day, week, month) envoyé, sinon hour pour
 * une grille horaire et day pour une grille à la journée ; unités = durée arrondie au-dessus en longueurs d'unité
 * (heure 1 h, demi-journée 4 h, jour, semaine 7 j, mois 30 j). Règles de la configuration : durée minimale et maximale,
 * jours minimum à la journée, dates autorisées, heures d'ouverture (ou créneau du soir), disponibilité ; frais par
 * passager ajoutés à la ligne ; caution rapportée à part, hors total.
 *
 * Erreurs (02/11/2026) : chaque entrée de errors[] porte un code stable (CartQuoteException::error : QUANTITY_INVALID,
 * STOCK_INSUFFICIENT {available}, DURATION_TOO_LONG {value, unit}…) que les applications traduisent elles-mêmes ; les messages
 * français et le message de premier niveau ne changent pas. Table : docs/boutique.md, « Codes du devis, de l'adresse et de la commande ».
 *
 * Livraison : 0 sans transporteur ; prix fixe du transporteur ; pour un transporteur EasyPost (carrierAccountId),
 * le tarif choisi envoyé par le navigateur (shippingPrice, cents). Taxes : TaxEngine selon l'adresse de livraison
 * (table du site par région, ou Stripe Tax) ; sans adresse, aucune taxe et taxStatus address_required. Retrait en magasin (B20,
 * 08/11/2026) : taxes à l'adresse de la fiche entreprise (lieu de remise), avec ou sans adresse du client ; `taxBasis: store`.
 */
final class CartQuoteCalculator
{
    /** Longueur d'une unité de facturation, en heures ou en jours */
    private const UNITS = ['hour' => [1, 'h'], 'halfDay' => [4, 'h'], 'day' => [1, 'd'], 'week' => [7, 'd'], 'month' => [30, 'd']];
    private const HOURLY = ['hours', 'minutes_30', 'minutes_15'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookingAvailabilityService $availability,
        private readonly TenantCurrencyProvider $currency,
        private readonly TaxEngine $taxes,
        private readonly ?\App\Services\Booking\BookingClock $clock = null, // fuseau de la boutique (04/11/2026)
        private readonly ?\App\Services\BoutiqueSettingsService\BoutiqueSettingsService $settings = null, // livraison offerte (lot 1, 07/11/2026)
        private readonly ?OrderTaxAddressResolver $taxAddress = null // retrait en magasin : taxes du lieu de remise (B20, 08/11/2026)
    ) {
    }

    /**
     * @return array{lines: list<array<string, mixed>>, subtotal: int, shipping: int, taxes: list<array{label: string, rate: float, amount: int, jurisdiction?: string}>, taxStatus: string, taxProvider: string, taxCalculationId: ?string, deposit: int, total: int, currency: string, shippingAddress: ?array, carrier: ?array{id: int, name: ?string, isFree: bool}}
     * @throws CartQuoteException
     */
    public function quote(CartQuoteInputDto $input): array
    {
        $em = $this->emProvider->getEntityManager();
        $lines = [];
        $errors = [];
        $subtotal = $deposit = 0;
        foreach ($input->items as $i => $item) {
            $path = "items[$i]";
            $quantity = $item['quantity'] ?? null;
            if (!is_numeric($quantity) || (int) $quantity < 1 || (int) $quantity != $quantity) {
                $errors[] = CartQuoteException::error("$path.quantity", 'QUANTITY_INVALID', 'entier positif attendu');
                continue;
            }
            $quantity = (int) $quantity;
            // Article libre (F7, caisse seulement : la clé est retirée hors caisse par CartQuoteInputDto) : libellé, prix saisi, taxable ou non, sans variante ni stock
            if (is_array($item['custom'] ?? null)) {
                $custom = \App\Services\PosLineService\PosLineService::customLine($item['custom'], $path, $errors);
                if ($custom === null) {
                    continue;
                }
                $lines[] = $custom + ['productVariantId' => null, 'productId' => null, 'kind' => 'sale', 'quantity' => $quantity, 'total' => $custom['unitPrice'] * $quantity,
                    'taxCategory' => $custom['custom']['taxable'] ? ($custom['custom']['taxCategory'] ?? TaxCategory::STANDARD) : TaxCategory::EXEMPT]; // catégorie fiscale (F18 partie 2a)
                $subtotal += $custom['unitPrice'] * $quantity;
                continue;
            }
            $variant = is_numeric($item['productVariantId'] ?? null) ? $em->getRepository(ProductVariant::class)->find((int) $item['productVariantId']) : null;
            $product = $variant?->getProduct();
            if ($variant === null || $product === null) {
                throw new CartQuoteException(404, "$path.productVariantId : variante introuvable", [CartQuoteException::error("$path.productVariantId", 'VARIANT_NOT_FOUND', 'variante introuvable')]);
            }
            $booking = $item['booking'] ?? $item['rental'] ?? null;
            $isRental = RentalLineResolver::isRental($product, $item);
            if ($isRental && !is_array($booking)) {
                $errors[] = CartQuoteException::error("$path.booking", 'BOOKING_REQUIRED', 'ce produit se loue seulement : dates de réservation attendues (start, end)');
                continue;
            }
            if (!$isRental && is_array($booking) && $booking !== [] && !$product->isRentalEnabled()) {
                $errors[] = CartQuoteException::error("$path.booking", 'NOT_RENTABLE', 'ce produit ne se loue pas');
                continue;
            }
            if (!$isRental && !$product->isSaleEnabled()) {
                $errors[] = CartQuoteException::error($path, 'NOT_SELLABLE', 'ce produit ne se vend pas');
                continue;
            }

            $line = [
                'productVariantId' => $variant->getId(), 'productId' => $product->getId(), 'name' => $product->getName(),
                'kind' => $isRental ? 'rental' : 'sale', 'quantity' => $quantity,
                'taxCategory' => TaxCategory::of($product->getTaxCategory()), // catégorie fiscale du produit (F18 partie 2a) ; un prix manuel la garde
            ];
            if ($isRental) {
                $priced = $this->rental($product, $booking, $quantity, $path, $errors);
                if ($priced === null) {
                    continue;
                }
                $line += $priced;
                $deposit += $priced['booking']['deposit'] * $quantity;
            } else {
                if ($variant->getStockQuantity() < $quantity) {
                    $errors[] = CartQuoteException::error("$path.quantity", 'STOCK_INSUFFICIENT', sprintf('stock insuffisant (%d disponible(s))', $variant->getStockQuantity()), ['available' => (int) $variant->getStockQuantity()]);
                    continue;
                }
                $line['unitPrice'] = (int) round($variant->getPrice() ?? $product->getEffectivePrice());
                $customizationId = is_numeric($item['customizationId'] ?? null) ? (int) $item['customizationId'] : null;
                $combination = $customizationId !== null ? $variant->getProductCustomizationImages()->filter(fn ($c) => $c->getId() === $customizationId)->first() : null;
                if ($customizationId !== null && !$combination) {
                    $errors[] = CartQuoteException::error("$path.customizationId", 'CUSTOMIZATION_NOT_FOUND', 'combinaison de personnalisation inconnue pour cette variante');
                    continue;
                }
                // Personnalisation : supplément des options de la combinaison ajouté au prix unitaire (devis, intent, ligne)
                $line['customizationId'] = $customizationId;
                $line['customizationPrice'] = $combination ? $combination->priceDeltaCents() : 0;
                $line['unitPrice'] += $line['customizationPrice'];
                // Prix manuel (F7, caisse seulement) : prix catalogue remplacé sur la ligne, prix catalogue et motif gardés
                if (is_array($item['priceOverride'] ?? null)) {
                    $override = \App\Services\PosLineService\PosLineService::priceOverride($item['priceOverride'], $line['unitPrice'], $quantity, $path, $errors);
                    if ($override === null) {
                        continue;
                    }
                    $line['catalogUnitPrice'] = $line['unitPrice'];
                    $line['unitPrice'] = $override['unitPrice'];
                    $line['priceOverride'] = $override;
                }
            }
            $line['total'] = $line['unitPrice'] * $quantity;
            $subtotal += $line['total'];
            $lines[] = $line;
        }
        if ($errors) {
            throw new CartQuoteException(422, $errors[0]['path'] . ' : ' . $errors[0]['message'] . (count($errors) > 1 ? sprintf(' (et %d autre(s) erreur(s))', count($errors) - 1) : ''), $errors);
        }

        [$shipping, $carrier] = $this->shipping($input, $subtotal);
        // Retrait en magasin (B20, 08/11/2026) : le bien est remis au magasin, les taxes suivent la province de la boutique (règle du lieu de
        // fourniture, comme une vente au comptoir), que le client ait donné une adresse ou non ; sans fiche entreprise, l'adresse donnée
        [$taxAddress, $taxBasis] = [$input->address, 'shipping'];
        if ($carrier?->isPickup() && $this->taxAddress !== null) {
            $store = $this->taxAddress->forStore();
            if ($store !== null && ($store['country'] ?? null) !== null) {
                [$taxAddress, $taxBasis] = [TaxEngine::normalizeAddress($store), 'store'];
            }
        }
        $currency = $this->currency->code();
        // Remises de caisse (F1, 24/10/2026) : seulement si des remises sont demandées (le web n'en envoie jamais : calcul inchangé) ;
        // taxes calculées sur le NET de chaque ligne
        $discounts = null;
        if (\App\Services\PosDiscountService\PosDiscountCalculator::hasDiscounts($input->items, $input->ticketDiscount)) {
            $applied = \App\Services\PosDiscountService\PosDiscountCalculator::apply($lines, array_values($input->items), $input->ticketDiscount);
            $lines = $applied['lines'];
            $discounts = $applied['summary'];
            $subtotal = (int) array_sum(array_map(fn ($l) => (int) $l['total'], $lines));
        }
        // Article libre non taxable (F7) : ligne exclue de l'assiette ; les lignes du catalogue restent taxables comme avant
        $tax = $this->taxes->compute($taxAddress, array_map(fn ($line, $i) => ($discounts !== null && ($line['kind'] ?? 'sale') === 'sale' ? [
            'amount' => (int) $line['total'], 'quantity' => 1, 'kind' => $line['kind'], 'reference' => sprintf('line-%d-variant-%d', $i, $line['productVariantId']),
        ] : [
            'amount' => (int) $line['unitPrice'], 'quantity' => (int) $line['quantity'], 'kind' => $line['kind'], 'reference' => sprintf('line-%d-variant-%d', $i, $line['productVariantId']),
        ]) + (isset($line['custom']) && !$line['custom']['taxable'] ? ['taxable' => false] : []) + ['taxCategory' => $line['taxCategory'] ?? TaxCategory::STANDARD], $lines, array_keys($lines)), $shipping, $currency);
        $posLines = \App\Services\PosLineService\PosLineService::summary($lines); // résumés (F7) présents seulement si la vente en contient

        return ($discounts !== null ? ['discounts' => $discounts, 'grossSubtotal' => $discounts['gross']] : []) + $posLines + [
            'lines' => $lines, 'subtotal' => $subtotal, 'shipping' => $shipping,
            'taxes' => $tax['taxes'], 'taxStatus' => $tax['status'], 'taxProvider' => $tax['provider'],
            'pricesIncludeTax' => (bool) ($tax['inclusive'] ?? false), // F18 partie 2b : TTC → les taxes sont dans subtotal et shipping, total = subtotal + shipping
            'taxCalculationId' => $tax['calculationId'], 'deposit' => $deposit,
            'total' => $subtotal + $shipping + (($tax['inclusive'] ?? false) ? 0 : $tax['total']), 'currency' => $currency,
            'shippingAddress' => $input->address,
            'taxAddress' => $taxAddress, 'taxBasis' => $taxBasis, // adresse qui a servi aux taxes : 'store' (retrait) ou 'shipping' (B20)
            'carrier' => $carrier ? ['id' => (int) $carrier->getId(), 'name' => $carrier->getName(), 'isFree' => $carrier->isFree(), 'kind' => $carrier->getKind(), 'freeShippingApplied' => $this->freeShippingApplied($carrier, $subtotal)] : null,
        ];
    }

    /**
     * Ligne de location : prix unitaire (par quantité) et détail de la réservation ; null si une règle est violée
     * (erreurs ajoutées à $errors).
     *
     * @param array<string, mixed> $booking
     * @param list<array{path: string, message: string, code: string, params?: array<string, mixed>}> $errors
     * @return array{unitPrice: int, booking: array<string, mixed>}|null
     */
    private function rental(Product $product, array $booking, int $quantity, string $path, array &$errors): ?array
    {
        $config = $product->getBookingConfiguration();
        try {
            // Fuseau de la boutique (04/11/2026) : sans fuseau = heure de la boutique, avec fuseau = converti avant tout contrôle
            $start = $this->parseDate((string) ($booking['start'] ?? ''));
            $end = $this->parseDate((string) ($booking['end'] ?? ''));
        } catch (\Exception) {
            $errors[] = CartQuoteException::error("$path.booking", 'BOOKING_DATES_INVALID', 'dates start et end attendues (ISO 8601)');

            return null;
        }
        if ($end <= $start) {
            $errors[] = CartQuoteException::error("$path.booking.end", 'BOOKING_END_BEFORE_START', 'la fin doit suivre le début');

            return null;
        }
        $granularity = $config?->getGranularity() ?? 'days';
        $datetime = $granularity === 'datetime'; // prise et retour avec date et heure (08/11/2026) : durées en heures, formules jour/semaine/mois, tolérance de retard
        $hourly = $datetime || in_array($granularity, self::HOURLY, true);
        $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        // Heures facturées : la tolérance de retard (grille datetime) n'est pas comptée ; les unités partent de là
        $billableHours = max(0.0, $hours - ($datetime ? (int) ($config?->getReturnToleranceMinutes() ?? 0) / 60 : 0));
        $days = (int) ceil($billableHours / 24);
        $measure = $hourly ? $hours : $days; // durée dans l'unité de la grille (contrôles min/max sur la durée réelle)

        $type = $booking['durationType'] ?? $booking['duration_type'] ?? ($hourly && !$datetime ? 'hour' : 'day');
        $type = match ($type) { 'half_day' => 'halfDay', default => $type };
        if (!isset(self::UNITS[$type])) {
            $errors[] = CartQuoteException::error("$path.booking.durationType", 'DURATION_TYPE_INVALID', 'valeur attendue : hour, halfDay, day, week, month', ['allowed' => array_keys(self::UNITS)]);

            return null;
        }
        $before = count($errors);
        if ($config !== null) {
            $this->checkConfiguration($config, $hourly, $measure, $days, $start, $end, $path, $errors);
            if ($datetime) {
                $this->checkPickupAndReturn($config, $start, $end, $path, $errors);
            }
        }
        $remaining = $this->availability->getRemainingStock($product, \App\Services\Booking\BookingClock::utc($start), \App\Services\Booking\BookingClock::utc($end)); // requête en UTC, comme la base
        if ($remaining < $quantity) {
            $errors[] = CartQuoteException::error("$path.quantity", 'DATES_UNAVAILABLE', sprintf('indisponible sur ces dates : il reste %d place(s)', max(0, $remaining)), ['remaining' => max(0, $remaining)]) + ['remaining' => max(0, $remaining)];
        }

        $rateId = $booking['rateId'] ?? $booking['rentalPackId'] ?? null;
        [$pack, $packError] = $this->pack($product, $rateId);
        if ($packError !== null) {
            // RATE_UNKNOWN : rateId envoyé mais étranger au produit ; NO_RENTAL_RATE : le produit n'a aucun forfait
            $errors[] = $rateId !== null && $rateId !== ''
                ? CartQuoteException::error("$path.booking.rateId", 'RATE_UNKNOWN', $packError, ['rateId' => is_numeric($rateId) ? (int) $rateId : (string) $rateId])
                : CartQuoteException::error("$path.booking.rateId", 'NO_RENTAL_RATE', $packError);
        }
        if (count($errors) > $before) {
            return null;
        }
        [$length, $in] = self::UNITS[$type];
        $units = max(1, (int) ceil(($in === 'h' ? $billableHours : $days) / $length));
        $rate = (int) round($this->rate($pack, $type));
        if ($rate <= 0) {
            $errors[] = CartQuoteException::error("$path.booking.durationType", 'RATE_NOT_FOUND', sprintf('aucun tarif « %s » pour ce produit', $type), ['durationType' => $type]);

            return null;
        }
        $passengers = max(0, (int) ($booking['passengers'] ?? 0));
        $passengerFee = (int) ($config?->getExtraPassengerFee() ?? 0) * $passengers;

        return [
            'unitPrice' => $rate * $units + $passengerFee,
            'booking' => [
                'start' => $start->format(\DateTimeInterface::ATOM), 'end' => $end->format(\DateTimeInterface::ATOM), // ATOM dans le fuseau de la boutique
                'rateId' => $pack?->getId(), 'rateName' => $pack?->getName(), 'durationType' => $type, 'units' => $units, 'rate' => $rate,
                'passengers' => $passengers, 'passengerFee' => $passengerFee, 'deposit' => (int) ($config?->getDeposit() ?? 0),
            ],
        ];
    }

    /** @throws \Exception chaîne illisible */
    private function parseDate(string $value): \DateTimeImmutable
    {
        return $this->clock?->parse($value) ?? new \DateTimeImmutable($value);
    }

    /** @param list<array{path: string, message: string, code: string, params?: array<string, mixed>}> $errors */
    private function checkConfiguration(BookingConfiguration $config, bool $hourly, float $measure, int $days, \DateTimeImmutable $start, \DateTimeImmutable $end, string $path, array &$errors): void
    {
        $unit = $hourly ? 'heure(s)' : 'jour(s)';
        $unitCode = $hourly ? 'hour' : 'day'; // params.unit : unité de la grille, dans le vocabulaire de durationType
        if ($config->getMinDuration() !== null && $measure < $config->getMinDuration()) {
            $errors[] = CartQuoteException::error("$path.booking.end", 'DURATION_TOO_SHORT', sprintf('durée minimale : %d %s', $config->getMinDuration(), $unit), ['value' => (int) $config->getMinDuration(), 'unit' => $unitCode]);
        }
        if ($config->getMaxDuration() !== null && $measure > $config->getMaxDuration()) {
            $errors[] = CartQuoteException::error("$path.booking.end", 'DURATION_TOO_LONG', sprintf('durée maximale : %d %s', $config->getMaxDuration(), $unit), ['value' => (int) $config->getMaxDuration(), 'unit' => $unitCode]);
        }
        // Jours minimum facturés : grille à la journée, et grille datetime sur les jours facturés après tolérance (08/11/2026) ; jamais sur les grilles à l'heure
        if ((!$hourly || $config->isDatetime()) && $config->getMinDaysStandard() !== null && $days < $config->getMinDaysStandard()) {
            $errors[] = CartQuoteException::error("$path.booking.end", 'DAYS_MINIMUM', sprintf('%d jour(s) minimum (facturés)', $config->getMinDaysStandard()), ['value' => (int) $config->getMinDaysStandard()]);
        }
        $allowed = $config->getAllowedDates() ?: null;
        if ($allowed !== null && !in_array($start->format('Y-m-d'), $allowed, true)) {
            $errors[] = CartQuoteException::error("$path.booking.start", 'DATE_NOT_OFFERED', 'date non proposée (voir rental.allowedDates)');
        }
        if ($hourly && !$config->isDatetime() && $config->getOpeningStart() !== null && $config->getOpeningEnd() !== null) {
            $from = $start->format('H:i');
            $to = $end->format('H:i');
            $inside = fn (string $open, string $close) => $from >= $open && ($to <= $close || $to === '00:00') && $start->format('Y-m-d') === $end->modify('-1 second')->format('Y-m-d');
            $evening = $config->getEveningSlot();
            if (!$inside($config->getOpeningStart(), $config->getOpeningEnd()) && !(is_array($evening) && isset($evening['start'], $evening['end']) && $inside((string) $evening['start'], (string) $evening['end']))) {
                $hasEvening = is_array($evening) && isset($evening['start'], $evening['end']);
                $errors[] = CartQuoteException::error("$path.booking.start", 'OUTSIDE_OPENING_HOURS', sprintf('créneau hors des heures d\'ouverture (%s à %s%s)', $config->getOpeningStart(), $config->getOpeningEnd(),
                    $hasEvening ? sprintf(', ou %s à %s', $evening['start'], $evening['end']) : ''),
                    ['start' => $config->getOpeningStart(), 'end' => $config->getOpeningEnd()] + ($hasEvening ? ['eveningStart' => (string) $evening['start'], 'eveningEnd' => (string) $evening['end']] : []));
            }
        }
    }

    /**
     * Grille `datetime` (08/11/2026) : la prise et le retour tombent chacun dans les heures d'ouverture (ou le créneau du soir), n'importe
     * quel jour ; les heures suivent le pas réglé (60 min par défaut).
     *
     * @param list<array{path: string, message: string, code: string, params?: array<string, mixed>}> $errors
     */
    private function checkPickupAndReturn(BookingConfiguration $config, \DateTimeImmutable $start, \DateTimeImmutable $end, string $path, array &$errors): void
    {
        $step = (int) ($config->effectiveTimeStepMinutes() ?? 60);
        foreach (['start' => $start, 'end' => $end] as $which => $moment) {
            if ($step > 0 && (((int) $moment->format('H') * 60 + (int) $moment->format('i')) % $step !== 0 || (int) $moment->format('s') !== 0)) {
                $errors[] = CartQuoteException::error("$path.booking.$which", 'TIME_STEP_INVALID', sprintf('heure attendue par pas de %d minutes', $step), ['stepMinutes' => $step]);
            }
        }
        if ($config->getOpeningStart() === null || $config->getOpeningEnd() === null) {
            return;
        }
        $evening = $config->getEveningSlot();
        $hasEvening = is_array($evening) && isset($evening['start'], $evening['end']);
        $windows = [[$config->getOpeningStart(), $config->getOpeningEnd()]];
        if ($hasEvening) {
            $windows[] = [(string) $evening['start'], (string) $evening['end']];
        }
        $inside = function (\DateTimeImmutable $moment) use ($windows): bool {
            $t = $moment->format('H:i');
            foreach ($windows as [$open, $close]) {
                if ($t >= $open && ($t <= $close || $close === '00:00')) {
                    return true;
                }
            }

            return false;
        };
        $params = ['start' => $config->getOpeningStart(), 'end' => $config->getOpeningEnd()] + ($hasEvening ? ['eveningStart' => (string) $evening['start'], 'eveningEnd' => (string) $evening['end']] : []);
        $label = sprintf('%s à %s%s', $config->getOpeningStart(), $config->getOpeningEnd(), $hasEvening ? sprintf(', ou %s à %s', $evening['start'], $evening['end']) : '');
        if (!$inside($start)) {
            $errors[] = CartQuoteException::error("$path.booking.start", 'OUTSIDE_PICKUP_HOURS', sprintf('heure de prise hors des heures d\'ouverture (%s)', $label), $params);
        }
        if (!$inside($end)) {
            $errors[] = CartQuoteException::error("$path.booking.end", 'OUTSIDE_RETURN_HOURS', sprintf('heure de retour hors des heures d\'ouverture (%s)', $label), $params);
        }
    }

    /** @return array{0: ?RentalPack, 1: ?string} forfait retenu, erreur */
    private function pack(Product $product, mixed $rateId): array
    {
        $packs = $product->getRentalPacks();
        if ($rateId !== null && $rateId !== '') {
            foreach ($packs as $pack) {
                if ((int) $pack->getId() === (int) $rateId) {
                    return [$pack, null];
                }
            }

            return [null, 'tarif inconnu pour ce produit (voir rental.rates)'];
        }
        if ($packs === []) {
            return [null, 'aucun tarif de location pour ce produit'];
        }
        ksort($packs);

        return [reset($packs), null];
    }

    private function rate(?RentalPack $pack, string $type): float
    {
        return (float) match ($type) {
            'hour' => $pack?->getHourRate(), 'halfDay' => $pack?->getHalfDayRate(), 'day' => $pack?->getDayRate(),
            'week' => $pack?->getWeekRate(), 'month' => $pack?->getMonthRate(), default => 0,
        };
    }

    /**
     * @param int $subtotal lignes seules, en cents (livraison offerte au-dessus d'un montant, lot 1, 07/11/2026)
     * @return array{0: int, 1: ?Carrier} frais de livraison en cents, transporteur
     */
    private function shipping(CartQuoteInputDto $input, int $subtotal = 0): array
    {
        if ($input->carrierId === null) {
            return [0, null];
        }
        $carrier = $this->emProvider->getEntityManager()->getRepository(Carrier::class)->find($input->carrierId);
        if ($carrier === null) {
            throw new CartQuoteException(404, 'carrierId : transporteur introuvable', [CartQuoteException::error('carrierId', 'CARRIER_NOT_FOUND', 'transporteur introuvable')]);
        }
        // Retrait en magasin : jamais de frais ; livraison offerte à partir d'un montant de lignes (réglage commerce.freeShippingAbove)
        if ($carrier->isPickup() || $this->freeShippingApplied($carrier, $subtotal)) {
            return [0, $carrier];
        }
        // Transporteur EasyPost : le tarif dépend des colis et de l'adresse (shipping/summary) ; sinon prix fixe du transporteur
        $price = $carrier->getCarrierAccountId() ? ($input->shippingPrice ?? (int) round((float) $carrier->getPrice())) : (int) round((float) $carrier->getPrice());

        return [max(0, $price), $carrier];
    }

    /** Livraison offerte (lot 1) : seuil réglé, atteint par les lignes, transporteur payant (le retrait est déjà gratuit) */
    private function freeShippingApplied(Carrier $carrier, int $subtotal): bool
    {
        if ($carrier->isPickup() || $this->settings === null) {
            return false;
        }
        $threshold = $this->settings->shippingSettings()['freeShippingAbove'];

        return $threshold !== null && $subtotal >= $threshold && !$carrier->isFree();
    }
}
