<?php

namespace App\Services\Booking;

use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Entity\RentalPack;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;

/**
 * « Ce que verra le client » (08/11/2026) : traduit une configuration de location (grille, durées, horaires, stock, packs de la catégorie,
 * caution, frais) en phrases pour l'administrateur, et signale les incohérences : formule d'un pack hors d'atteinte avec la durée
 * maximale, grille horaire avec heures d'ouverture (la location se termine le même jour : journée, semaine et mois inatteignables),
 * aucun pack pour les catégories du produit, formule de la grille sans tarif. Même règles que `CartQuoteCalculator` (durée mesurée en
 * heures sur une grille horaire, en jours à la journée ; unités : heure 1 h, demi-journée 4 h, jour, semaine 7 j, mois 30 j).
 * Utilisé par l'écran « Règles & Stocks », le questionnaire « Configurer une location » et la console Location.
 */
class RentalSummaryService
{
    public const GRIDS = ['days' => 'à la journée', 'datetime' => 'avec une date et une heure de prise, puis une date et une heure de retour', 'hours' => 'à l\'heure', 'minutes_30' => 'par tranches de 30 minutes', 'minutes_15' => 'par tranches de 15 minutes'];
    public const FORMULAS = ['hour' => 'l\'heure', 'halfDay' => 'la demi-journée', 'day' => 'la journée', 'week' => 'la semaine', 'month' => 'le mois'];
    /** longueur d'une formule, en heures */
    private const HOURS = ['hour' => 1, 'halfDay' => 4, 'day' => 24, 'week' => 168, 'month' => 720];
    private const RATE_GETTERS = ['hour' => 'getHourRate', 'halfDay' => 'getHalfDayRate', 'day' => 'getDayRate', 'week' => 'getWeekRate', 'month' => 'getMonthRate'];

    public function __construct(private readonly ?TenantCurrencyProvider $currency = null)
    {
    }

