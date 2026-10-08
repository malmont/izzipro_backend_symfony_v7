<?php

namespace App\Services\OrderService;

use App\Dto\CartQuoteInputDto;
use App\Entity\BookingConfiguration;
use App\Entity\Carrier;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\RentalPack;
use App\Entity\Tax;
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
 * Livraison : 0 sans transporteur ; prix fixe du transporteur ; pour un transporteur EasyPost (carrierAccountId),
 * le tarif choisi envoyé par le navigateur (shippingPrice, cents). Taxes : toutes celles du site, sur articles +
 * livraison (TaxCalculationService fait de même à la commande).
 */
final class CartQuoteCalculator
{
    /** Longueur d'une unité de facturation, en heures ou en jours */
    private const UNITS = ['hour' => [1, 'h'], 'halfDay' => [4, 'h'], 'day' => [1, 'd'], 'week' => [7, 'd'], 'month' => [30, 'd']];
    private const HOURLY = ['hours', 'minutes_30', 'minutes_15'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookingAvailabilityService $availability,
        private readonly TenantCurrencyProvider $currency
    ) {
    }

    /**
     * @return array{lines: list<array<string, mixed>>, subtotal: int, shipping: int, taxes: list<array{label: string, rate: float, amount: int}>, deposit: int, total: int, currency: string, carrier: ?array{id: int, name: ?string, isFree: bool}}
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
                $errors[] = ['path' => "$path.quantity", 'message' => 'entier positif attendu'];
                continue;
            }
            $quantity = (int) $quantity;
            $variant = is_numeric($item['productVariantId'] ?? null) ? $em->getRepository(ProductVariant::class)->find((int) $item['productVariantId']) : null;
            $product = $variant?->getProduct();
            if ($variant === null || $product === null) {
                throw new CartQuoteException(404, "$path.productVariantId : variante introuvable", [['path' => "$path.productVariantId", 'message' => 'variante introuvable']]);
            }
            $booking = $item['booking'] ?? $item['rental'] ?? null;
            $isRental = RentalLineResolver::isRental($product, $item);
            if ($isRental && !is_array($booking)) {
                $errors[] = ['path' => "$path.booking", 'message' => 'ce produit se loue seulement : dates de réservation attendues (start, end)'];
                continue;
            }
            if (!$isRental && is_array($booking) && $booking !== [] && !$product->isRentalEnabled()) {
                $errors[] = ['path' => "$path.booking", 'message' => 'ce produit ne se loue pas'];
                continue;
            }
            if (!$isRental && !$product->isSaleEnabled()) {
                $errors[] = ['path' => $path, 'message' => 'ce produit ne se vend pas'];
                continue;
            }

            $line = [
                'productVariantId' => $variant->getId(), 'productId' => $product->getId(), 'name' => $product->getName(),
                'kind' => $isRental ? 'rental' : 'sale', 'quantity' => $quantity,
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
                    $errors[] = ['path' => "$path.quantity", 'message' => sprintf('stock insuffisant (%d disponible(s))', $variant->getStockQuantity())];
                    continue;
                }
                $line['unitPrice'] = (int) round($variant->getPrice() ?? $product->getEffectivePrice());
                $line['customizationId'] = is_numeric($item['customizationId'] ?? null) ? (int) $item['customizationId'] : null;
            }
            $line['total'] = $line['unitPrice'] * $quantity;
            $subtotal += $line['total'];
            $lines[] = $line;
        }
        if ($errors) {
            throw new CartQuoteException(422, $errors[0]['path'] . ' : ' . $errors[0]['message'] . (count($errors) > 1 ? sprintf(' (et %d autre(s) erreur(s))', count($errors) - 1) : ''), $errors);
        }

        [$shipping, $carrier] = $this->shipping($input);
        $taxable = $subtotal + $shipping;
        $taxes = [];
        $taxTotal = 0.0;
        foreach ($em->getRepository(Tax::class)->findAll() as $tax) {
            $amount = $taxable * (float) $tax->getRate();
            $taxTotal += $amount;
            $taxes[] = ['label' => (string) $tax->getName(), 'rate' => (float) $tax->getRate(), 'amount' => (int) round($amount)];
        }

        return [
            'lines' => $lines, 'subtotal' => $subtotal, 'shipping' => $shipping, 'taxes' => $taxes, 'deposit' => $deposit,
            'total' => $taxable + (int) round($taxTotal), 'currency' => $this->currency->code(),
            'carrier' => $carrier ? ['id' => (int) $carrier->getId(), 'name' => $carrier->getName(), 'isFree' => $carrier->isFree()] : null,
        ];
    }

    /**
     * Ligne de location : prix unitaire (par quantité) et détail de la réservation ; null si une règle est violée
     * (erreurs ajoutées à $errors).
     *
     * @param array<string, mixed> $booking
     * @param list<array{path: string, message: string}> $errors
     * @return array{unitPrice: int, booking: array<string, mixed>}|null
     */
    private function rental(Product $product, array $booking, int $quantity, string $path, array &$errors): ?array
    {
        $config = $product->getBookingConfiguration();
        try {
            $start = new \DateTimeImmutable((string) ($booking['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($booking['end'] ?? ''));
        } catch (\Exception) {
            $errors[] = ['path' => "$path.booking", 'message' => 'dates start et end attendues (ISO 8601)'];

            return null;
        }
        if ($end <= $start) {
            $errors[] = ['path' => "$path.booking.end", 'message' => 'la fin doit suivre le début'];

            return null;
        }
        $granularity = $config?->getGranularity() ?? 'days';
        $hourly = in_array($granularity, self::HOURLY, true);
        $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        $days = (int) ceil($hours / 24);
        $measure = $hourly ? $hours : $days; // durée dans l'unité de la grille

        $type = $booking['durationType'] ?? $booking['duration_type'] ?? ($hourly ? 'hour' : 'day');
        $type = match ($type) { 'half_day' => 'halfDay', default => $type };
        if (!isset(self::UNITS[$type])) {
            $errors[] = ['path' => "$path.booking.durationType", 'message' => 'valeur attendue : hour, halfDay, day, week, month'];

            return null;
        }
        $before = count($errors);
        if ($config !== null) {
            $this->checkConfiguration($config, $hourly, $measure, $days, $start, $end, $path, $errors);
        }
        $remaining = $this->availability->getRemainingStock($product, $start, $end);
        if ($remaining < $quantity) {
            $errors[] = ['path' => "$path.quantity", 'message' => sprintf('indisponible sur ces dates : il reste %d place(s)', max(0, $remaining)), 'remaining' => max(0, $remaining)];
        }

        [$pack, $packError] = $this->pack($product, $booking['rateId'] ?? $booking['rentalPackId'] ?? null);
        if ($packError !== null) {
            $errors[] = ['path' => "$path.booking.rateId", 'message' => $packError];
        }
        if (count($errors) > $before) {
            return null;
        }
        [$length, $in] = self::UNITS[$type];
        $units = max(1, (int) ceil(($in === 'h' ? $hours : $days) / $length));
        $rate = (int) round($this->rate($pack, $type));
        if ($rate <= 0) {
            $errors[] = ['path' => "$path.booking.durationType", 'message' => sprintf('aucun tarif « %s » pour ce produit', $type)];

            return null;
        }
        $passengers = max(0, (int) ($booking['passengers'] ?? 0));
        $passengerFee = (int) ($config?->getExtraPassengerFee() ?? 0) * $passengers;

        return [
            'unitPrice' => $rate * $units + $passengerFee,
            'booking' => [
                'start' => $start->format(\DateTimeInterface::ATOM), 'end' => $end->format(\DateTimeInterface::ATOM),
                'rateId' => $pack?->getId(), 'rateName' => $pack?->getName(), 'durationType' => $type, 'units' => $units, 'rate' => $rate,
                'passengers' => $passengers, 'passengerFee' => $passengerFee, 'deposit' => (int) ($config?->getDeposit() ?? 0),
            ],
        ];
    }

    /** @param list<array{path: string, message: string}> $errors */
    private function checkConfiguration(BookingConfiguration $config, bool $hourly, float $measure, int $days, \DateTimeImmutable $start, \DateTimeImmutable $end, string $path, array &$errors): void
    {
        $unit = $hourly ? 'heure(s)' : 'jour(s)';
        if ($config->getMinDuration() !== null && $measure < $config->getMinDuration()) {
            $errors[] = ['path' => "$path.booking.end", 'message' => sprintf('durée minimale : %d %s', $config->getMinDuration(), $unit)];
        }
        if ($config->getMaxDuration() !== null && $measure > $config->getMaxDuration()) {
            $errors[] = ['path' => "$path.booking.end", 'message' => sprintf('durée maximale : %d %s', $config->getMaxDuration(), $unit)];
        }
        if (!$hourly && $config->getMinDaysStandard() !== null && $days < $config->getMinDaysStandard()) {
            $errors[] = ['path' => "$path.booking.end", 'message' => sprintf('%d jour(s) minimum à la journée', $config->getMinDaysStandard())];
        }
        $allowed = $config->getAllowedDates() ?: null;
        if ($allowed !== null && !in_array($start->format('Y-m-d'), $allowed, true)) {
            $errors[] = ['path' => "$path.booking.start", 'message' => 'date non proposée (voir rental.allowedDates)'];
        }
        if ($hourly && $config->getOpeningStart() !== null && $config->getOpeningEnd() !== null) {
            $from = $start->format('H:i');
            $to = $end->format('H:i');
            $inside = fn (string $open, string $close) => $from >= $open && ($to <= $close || $to === '00:00') && $start->format('Y-m-d') === $end->modify('-1 second')->format('Y-m-d');
            $evening = $config->getEveningSlot();
            if (!$inside($config->getOpeningStart(), $config->getOpeningEnd()) && !(is_array($evening) && isset($evening['start'], $evening['end']) && $inside((string) $evening['start'], (string) $evening['end']))) {
                $errors[] = ['path' => "$path.booking.start", 'message' => sprintf('créneau hors des heures d\'ouverture (%s à %s%s)', $config->getOpeningStart(), $config->getOpeningEnd(),
                    is_array($evening) && isset($evening['start'], $evening['end']) ? sprintf(', ou %s à %s', $evening['start'], $evening['end']) : '')];
            }
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

    /** @return array{0: int, 1: ?Carrier} frais de livraison en cents, transporteur */
    private function shipping(CartQuoteInputDto $input): array
    {
        if ($input->carrierId === null) {
            return [0, null];
        }
        $carrier = $this->emProvider->getEntityManager()->getRepository(Carrier::class)->find($input->carrierId);
        if ($carrier === null) {
            throw new CartQuoteException(404, 'carrierId : transporteur introuvable', [['path' => 'carrierId', 'message' => 'transporteur introuvable']]);
        }
        // Transporteur EasyPost : le tarif dépend des colis et de l'adresse (shipping/summary) ; sinon prix fixe du transporteur
        $price = $carrier->getCarrierAccountId() ? ($input->shippingPrice ?? (int) round((float) $carrier->getPrice())) : (int) round((float) $carrier->getPrice());

        return [max(0, $price), $carrier];
    }
}
