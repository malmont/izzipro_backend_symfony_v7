<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Gestion\Message\GenerateGestionAdviceMessage;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guide de la gestion (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md`) : générations du jour (règles calculées tout de suite, rédaction par l'IA
 * en asynchrone sur le worker `gestion_ai`), conseils de la période (une ligne par signature, statut porté par le site), textes de repli des
 * règles, vérification des chiffres écrits par l'IA, vignette « advice ».
 *
 * Statuts d'une génération : `rules` (règles calculées, IA pas encore demandée), `pending`, `ok`, `not_included`, `credits_exhausted`, `error` ;
 * l'API rend `rules` sous le nom `rules_only`.
 */
class GestionAdviceService
{
    public const MANUAL_PER_DAY = 3;
    public const PENDING_TIMEOUT_MINUTES = 15;
    public const LOCK_KEY = 7414301;
    /** Conseils qui ont déjà leur vignette d'alerte (cash-low, trip-budget) : pas de vignette « advice » en double */
    public const NO_TILE_KINDS = ['CASH_TIGHT', 'TRIP_BUDGET'];
    public const TILE_PRIORITY = ['high' => 68, 'medium' => 64, 'low' => 60];
    /** Nombres tolérés dans un texte sans figurer dans les sources (fenêtres et repères écrits par les règles) */
    public const FREE_NUMBERS = [1, 7, 14, 21, 30, 45, 60, 90];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly PeriodCalendar $calendar,
        private readonly AdviceRules $rules,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        private readonly ?EmailLocale $siteLocale = null,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?AdviceMeasure $measures = null,
    ) {
    }

    // ----- générations -----

    /**
     * Génération du jour pour la période : créée au premier appel du jour (règles calculées tout de suite) ; une période fermée n'a qu'une
     * génération, ensuite figée. `$askAi` : demande la rédaction par l'IA si la génération automatique du jour ne l'a pas encore eue
     * (jamais depuis les vignettes).
     *
     * @return array<string, mixed> la génération
     */
    public function ensureToday(Collections $period, bool $askAi, ?string $user = null, ?\DateTimeImmutable $today = null): array
    {
        $this->assertPeriod($period);
        $today ??= $this->calendar->today();
        $conn = $this->conn();
        $pid = (int) $period->getId();
        $this->expirePending();
        $closed = $this->calendar->isClosed($period);
        $run = $closed
            ? $conn->fetchAssociative('SELECT * FROM gestion_advice_run WHERE period_id = ? ORDER BY id DESC LIMIT 1', [$pid])
            : $conn->fetchAssociative('SELECT * FROM gestion_advice_run WHERE period_id = ? AND store_day = ? ORDER BY id DESC LIMIT 1', [$pid, $today->format('Y-m-d')]);
        if ($run === false) {
            $conn->beginTransaction();
            try {
                $conn->executeQuery('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_KEY, $pid]);
                $run = $closed
                    ? $conn->fetchAssociative('SELECT * FROM gestion_advice_run WHERE period_id = ? ORDER BY id DESC LIMIT 1', [$pid])
                    : $conn->fetchAssociative('SELECT * FROM gestion_advice_run WHERE period_id = ? AND store_day = ? ORDER BY id DESC LIMIT 1', [$pid, $today->format('Y-m-d')]);
                if ($run === false) {
                    $run = $this->newRun($period, $today, 'auto', $user);
                }
                $conn->commit();
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }
        if ($askAi && $run['status'] === 'rules' && $run['run_trigger'] === 'auto') {
            $conn->executeStatement("UPDATE gestion_advice_run SET status = 'pending' WHERE id = ? AND status = 'rules'", [$run['id']]);
            $run['status'] = 'pending';
            $this->dispatch((int) $run['id']);
        }

        return $run;
    }

    /**
     * Relance manuelle : règles recalculées et rédaction demandée ; 3 par site et par jour (429 ADVICE_REFRESH_LIMIT), une seule à la fois
     * (409 ADVICE_GENERATING), pas sur une période fermée (409 PERIOD_CLOSED).
     *
     * @return array{runId: int, manualRefreshesLeft: int}
     */
    public function refresh(Collections $period, ?string $user, ?\DateTimeImmutable $today = null): array
    {
        $this->assertPeriod($period);
        if ($this->calendar->isClosed($period)) {
            throw new GestionConflictException('PERIOD_CLOSED', 'Cette période est fermée : son bilan ne change plus.', 409, ['periodId' => $period->getId()]);
        }
        $today ??= $this->calendar->today();
        $this->expirePending();
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $conn->executeQuery('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_KEY, 0]);
            if ($conn->fetchOne("SELECT 1 FROM gestion_advice_run WHERE status = 'pending' LIMIT 1") !== false) {
                throw new GestionConflictException('ADVICE_GENERATING', 'Une rédaction des conseils est déjà en cours : attendez sa fin.');
            }
            $used = $this->manualToday($today);
            if ($used >= self::MANUAL_PER_DAY) {
                throw new GestionConflictException('ADVICE_REFRESH_LIMIT', sprintf('%d relances par jour au plus : la prochaine sera possible demain.', self::MANUAL_PER_DAY), 429, ['manualRefreshesLeft' => 0]);
            }
            $run = $this->newRun($period, $today, 'manual', $user);
            $conn->executeStatement("UPDATE gestion_advice_run SET status = 'pending' WHERE id = ?", [$run['id']]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $this->dispatch((int) $run['id']);

        return ['runId' => (int) $run['id'], 'manualRefreshesLeft' => self::MANUAL_PER_DAY - $used - 1];
    }

    // ----- lecture -----

    /** @return array<string, mixed> réponse de GET /api/gestion/conseils (demande la rédaction du jour par l'IA si elle n'a pas eu lieu) */
    public function list(Collections $period, string $status, string $locale, ?\DateTimeImmutable $today = null): array
    {
        return $this->render($period, $status, $locale, true, $today);
    }

    /** Même réponse sans jamais demander l'IA (commande de contrôle) */
    public function listWithoutAi(Collections $period, string $status, string $locale): array
    {
        return $this->render($period, $status, $locale, false, null);
    }

    private function render(Collections $period, string $status, string $locale, bool $askAi, ?\DateTimeImmutable $today): array
    {
        $today ??= $this->calendar->today();
        $run = $this->ensureToday($period, $askAi, null, $today);
        $pid = (int) $period->getId();
        $rows = $status === 'all'
            ? $this->conn()->fetchAllAssociative('SELECT * FROM gestion_advice WHERE period_id = ? ORDER BY active DESC, rank, id', [$pid])
            : $this->conn()->fetchAllAssociative("SELECT * FROM gestion_advice WHERE period_id = ? AND active AND status = 'new' ORDER BY rank, id", [$pid]);
        $headline = $this->conn()->fetchAssociative("SELECT headline, headline_locale FROM gestion_advice_run WHERE period_id = ? AND headline IS NOT NULL ORDER BY id DESC LIMIT 1", [$pid]);

        return [
            'period' => ['id' => $pid, 'name' => $period->getNomCollection(), 'start' => $period->getStartDateCollection()->format('Y-m-d'), 'end' => $period->getEndDateCollection()->format('Y-m-d')],
            'moments' => $this->rules->moments($period, $today)['moments'],
            'currency' => $this->siteCurrency(),
            'headline' => $headline !== false && $headline['headline_locale'] === $locale ? self::cleanText((string) $headline['headline']) : null,
            'ai' => ['status' => $run['status'] === 'rules' ? 'rules_only' : $run['status'], 'generatedAt' => $run['finished_at'] === null ? null : (new \DateTimeImmutable((string) $run['finished_at']))->format(\DateTimeInterface::ATOM)],
            'generating' => $run['status'] === 'pending',
            'manualRefreshesLeft' => max(0, self::MANUAL_PER_DAY - $this->manualToday($today)),
            'advices' => array_map(fn (array $r) => $this->toArray($r, $locale), $rows),
        ];
    }

    /** Premier conseil new de la période en cours pour la vignette « advice » (règles calculées au besoin, sans IA), ou null */
    public function tileAdvice(string $locale): ?array
    {
        $period = $this->calendar->periodFor($this->calendar->today()); // crée la période en cours au besoin, comme le budget par poste
        if ($period === null) {
            return null;
        }
        $this->ensureToday($period, false);
        $row = $this->conn()->fetchAssociative(
            "SELECT * FROM gestion_advice WHERE period_id = ? AND active AND status = 'new' AND kind NOT IN (?) ORDER BY rank, id LIMIT 1",
            [$period->getId(), self::NO_TILE_KINDS], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        return $row === false ? null : $this->toArray($row, $locale);
    }

    /**
     * Fait / écarté : statut porté par le site, auteur et date gardés (le sujet reste en base pour la mesure). « Fait » peut dire ce qui a été
     * appliqué (lot G1b-1) : `applied { appliedOn?, pricePercent?, promotionPercent?, productIds?, campaignId? }`, facultatif.
     */
    public function setStatus(int $id, string $status, ?string $user, mixed $note, string $locale, mixed $applied = null): array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_advice WHERE id = ?', [$id]);
        if ($row === false) {
            throw new GestionConflictException('ADVICE_NOT_FOUND', 'Conseil introuvable.', 404, ['adviceId' => $id]);
        }
        if ($row['status'] !== 'new') {
            throw new GestionConflictException('ADVICE_CLOSED', 'Ce conseil est déjà traité.', 409, ['adviceId' => $id, 'status' => $row['status']]);
        }
        if ($note !== null && !is_string($note)) {
            throw new GestionValidationException(['note' => 'MUST_BE_STRING']);
        }
        [$appliedJson, $appliedOn] = $status === 'done' ? $this->applied($applied) : [null, null];
        $column = $status === 'done' ? 'done_at' : 'dismissed_at';
        $this->conn()->executeStatement("UPDATE gestion_advice SET status = ?, status_by = ?, status_note = ?, applied = ?, applied_on = ?, $column = NOW(), updated_at = NOW() WHERE id = ?",
            [$status, $user, $note === null ? null : mb_substr(trim($note), 0, 1000), $appliedJson, $appliedOn, $id]);

        return $this->toArray($this->conn()->fetchAssociative('SELECT * FROM gestion_advice WHERE id = ?', [$id]), $locale);
    }

    /**
     * Conseil marqué fait par une application de la gestion (promotion datée, lot P5) : `appliedOn` peut être à venir (début de la promotion),
     * ce que la route publique « fait » refuse ; rien si le conseil n'est plus new.
     *
     * @param array<string, mixed> $applied
     */
    public function markDoneByApplication(int $id, ?string $user, array $applied, string $appliedOn, string $locale): ?array
    {
        $done = $this->conn()->executeStatement(
            "UPDATE gestion_advice SET status = 'done', status_by = ?, applied = ?, applied_on = ?, done_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'new'",
            [$user, json_encode($applied + ['appliedOn' => $appliedOn]), $appliedOn, $id]
        );

        return $done === 0 ? null : $this->toArray($this->conn()->fetchAssociative('SELECT * FROM gestion_advice WHERE id = ?', [$id]), $locale);
    }

    /** Forme rendue par l'API */
    public function toArray(array $r, string $locale): array
    {
        $sources = json_decode((string) $r['sources'], true) ?: [];
        $params = json_decode((string) $r['params'], true) ?: [];
        $aiTitle = self::cleanText($r['ai_title'] ?? null);
        $aiText = self::cleanText($r['ai_text'] ?? null);
        $useAi = $aiTitle !== null && $aiText !== null && $r['ai_locale'] === $locale; // texte stocké avant le nettoyage : nettoyé ici, sinon repli
        [$title, $text] = $useAi ? [$aiTitle, $aiText] : $this->fallback((string) $r['kind'], $sources, $params, (int) $r['effect_cents'], $locale);
        $action = json_decode((string) $r['action'], true) ?: [];
        if (($action['params'] ?? []) === []) {
            $action['params'] = new \stdClass();
        }

        return [
            'id' => (int) $r['id'], 'periodId' => (int) $r['period_id'], 'moment' => $r['moment'], 'kind' => $r['kind'], 'title' => $title, 'text' => $text, 'textSource' => $useAi ? 'ai' : 'rules',
            'action' => $action,
            'effect' => ['amount' => (int) $r['effect_cents'], 'label' => $this->translator->trans('advice.effects.' . $r['effect_label'], [], 'gestion', $locale)],
            'confidence' => $r['confidence'],
            'sources' => array_map(fn ($s) => ['label' => $this->translator->trans('advice.sources.' . $s['key'], [], 'gestion', $locale), 'value' => $s['value'], 'unit' => $s['unit']], $sources),
            'figures' => json_decode((string) $r['figures'], true) ?: [],
            'subject' => (object) (json_decode((string) $r['subject'], true) ?: []),
            'status' => $r['status'], 'active' => (bool) $r['active'], 'rank' => (int) $r['rank'],
            'createdAt' => (new \DateTimeImmutable((string) $r['created_at']))->format(\DateTimeInterface::ATOM),
            'updatedAt' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DateTimeInterface::ATOM),
            'doneAt' => $r['done_at'] === null ? null : (new \DateTimeImmutable((string) $r['done_at']))->format(\DateTimeInterface::ATOM),
            'dismissedAt' => $r['dismissed_at'] === null ? null : (new \DateTimeImmutable((string) $r['dismissed_at']))->format(\DateTimeInterface::ATOM),
            'applied' => ($r['applied'] ?? null) === null ? null : json_decode((string) $r['applied'], true),
            'appliedOn' => ($r['applied_on'] ?? null) === null ? null : substr((string) $r['applied_on'], 0, 10),
            'measurement' => $r['status'] === 'done' ? $this->measures?->summary($r, $locale) : null,
        ];
    }

    /** Mesure après application (lot G1b-1) : 404 ADVICE_NOT_FOUND, 409 ADVICE_NOT_DONE */
    public function measure(int $id, string $locale): array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_advice WHERE id = ?', [$id]);
        if ($row === false) {
            throw new GestionConflictException('ADVICE_NOT_FOUND', 'Conseil introuvable.', 404, ['adviceId' => $id]);
        }
        if ($row['status'] !== 'done' || $this->measures === null) {
            throw new GestionConflictException('ADVICE_NOT_DONE', 'Ce conseil n\'est pas marqué fait : rien à mesurer.', 409, ['adviceId' => $id, 'status' => $row['status']]);
        }

        return $this->measures->measure($row, $locale);
    }

    /**
     * Vignette « advice-result » (lot G1b-1) : le dernier conseil fait dont la mesure est complète depuis moins de 30 jours.
     *
     * @return array{adviceId: int, kind: string, text: string, change: array<string, int>, appliedOn: string}|null
     */
    public function resultTile(string $locale, ?\DateTimeImmutable $today = null): ?array
    {
        if ($this->measures === null) {
            return null;
        }
        $today ??= $this->calendar->today();
        $since = $today->modify('-' . (AdviceMeasure::WINDOW_DAYS + AdviceMeasure::RESULT_TILE_DAYS) . ' days')->format('Y-m-d');
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT * FROM gestion_advice WHERE status = 'done' AND kind IN ('FAST_SELLER', 'SLOW_STOCK', 'END_CLEARANCE', 'AD_RELAUNCH', 'RENTAL_PEAK_DAYS', 'RENTAL_LOW_WEEKDAYS', 'RENTAL_IDLE') AND COALESCE(applied_on, CAST(done_at AS date)) >= ?"
            . ' ORDER BY COALESCE(applied_on, CAST(done_at AS date)) DESC, id DESC LIMIT 5', [$since]
        );
        foreach ($rows as $r) {
            $m = $this->measures->measure($r, $locale, $today);
            if ($m['measurable'] && $m['window']['complete']) {
                return ['adviceId' => (int) $r['id'], 'kind' => (string) $r['kind'], 'text' => $m['text'], 'change' => $m['change'], 'appliedOn' => $m['appliedOn']];
            }
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} applied (JSON) et applied_on validés */
    private function applied(mixed $applied): array
    {
        if ($applied === null) {
            return [null, null];
        }
        if (!is_array($applied) || ($applied !== [] && array_is_list($applied))) {
            throw new GestionValidationException(['applied' => 'MUST_BE_OBJECT']);
        }
        $errors = [];
        $out = [];
        $today = $this->calendar->today()->format('Y-m-d');
        $on = null;
        if (isset($applied['appliedOn'])) {
            $on = is_string($applied['appliedOn']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $applied['appliedOn']) ? $applied['appliedOn'] : null;
            if ($on === null) {
                $errors['applied.appliedOn'] = 'INVALID_DATE';
            } elseif ($on > $today) {
                $errors['applied.appliedOn'] = 'DATE_IN_FUTURE';
            }
        }
        foreach (['pricePercent' => [-90, 300], 'promotionPercent' => [0, 90]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $applied) || $applied[$field] === null) {
                continue;
            }
            $v = $applied[$field];
            if (!is_int($v) && !(is_float($v) && is_finite($v))) {
                $errors["applied.$field"] = 'MUST_BE_NUMBER';
            } elseif ($v < $min || $v > $max) {
                $errors["applied.$field"] = 'OUT_OF_RANGE';
            } else {
                $out[$field] = $v;
            }
        }
        if (isset($applied['productIds'])) {
            $ids = $applied['productIds'];
            if (!is_array($ids) || !array_is_list($ids)) {
                $errors['applied.productIds'] = 'MUST_BE_LIST';
            } else {
                $known = $ids === [] ? [] : array_map('intval', $this->conn()->fetchFirstColumn('SELECT id FROM product WHERE id IN (?)', [array_values(array_filter($ids, 'is_int'))], [\Doctrine\DBAL\ArrayParameterType::INTEGER]));
                foreach ($ids as $i => $pid) {
                    if (!is_int($pid) || !in_array($pid, $known, true)) {
                        $errors["applied.productIds[$i]"] = 'PRODUCT_NOT_FOUND';
                    }
                }
                $out['productIds'] = array_values(array_unique(array_filter($ids, 'is_int')));
            }
        }
        if (isset($applied['campaignId'])) {
            $cid = $applied['campaignId'];
            if (!is_int($cid) || $this->conn()->fetchOne('SELECT 1 FROM marketing_campaign WHERE id = ?', [$cid]) === false) {
                $errors['applied.campaignId'] = 'CAMPAIGN_NOT_FOUND';
            } else {
                $out['campaignId'] = $cid;
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Application invalide');
        }
        if ($on !== null) {
            $out['appliedOn'] = $on;
        }

        return [json_encode($out ?: new \stdClass()), $on ?? $today];
    }

    // ----- textes de repli, formats, vérification -----

    /** @return array{0: string, 1: string} titre et texte de repli de la règle, chiffres formatés dans la langue */
    public function fallback(string $kind, array $sources, array $params, int $effect, string $locale): array
    {
        $p = ['%effect%' => $this->format(abs($effect), 'money', $locale)];
        foreach ($sources as $s) {
            $p['%' . $s['key'] . '%'] = $this->format($s['value'], $s['unit'], $locale);
        }
        foreach ($params as $k => $v) {
            $p["%$k%"] = match ($k) {
                'category' => $this->translator->trans("budget.categories.$v", [], 'gestion', $locale),
                'date' => $v === null ? '' : HomeTilesService::day((string) $v, $locale),
                'weekdays' => implode(', ', array_map(fn ($w) => $this->translator->trans("advice.weekdays.$w", [], 'gestion', $locale), (array) $v)), // lot LOC2
                default => is_array($v) ? implode(', ', $v) : (string) $v,
            };
        }
        $variant = ($params['date'] ?? 'x') === null ? '_later' : '';

        return [$this->translator->trans("advice.kinds.$kind.title", $p, 'gestion', $locale), $this->translator->trans("advice.kinds.$kind.text$variant", $p, 'gestion', $locale)];
    }

    public function format(int|float|string $value, string $unit, string $locale): string
    {
        $fmt = fn (float $n, int $dec) => (new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::DECIMAL))->format(round($n, $dec)) ?: (string) $n;

        return match ($unit) {
            'money' => HomeTilesService::money((int) $value, $locale, $this->siteCurrency()),
            'percent' => $fmt((float) $value, 1) . ($locale === 'en' ? '%' : "\u{00A0}%"),
            'ratio' => $fmt((float) $value, 2),
            'date' => HomeTilesService::day((string) $value, $locale),
            default => (string) (int) $value,
        };
    }

    /**
     * Nombres qu'un texte peut écrire : chaque valeur des sources et de l'effet sous ses écritures admises (montant au cent, à l'unité arrondie
     * ou tronquée ; pourcentage ou ratio arrondi ; date par jour, mois et année ; quantité), plus quelques repères fixes. Jamais une valeur
     * recalculée (somme, écart) qui ne serait pas dans les sources.
     *
     * @return list<float>
     */
    public static function allowedNumbers(array $sources, int $effect, int $figures = 0): array
    {
        $out = self::FREE_NUMBERS;
        $add = function (int|float|string $value, string $unit) use (&$out): void {
            if ($unit === 'date') {
                [$y, $m, $d] = array_map('intval', explode('-', substr((string) $value, 0, 10)) + [0, 0, 0]);
                array_push($out, $y, $m, $d);

                return;
            }
            $v = abs((float) $value);
            if ($unit === 'money') {
                array_push($out, $v / 100, round($v / 100), floor($v / 100));
            } else {
                array_push($out, $v, round($v, 1), round($v));
            }
        };
        foreach ($sources as $s) {
            $add($s['value'], $s['unit']);
        }
        $add($effect, 'money');
        if ($figures > 0) {
            $out[] = $figures;
        }

        return array_values(array_unique(array_map('floatval', $out)));
    }

    /** @return list<float> nombres écrits dans un texte (séparateurs de milliers et virgule décimale compris) */
    public static function numbersIn(string $text): array
    {
        preg_match_all('/\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?/u', $text, $m);

        return array_map(fn ($s) => (float) str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $s), $m[0]);
    }

    /**
     * Texte rédigé par l'IA nettoyé des restes de sa sortie JSON (11/11/2026 : « …à vous de décider.} » vu sur demo) : blancs, accolades,
     * crochets et guillemets droits en trop retirés au début et à la fin ; null (texte rejeté, repli des règles) s'il reste un caractère de
     * structure : accolade, crochet, `\"` ou `\n` littéral. Appliqué à l'écriture (conseils, synthèse, avis du simulateur et de la
     * préparation) et à la lecture (textes déjà stockés).
     */
    public static function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $clean = preg_replace('/^[\s{}\[\]"]+|[\s{}\[\]"]+$/u', '', $text) ?? '';
        if ($clean === '' || preg_match('/[{}\[\]]|\\\\["nrt]/u', $clean)) {
            return null;
        }

        return $clean;
    }

    /** @return list<float> nombres du texte qui ne sont pas admis (vide = texte accepté) */
    public static function unknownNumbers(string $text, array $allowed): array
    {
        return array_values(array_filter(self::numbersIn($text), function (float $n) use ($allowed): bool {
            foreach ($allowed as $a) {
                if (abs($a - $n) < 0.005) {
                    return false;
                }
            }

            return true;
        }));
    }

    // ----- interne -----

    /** Règles calculées, conseils mis à jour par signature (statut gardé ; ceux qui ne sont plus détectés sortent de la liste) */
    private function newRun(Collections $period, \DateTimeImmutable $today, string $trigger, ?string $user): array
    {
        $conn = $this->conn();
        $pid = (int) $period->getId();
        $detected = $this->rules->detect($period, $today);
        $seen = [];
        foreach ($detected['advices'] as $rank => $a) {
            $seen[] = $a['signature'];
            $conn->executeStatement(
                'INSERT INTO gestion_advice (period_id, signature, moment, kind, subject, action, effect_cents, effect_label, confidence, sources, figures, params, rank, active, detected_on, created_at, updated_at)'
                . ' VALUES (:p, :sig, :moment, :kind, :subject, :action, :effect, :label, :conf, :sources, :figures, :params, :rank, TRUE, :day, NOW(), NOW())'
                . ' ON CONFLICT (period_id, signature) DO UPDATE SET moment = EXCLUDED.moment, kind = EXCLUDED.kind, subject = EXCLUDED.subject, action = EXCLUDED.action,'
                . ' effect_cents = EXCLUDED.effect_cents, effect_label = EXCLUDED.effect_label, confidence = EXCLUDED.confidence, sources = EXCLUDED.sources, figures = EXCLUDED.figures,'
                . ' params = EXCLUDED.params, rank = EXCLUDED.rank, active = TRUE, detected_on = EXCLUDED.detected_on, updated_at = NOW(),'
                . ' ai_title = CASE WHEN gestion_advice.sources::text = EXCLUDED.sources::text THEN gestion_advice.ai_title END,'
                . ' ai_text = CASE WHEN gestion_advice.sources::text = EXCLUDED.sources::text THEN gestion_advice.ai_text END',
                ['p' => $pid, 'sig' => $a['signature'], 'moment' => $a['moment'], 'kind' => $a['kind'], 'subject' => json_encode($a['subject'] ?: new \stdClass()),
                    'action' => json_encode($a['action']), 'effect' => $a['effect']['amount'], 'label' => $a['effect']['label'], 'conf' => $a['confidence'],
                    'sources' => json_encode($a['sources']), 'figures' => json_encode($a['figures']), 'params' => json_encode($a['params'] ?: new \stdClass()),
                    'rank' => $rank + 1, 'day' => $today->format('Y-m-d')]
            );
        }
        $seen === []
            ? $conn->executeStatement('UPDATE gestion_advice SET active = FALSE WHERE period_id = ? AND active', [$pid])
            : $conn->executeStatement('UPDATE gestion_advice SET active = FALSE WHERE period_id = ? AND active AND signature NOT IN (?)', [$pid, $seen], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING]);

        return $conn->fetchAssociative(
            'INSERT INTO gestion_advice_run (period_id, store_day, run_trigger, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING *',
            [$pid, $today->format('Y-m-d'), $trigger, 'rules', $user]
        );
    }

    private function dispatch(int $runId): void
    {
        $this->bus->dispatch(new GenerateGestionAdviceMessage($runId, (string) $this->tenants->getCurrentTenantCode()));
    }

    /** Rédaction perdue (worker arrêté) : au-delà de 15 minutes, la génération passe en erreur */
    private function expirePending(): void
    {
        $this->conn()->executeStatement("UPDATE gestion_advice_run SET status = 'error', finished_at = NOW() WHERE status = 'pending' AND created_at < NOW() - INTERVAL '" . self::PENDING_TIMEOUT_MINUTES . " minutes'");
    }

    public function manualToday(\DateTimeImmutable $today): int
    {
        return (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM gestion_advice_run WHERE run_trigger = 'manual' AND store_day = ?", [$today->format('Y-m-d')]);
    }

    private function assertPeriod(Collections $period): void
    {
        if (!$period->isPeriod()) {
            throw new GestionConflictException('NOT_A_PERIOD', 'Cette collection est manuelle : le guide concerne les périodes.', 404, ['collectionId' => $period->getId()]);
        }
    }

    public function siteLanguage(): string
    {
        return $this->siteLocale?->language(null) ?? 'fr';
    }

    private function siteCurrency(): string
    {
        return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