    /**
     * @return array{lines: list<string>, warnings: list<string>, formulas: array<string, array{label: string, priced: bool, reachable: bool, hint: ?string}>, packs: list<array{id: int, name: string, rates: array<string, int>}>}
     */
    public function describe(BookingConfiguration $config, ?Product $product = null): array
    {
        $product ??= $config->getProduct();
        $grid = $config->getGranularity() ?? 'days';
        $datetime = $grid === 'datetime';
        $hourly = $datetime || in_array($grid, ['hours', 'minutes_30', 'minutes_15'], true);
        $unit = $hourly ? 'h' : 'jour(s)';
        $min = $config->getMinDuration();
        $max = $config->getMaxDuration();
        $lines = [];
        $warnings = [];

        $lines[] = sprintf('Le client réserve %s%s.', self::GRIDS[$grid] ?? $grid, $grid === 'days' ? ' : il choisit une date de début et une date de fin' : ($datetime ? ', sur un ou plusieurs jours (ex. : du 2 à 9 h au 9 à 10 h)' : ' : il choisit un créneau de début et un créneau de fin dans la journée'));
        if ($min !== null && $max !== null) {
            $lines[] = sprintf('Durée de %d à %d %s d\'affilée.', $min, $max, $unit);
        } elseif ($min !== null) {
            $lines[] = sprintf('Durée minimale : %d %s, sans maximum.', $min, $unit);
        } elseif ($max !== null) {
            $lines[] = sprintf('Durée maximale : %d %s.', $max, $unit);
        } else {
            $lines[] = 'Aucune limite de durée.';
        }
        if ($min !== null && $max !== null && $max < $min) {
            $warnings[] = sprintf('La durée maximale (%d) est plus petite que la durée minimale (%d) : aucune réservation ne sera acceptée.', $max, $min);
        }
        if ((!$hourly || $datetime) && $config->getMinDaysStandard()) {
            $lines[] = $datetime ? sprintf('%d jour(s) facturé(s) minimum (après le retard toléré).', $config->getMinDaysStandard()) : sprintf('%d jour(s) minimum.', $config->getMinDaysStandard());
        }
        $openingSameDay = false;
        if ($datetime) {
            $step = (int) ($config->effectiveTimeStepMinutes() ?? 60);
            $tolerance = (int) ($config->getReturnToleranceMinutes() ?? 0);
            $evening = $config->getEveningSlot();
            $lines[] = $config->getOpeningStart() !== null && $config->getOpeningEnd() !== null
                ? sprintf('Prise et retour entre %s et %s%s, n\'importe quel jour, par pas de %d minutes.', $config->getOpeningStart(), $config->getOpeningEnd(), is_array($evening) && isset($evening['start'], $evening['end']) ? sprintf(' (ou le soir de %s à %s)', $evening['start'], $evening['end']) : '', $step)
                : sprintf('Prise et retour à toute heure, par pas de %d minutes.', $step);
            $lines[] = sprintf('Prix : la formule choisie (journée, semaine, mois, ou heure) × le nombre d\'unités, arrondi au-dessus%s. Ex. : du 2 à 9 h au 9 à 10 h = 169 h → %s.', $tolerance > 0 ? sprintf(', après %d minutes de retard tolérées', $tolerance) : '', $tolerance >= 60 ? '7 jours' : '8 jours');
        } elseif ($hourly) {
            if ($config->getOpeningStart() !== null && $config->getOpeningEnd() !== null) {
                $openingSameDay = true;
                $evening = $config->getEveningSlot();
                $lines[] = sprintf('Créneaux proposés de %s à %s%s, le début et la fin le même jour.', $config->getOpeningStart(), $config->getOpeningEnd(), is_array($evening) && isset($evening['start'], $evening['end']) ? sprintf(', ou le soir de %s à %s', $evening['start'], $evening['end']) : '');
                $span = $this->spanHours((string) $config->getOpeningStart(), (string) $config->getOpeningEnd());
                if ($span !== null && $max !== null && $max > $span) {
                    $warnings[] = sprintf('La durée maximale (%d h) dépasse l\'amplitude d\'ouverture (%s h) : en pratique le client ne pourra pas dépasser %s h.', $max, $span, $span);
                }
                if ($span !== null && $min !== null && $min > $span) {
                    $warnings[] = sprintf('La durée minimale (%d h) dépasse l\'amplitude d\'ouverture (%s h) : aucune réservation ne sera acceptée.', $min, $span);
                }
            } else {
                $lines[] = 'Aucune heure d\'ouverture : un créneau peut commencer à toute heure et se terminer un autre jour.';
            }
            $halfDays = $config->getHalfDays() ?: [];
            if ($halfDays !== []) {
                $lines[] = 'Demi-journées proposées : ' . implode(', ', array_map(fn ($h) => sprintf('%s (%s à %s)', $h['label'] ?? '?', $h['start'] ?? '?', $h['end'] ?? '?'), $halfDays)) . '.';
            }
        }
        $allowed = $config->getAllowedDates() ?: null;
        $lines[] = $allowed === null ? 'Toutes les dates sont ouvertes à la réservation.' : sprintf('Seulement %d date(s) ouverte(s) : %s%s.', count($allowed), implode(', ', array_slice($allowed, 0, 4)), count($allowed) > 4 ? '…' : '');
        $stock = (int) ($config->getStockQuantity() ?? 0);
        $lines[] = sprintf('%d exemplaire(s) réservable(s) en même temps%s.', $stock, ($config->getBufferTime() ?? 0) > 0 ? sprintf(', %d minutes bloquées entre deux locations', $config->getBufferTime()) : '');
        if ($stock <= 0) {
            $warnings[] = 'Stock à zéro : le client verra toujours « indisponible ».';
        }

        // Formules et packs : ceux des catégories du produit
        $packs = [];
        $priced = array_fill_keys(array_keys(self::FORMULAS), false);
        foreach ($product?->getRentalPacks() ?? [] as $pack) {
            $rates = [];
            foreach (self::RATE_GETTERS as $type => $getter) {
                $cents = (int) round((float) ($pack->$getter() ?? 0));
                if ($cents > 0) {
                    $rates[$type] = $cents;
                    $priced[$type] = true;
                }
            }
            $packs[] = ['id' => (int) $pack->getId(), 'name' => (string) $pack->getName(), 'rates' => $rates];
        }
        $formulas = [];
        foreach (self::FORMULAS as $type => $label) {
            [$reachable, $hint] = $this->reachable($type, $hourly, $min, $max, $openingSameDay);
            if ($datetime && $type === 'halfDay' && $reachable) {
                $hint = null;
            }
            $formulas[$type] = ['label' => $label, 'priced' => $priced[$type], 'reachable' => $reachable, 'hint' => $hint];
        }
        if ($packs === []) {
            $warnings[] = $product !== null && count($product->getCategory()) === 0
                ? 'Le produit n\'a aucune catégorie : aucune grille tarifaire ne peut s\'y appliquer, le client ne pourra pas réserver.'
                : 'Aucune grille tarifaire (pack) n\'est rattachée aux catégories du produit : le client ne pourra pas réserver.';
        } else {
            foreach ($packs as $pack) {
                $parts = [];
                foreach ($pack['rates'] as $type => $cents) {
                    $parts[] = sprintf('%s %s', $this->money($cents), self::FORMULAS[$type]) . ($formulas[$type]['reachable'] ? '' : ' (hors d\'atteinte)');
                }
                $lines[] = sprintf('Grille « %s » : %s.', $pack['name'], $parts !== [] ? implode(', ', $parts) : 'aucun tarif');
            }
            $usable = array_filter($formulas, fn ($f) => $f['priced'] && $f['reachable']);
            if ($usable === []) {
                $warnings[] = 'Aucune formule n\'est à la fois tarifée et atteignable avec ces durées : le client ne pourra pas réserver.';
            } else {
                $lines[] = 'Formules que le client pourra choisir : ' . implode(', ', array_map(fn ($f) => $f['label'], $usable)) . '.';
            }
            foreach ($formulas as $type => $f) {
                if ($f['priced'] && !$f['reachable'] && $f['hint'] !== null) {
                    $warnings[] = sprintf('Le tarif « %s » ne servira jamais : %s', $f['label'], $f['hint']);
                }
            }
            if ($hourly && !$priced['hour'] && !$priced['halfDay']) {
                $warnings[] = 'Grille horaire sans tarif à l\'heure ni à la demi-journée dans les packs : le client ne pourra pas réserver.';
            }
            if (!$hourly && !$priced['day'] && !$priced['week'] && !$priced['month']) {
                $warnings[] = 'Grille à la journée sans tarif à la journée, à la semaine ni au mois : le client ne pourra pas réserver.';
            }
        }

        if (($config->getDeposit() ?? 0) > 0) {
            $lines[] = sprintf('Caution de %s, affichée à part et non comprise dans le total.', $this->money((int) $config->getDeposit()));
        }
        if (($config->getExtraPassengerFee() ?? 0) > 0) {
            $lines[] = sprintf('%s de plus par passager déclaré.', $this->money((int) $config->getExtraPassengerFee()));
        }
        if (($config->getArrivalLeadMinutes() ?? 0) > 0) {
            $lines[] = sprintf('Le client est invité à arriver %d minutes avant.', $config->getArrivalLeadMinutes());
        }
        if ($config->getIncluded()) {
            $lines[] = 'Inclus : ' . implode(', ', $config->getIncluded()) . '.';
        }
        if ($config->getExcluded()) {
            $lines[] = 'Non inclus : ' . implode(', ', $config->getExcluded()) . '.';
        }
        if (trim((string) $config->getCancellationPolicy()) !== '') {
            $lines[] = 'Politique d\'annulation affichée : « ' . mb_substr(trim((string) $config->getCancellationPolicy()), 0, 120) . (mb_strlen(trim((string) $config->getCancellationPolicy())) > 120 ? '…' : '') . ' »';
        }

        return ['lines' => $lines, 'warnings' => $warnings, 'formulas' => $formulas, 'packs' => $packs];
    }

