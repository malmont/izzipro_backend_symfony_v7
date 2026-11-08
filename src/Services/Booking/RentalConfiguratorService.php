<?php

namespace App\Services\Booking;

use App\Entity\BookingConfiguration;
use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\RentalPack;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;

/**
 * Questionnaire « Configurer une location pas à pas » (08/11/2026) : des questions en langage courant, posées dans l'ordre, qui
 * remplissent les règles de réservation du produit (BookingConfiguration) et sa grille tarifaire (RentalPack rattaché à ses
 * catégories). Montants saisis en dollars, stockés en cents ; durées dans l'unité de la grille ; heures « HH:MM ».
 * `answers()` rend les réponses actuelles (préremplissage), `apply()` vérifie et enregistre, et rend les erreurs par question.
 */
class RentalConfiguratorService
{
    public const GRIDS = ['days', 'datetime', 'hours', 'minutes_30', 'minutes_15'];
    public const RATE_FIELDS = ['hour' => 'HourRate', 'halfDay' => 'HalfDayRate', 'day' => 'DayRate', 'week' => 'WeekRate', 'month' => 'MonthRate'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly RentalSummaryService $summary, private readonly ?ContentAuditRecorder $audit = null, private readonly ?TenantCacheService $cache = null)
    {
    }

    /** @return list<array{id: int, name: string, categories: list<string>, configured: bool, priced: bool}> produits louables */
    public function products(): array
    {
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(Product::class)->findBy(['rentalEnabled' => true], ['name' => 'ASC']) as $p) {
            $out[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'categories' => array_values(array_map(fn (Categories $c) => (string) $c->getName(), $p->getCategory()->toArray())), 'configured' => $p->getBookingConfiguration() !== null, 'priced' => $p->getRentalPacks() !== []];
        }

