<?php

namespace App\Services\GestionService;

use App\Entity\Collections;
use App\Message\GenerateGestionAdviceMessage;
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
            'headline' => $headline !== false && $headline['headline_locale'] === $locale ? $headline['headline'] : null,
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

    /** Fait / écarté : statut porté par le site, auteur et date gardés (le sujet reste en base pour la mesure de G1b) */
    public function setStatus(int $id, string $status, ?string $user, mixed $note, string $locale): array
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
        $column = $status === 'done' ? 'done_at' : 'dismissed_at';
        $this->conn()->executeStatement("UPDATE gestion_advice SET status = ?, status_by = ?, status_note = ?, $column = NOW(), updated_at = NOW() WHERE id = ?",
            [$status, $user, $note === null ? null : mb_substr(trim($note), 0, 1000), $id]);

        return $this->toArray($this->conn()->fetchAssociative('SELECT * FROM gestion_advice WHERE id = ?', [$id]), $locale);
    }

    /** Forme rendue par l'API */
    public function toArray(array $r, string $locale): array
    {
        $sources = json_decode((string) $r['sources'], true) ?: [];
        $params = json_decode((string) $r['params'], true) ?: [];
        $useAi = $r['ai_text'] !== null && $r['ai_locale'] === $locale;
        [$title, $text] = $useAi ? [(string) $r['ai_title'], (string) $r['ai_text']] : $this->fallback((string) $r['kind'], $sources, $params, (int) $r['effect_cents'], $locale);
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
        ];
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
                default => (string) $v,
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

    private function manualToday(\DateTimeImmutable $today): int
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