    /** Résumé en HTML (panneau EasyAdmin) */
    public function html(BookingConfiguration $config, ?Product $product = null): string
    {
        $d = $this->describe($config, $product);
        $html = '<ul class="mb-2">' . implode('', array_map(fn ($l) => '<li>' . htmlspecialchars($l, ENT_QUOTES) . '</li>', $d['lines'])) . '</ul>';
        if ($d['warnings'] !== []) {
            $html .= '<div class="alert alert-warning py-2 mb-0"><strong>À corriger :</strong><ul class="mb-0">' . implode('', array_map(fn ($w) => '<li>' . htmlspecialchars($w, ENT_QUOTES) . '</li>', $d['warnings'])) . '</ul></div>';
        } else {
            $html .= '<div class="text-success">Aucune incohérence relevée.</div>';
        }

        return $html;
    }

    public function money(int $cents): string
    {
        $code = $this->currency?->code() ?? 'CAD';
        $symbol = ['CAD' => '$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CHF' => 'CHF'][$code] ?? $code;

        return number_format($cents / 100, 2, ',', ' ') . ' ' . $symbol;
    }

    /** @return array{0: bool, 1: ?string} atteignable avec les durées de la grille, et sinon pourquoi */
    private function reachable(string $type, bool $hourly, ?int $min, ?int $max, bool $openingSameDay): array
    {
        $hours = self::HOURS[$type];
        if ($hourly) {
            if (in_array($type, ['day', 'week', 'month'], true) && $openingSameDay) {
                return [false, 'avec des heures d\'ouverture, une location à l\'heure commence et finit le même jour ; pour louer à la journée, choisissez la grille « à la journée » (ou un second produit).'];
            }
            if ($max !== null && $max < $hours) {
                return [false, sprintf('la durée maximale est de %d h, cette formule dure %d h.', $max, $hours)];
            }

            return [true, null];
        }
        if (in_array($type, ['hour', 'halfDay'], true)) {
            return [false, 'sur une grille à la journée, le client réserve des jours entiers : les formules à l\'heure et à la demi-journée ne sont pas proposées.'];
        }
        $days = (int) ($hours / 24);
        if ($max !== null && $max < $days) {
            return [false, sprintf('la durée maximale est de %d jour(s), cette formule dure %d jours.', $max, $days)];
        }

        return [true, null];
    }

    private function spanHours(string $start, string $end): ?float
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $start, $a) || !preg_match('/^(\d{2}):(\d{2})$/', $end, $b)) {
            return null;
        }
        $span = ((int) $b[1] * 60 + (int) $b[2]) - ((int) $a[1] * 60 + (int) $a[2]);
        if ($end === '00:00') {
            $span = 24 * 60 - ((int) $a[1] * 60 + (int) $a[2]);
        }

        return $span > 0 ? round($span / 60, 2) : null;
    }
}