        return $out;
    }

    public function product(int $id): ?Product
    {
        $product = $id > 0 ? $this->emProvider->getEntityManager()->getRepository(Product::class)->find($id) : null;

        return $product instanceof Product && $product->isRentalEnabled() ? $product : null;
    }

    /** Réponses actuelles du questionnaire pour un produit (valeurs vides si rien n'est configuré). @return array<string, mixed> */
    public function answers(Product $product): array
    {
        $c = $product->getBookingConfiguration();
        $evening = is_array($c?->getEveningSlot()) ? $c->getEveningSlot() : [];
        $half = array_values(array_filter(is_array($c?->getHalfDays()) ? $c->getHalfDays() : [], 'is_array'));
        $packs = [];
        foreach ($product->getRentalPacks() as $pack) {
            $packs[] = ['id' => (int) $pack->getId(), 'name' => (string) $pack->getName()] + $this->packRates($pack);
        }
        $first = $packs[0] ?? null;

        return [
            'granularity' => $c?->getGranularity() ?? 'days',
            'stock' => $c?->getStockQuantity() ?? 1,
            'minDuration' => $c?->getMinDuration() ?? 1,
            'maxDuration' => $c?->getMaxDuration(),
            'minDaysStandard' => $c?->getMinDaysStandard(),
            'bufferTime' => $c?->getBufferTime() ?? 0,
            'openingStart' => $c?->getOpeningStart() ?? '',
            'openingEnd' => $c?->getOpeningEnd() ?? '',
            'eveningStart' => (string) ($evening['start'] ?? ''), 'eveningEnd' => (string) ($evening['end'] ?? ''),
            'halfDay1Label' => (string) ($half[0]['label'] ?? ''), 'halfDay1Start' => (string) ($half[0]['start'] ?? ''), 'halfDay1End' => (string) ($half[0]['end'] ?? ''),
            'halfDay2Label' => (string) ($half[1]['label'] ?? ''), 'halfDay2Start' => (string) ($half[1]['start'] ?? ''), 'halfDay2End' => (string) ($half[1]['end'] ?? ''),
            'returnTolerance' => $c?->getReturnToleranceMinutes() ?? 0, 'timeStep' => $c?->getTimeStepMinutes() ?? 60,
            'datesMode' => $c?->getAllowedDates() ? 'list' : 'all',
            'allowedDates' => implode("\n", $c?->getAllowedDates() ?: []),
            'pack' => $first !== null ? (string) $first['id'] : 'new',
            'packName' => $first['name'] ?? ('Tarifs ' . ($product->getCategory()->first() ? $product->getCategory()->first()->getName() : $product->getName())),
            'rateHour' => $first['rateHour'] ?? '', 'rateHalfDay' => $first['rateHalfDay'] ?? '', 'rateDay' => $first['rateDay'] ?? '', 'rateWeek' => $first['rateWeek'] ?? '', 'rateMonth' => $first['rateMonth'] ?? '',
            'deposit' => $this->dollars($c?->getDeposit()), 'passengerFee' => $this->dollars($c?->getExtraPassengerFee()),
            'arrivalLead' => $c?->getArrivalLeadMinutes() ?? '',
            'included' => implode("\n", $c?->getIncluded() ?: []), 'excluded' => implode("\n", $c?->getExcluded() ?: []),
            'cancellationPolicy' => (string) ($c?->getCancellationPolicy() ?? ''), 'notes' => (string) ($c?->getNotes() ?? ''),
            'packs' => $packs,
            'categories' => array_values(array_map(fn (Categories $cat) => (string) $cat->getName(), $product->getCategory()->toArray())),
            'configured' => $c !== null,
        ];
    }

    /**
     * Vérifie puis enregistre les réponses. @param array<string, mixed> $post
     * @return array{errors: array<string, string>, summary: ?array}
     */
    public function apply(Product $product, array $post, ?string $by = null): array
    {
        $errors = [];
        $str = fn (string $k) => trim((string) ($post[$k] ?? ''));
        $int = function (string $k, bool $required, int $min = 0) use ($post, &$errors): ?int {
            $v = trim((string) ($post[$k] ?? ''));
            if ($v === '') {
                if ($required) {
                    $errors[$k] = 'Ce nombre est obligatoire.';
                }

                return null;
            }
            if (!preg_match('/^\d+$/', $v) || (int) $v < $min) {
                $errors[$k] = $min > 0 ? sprintf('Un nombre entier d\'au moins %d est attendu.', $min) : 'Un nombre entier est attendu.';

                return null;
            }

            return (int) $v;
        };
        $time = function (string $k, bool $required) use ($post, &$errors): ?string {
            $v = trim((string) ($post[$k] ?? ''));
            if ($v === '') {
                if ($required) {
                    $errors[$k] = 'Heure attendue (HH:MM).';
                }

                return null;
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                $errors[$k] = 'Heure au format HH:MM attendue, par exemple 09:00.';

                return null;
            }

            return $v;
        };
        $money = function (string $k) use ($post, &$errors): ?int {
            $v = str_replace([' ', ','], ['', '.'], trim((string) ($post[$k] ?? '')));
            if ($v === '') {
                return null;
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1_000_000) {
                $errors[$k] = 'Montant en dollars attendu, par exemple 95 ou 95,50.';

                return null;
            }

            return (int) round((float) $v * 100);
        };

        $grid = $str('granularity');
        if (!in_array($grid, self::GRIDS, true)) {
            $errors['granularity'] = 'Choisissez comment le client réserve.';
        }
        $datetime = $grid === 'datetime';
        $hourly = $datetime || in_array($grid, ['hours', 'minutes_30', 'minutes_15'], true);
        $returnTolerance = $datetime ? ($int('returnTolerance', false, 0) ?? 0) : null;
        if ($returnTolerance !== null && $returnTolerance > 1439) {
            $errors['returnTolerance'] = 'Au plus 1 439 minutes (moins d\'un jour).';
        }
        $timeStep = $datetime ? ($int('timeStep', false, 5) ?? 60) : null;
        if ($timeStep !== null && !in_array($timeStep, [5, 10, 15, 20, 30, 60, 120], true)) {
            $errors['timeStep'] = 'Pas possible : 5, 10, 15, 20, 30, 60 ou 120 minutes.';
        }
        $stock = $int('stock', true, 1);
        $minDuration = $int('minDuration', true, 1);
        $maxDuration = $int('maxDuration', false, 1);
        if ($minDuration !== null && $maxDuration !== null && $maxDuration < $minDuration) {
            $errors['maxDuration'] = 'La durée maximale doit être au moins égale à la durée minimale (ou laissée vide).';
        }
        $minDays = $hourly && !$datetime ? null : $int('minDaysStandard', false, 1); // jours facturés : grille à la journée et grille datetime
        $buffer = $int('bufferTime', false, 0) ?? 0;
        $openingStart = $hourly ? $time('openingStart', false) : null;
        $openingEnd = $hourly ? $time('openingEnd', false) : null;
        if ($hourly && (($openingStart === null) !== ($openingEnd === null)) && !isset($errors['openingStart'], $errors['openingEnd'])) {
            $errors['openingStart'] = 'Donnez l\'ouverture et la fermeture, ou aucune des deux.';
        }
        $evening = null;
        if ($hourly) {
            $es = $time('eveningStart', false);
            $ee = $time('eveningEnd', false);
            if ($es !== null && $ee !== null) {
                $evening = ['start' => $es, 'end' => $ee];
            } elseif ($es !== null || $ee !== null) {
                $errors['eveningStart'] = 'Donnez le début et la fin du créneau du soir, ou aucun des deux.';
            }
        }
        $halfDays = [];
        if ($hourly && !$datetime) {
            foreach ([1, 2] as $i) {
                $label = $str("halfDay{$i}Label");
                $hs = $time("halfDay{$i}Start", false);
                $he = $time("halfDay{$i}End", false);
                if ($label !== '' || $hs !== null || $he !== null) {
                    if ($label === '' || $hs === null || $he === null) {
                        $errors["halfDay{$i}Label"] = 'Une demi-journée a un nom, un début et une fin.';
                    } else {
                        $halfDays[] = ['label' => mb_substr($label, 0, 40), 'start' => $hs, 'end' => $he];
                    }
                }
            }
        }
        $allowedDates = null;
        if ($str('datesMode') === 'list') {
            $allowedDates = [];
            foreach (preg_split('/[\s,;]+/', $str('allowedDates')) ?: [] as $d) {
                if ($d === '') {
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                    $errors['allowedDates'] = sprintf('Date « %s » illisible : une date par ligne, au format AAAA-MM-JJ.', mb_substr($d, 0, 20));
                    break;
                }
                $allowedDates[] = $d;
            }
            $allowedDates = array_values(array_unique($allowedDates));
            if ($allowedDates === [] && !isset($errors['allowedDates'])) {
                $errors['allowedDates'] = 'Donnez au moins une date, ou choisissez « toutes les dates ».';
            }
        }
        $deposit = $money('deposit');
        $passengerFee = $money('passengerFee');
        $arrivalLead = $int('arrivalLead', false, 0);
        $rates = [];
        foreach (array_keys(self::RATE_FIELDS) as $type) {
            $rates[$type] = $money('rate' . ucfirst($type));
        }
        $packChoice = $str('pack');
        $packName = mb_substr($str('packName'), 0, 120);
        $em = $this->emProvider->getEntityManager();
        $pack = null;
        if ($packChoice === 'new') {
            if ($packName === '') {
                $errors['packName'] = 'Donnez un nom à la grille tarifaire.';
            }
            if ($product->getCategory()->isEmpty()) {
                $errors['pack'] = 'Le produit n\'a aucune catégorie : ajoutez-en une sur sa fiche, la grille tarifaire s\'y rattache.';
            }
        } elseif ($packChoice !== 'keep') {
            $pack = is_numeric($packChoice) ? $em->getRepository(RentalPack::class)->find((int) $packChoice) : null;
            if (!$pack instanceof RentalPack || !in_array($pack, $product->getRentalPacks(), true)) {
                $errors['pack'] = 'Cette grille tarifaire n\'est pas rattachée aux catégories du produit.';
            }
        }
        if ($packChoice !== 'keep' && array_filter($rates, fn ($v) => $v !== null && $v > 0) === []) {
            $errors['rates'] = 'Donnez au moins un tarif (par exemple le prix à l\'heure ou à la journée).';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'summary' => null];
        }

        $config = $product->getBookingConfiguration() ?? (new BookingConfiguration())->setProduct($product);
        $config->setGranularity($grid)->setStockQuantity($stock)->setMinDuration($minDuration)->setMaxDuration($maxDuration)->setMinDaysStandard($minDays)->setBufferTime($buffer)
            ->setOpeningStart($openingStart)->setOpeningEnd($openingEnd)->setEveningSlot($evening)->setHalfDays($halfDays !== [] ? $halfDays : null)->setAllowedDates($allowedDates)
            ->setReturnToleranceMinutes($returnTolerance)->setTimeStepMinutes($timeStep)
            ->setDeposit($deposit)->setExtraPassengerFee($passengerFee)->setArrivalLeadMinutes($arrivalLead)
            ->setIncluded($this->lines($str('included')))->setExcluded($this->lines($str('excluded')))
            ->setCancellationPolicy($str('cancellationPolicy') !== '' ? mb_substr($str('cancellationPolicy'), 0, 5000) : null)->setNotes($str('notes') !== '' ? mb_substr($str('notes'), 0, 10000) : null);
        $product->setBookingConfiguration($config);
        $em->persist($config);
        if ($packChoice === 'new') {
            $pack = (new RentalPack())->setName($packName);
            $pack->addCategory($product->getCategory()->first());
            $em->persist($pack);
        }
        if ($pack !== null) {
            foreach (self::RATE_FIELDS as $type => $suffix) {
                $pack->{'set' . $suffix}($rates[$type] !== null && $rates[$type] > 0 ? (float) $rates[$type] : null);
            }
        }
        $em->flush();
        $this->cache?->invalidateTenant();
        $this->audit?->record('product', (int) $product->getId(), 'rental_setup', [], ['granularity' => $grid, 'stock' => $stock, 'minDuration' => $minDuration, 'maxDuration' => $maxDuration, 'pack' => $pack?->getId(), 'by' => $by], ['product']);

        return ['errors' => [], 'summary' => $this->summary->describe($config, $product)];
    }

    /** @return array<string, string> tarifs du pack en dollars (chaîne vide si absent) */
    private function packRates(RentalPack $pack): array
    {
        $out = [];
        foreach (self::RATE_FIELDS as $type => $suffix) {
            $out['rate' . ucfirst($type)] = $this->dollars($pack->{'get' . $suffix}());
        }

        return $out;
    }

    private function dollars(int|float|null $cents): string
    {
        return $cents === null || (float) $cents <= 0 ? '' : number_format(((float) $cents) / 100, 2, '.', '');
    }

    /** @return list<string>|null */
    private function lines(string $text): ?array
    {
        $out = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), fn ($l) => $l !== ''));

        return $out === [] ? null : array_map(fn ($l) => mb_substr($l, 0, 200), $out);
    }
}
