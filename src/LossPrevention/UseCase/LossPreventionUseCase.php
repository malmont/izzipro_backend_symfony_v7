<?php

namespace App\LossPrevention\UseCase;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisService;
use App\LossPrevention\Services\LossExerciseService;
use App\LossPrevention\Services\LossFindingReader;
use App\LossPrevention\Services\LossRuleCatalog;
use App\LossPrevention\Services\LossRuleService;
use App\LossPrevention\Services\LossSettingsService;
use App\LossPrevention\Services\LossSite;
use App\LossPrevention\Services\LossTexts;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Scénarios de la section « Prévention des pertes » de la gestion (lot L1-a, `docs/prevention-pertes.md`) : synthèse, constats, décision,
 * règles, réglages, analyse à la demande.
 */
class LossPreventionUseCase
{
    public const SCREENS = ['home' => 'pertes.home', 'findings' => 'pertes.constats', 'finding' => 'pertes.constat', 'rules' => 'pertes.regles', 'settings' => 'pertes.reglages',
        'exercises' => 'pertes.exercices'];
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossAnalysisService $analysis,
        private readonly LossFindingReader $reader,
        private readonly LossRuleService $rules,
        private readonly LossSettingsService $settings,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly LoggerInterface $logger,
        private readonly ?LossExerciseService $exercises = null, // lot EX-1 : mode exercice
    ) {
    }

    /**
     * Synthèse : comptes des constats de la période (journées du magasin), montant en jeu, totaux des clôtures de caisse de la même période
     * (mêmes définitions que le bloc cashDesk des statistiques), état de l'analyse, notice aux salariés. Sans aucun nom.
     * Si la dernière analyse a plus de dix minutes, un passage borné part avant la réponse, sauf `analyse=0`.
     *
     * @param array<string, mixed> $query from, to (Y-m-d ; défaut : les 30 derniers jours), analyse
     * @return array<string, mixed>
     */
    public function summary(array $query, string $locale): array
    {
        $clock = $this->site->clock();
        $today = $clock->day(new \DateTimeImmutable());
        // valider AVANT tout calcul de date : une valeur illisible (texte, tableau `to[]=`) rend 422, jamais une erreur interne ni un défaut silencieux
        $day = fn (string $key) => !isset($query[$key]) || $query[$key] === '' ? null : (is_string($query[$key]) && LossFindingReader::isDay($query[$key]) ? $query[$key] : false);
        $to = $day('to');
        $from = $day('from');
        $errors = array_filter(['from' => $from === false ? 'INVALID_DATE' : null, 'to' => $to === false ? 'INVALID_DATE' : null]);
        $to = is_string($to) ? $to : $today;
        $from = is_string($from) ? $from : null;
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $from ??= (new \DateTimeImmutable($to))->modify('-' . (self::DEFAULT_DAYS - 1) . ' days')->format('Y-m-d');
        if ($to < $from) {
            throw new GestionValidationException(['to' => 'BEFORE_FROM']);
        }
        $ran = null;
        $failed = false;
        if (($query['analyse'] ?? '1') !== '0' && $this->analysis->isStale()) {
            try {
                $ran = $this->analysis->run();
            } catch (\Throwable $e) { // la synthèse répond quand même ; le détail va au journal du serveur, jamais au client
                $failed = true;
                $this->logger->error('Prévention des pertes : analyse à la lecture de la synthèse en échec : ' . $e->getMessage(), ['exception' => $e]);
            }
        }
        $em = $this->emProvider->getEntityManager();
        $counts = $em->getRepository(LossFinding::class)->counts($from, $to);
        $state = $this->analysis->state();
        $caveats = [['code' => 'AMOUNT_NOT_A_LOSS', 'text' => $this->texts->t('amountNotice', $locale)], ['code' => 'RECORDED_SALES_ONLY', 'text' => $this->texts->t('caveats.RECORDED_SALES_ONLY', $locale)]];
        if ($state['skipped'] !== null) {
            array_unshift($caveats, ['code' => 'ENTRIES_SKIPPED', 'text' => $this->texts->t('caveats.ENTRIES_SKIPPED', $locale, ['count' => $state['skipped']['total']])]);
        }
        if ($state['blocked'] !== null) {
            array_unshift($caveats, ['code' => $state['blocked'], 'text' => $this->texts->t('caveats.' . $state['blocked'], $locale)]);
        }

        return [
            'period' => ['from' => $from, 'to' => $to],
            'lastAnalysis' => $state + ['ran' => $ran !== null && $ran['status'] === 'done' ? ['read' => $ran['read'], 'opened' => $ran['opened'], 'completed' => $ran['completed'], 'skipped' => $ran['skipped']] : null, 'failed' => $failed],
            'counts' => [
                'open' => $counts['byStatus'][LossFinding::OPEN], 'watch' => $counts['byStatus'][LossFinding::WATCH], 'new' => $counts['new'],
                'active' => $em->getRepository(LossFinding::class)->activeCount(),
                'byStatus' => array_map(fn (string $s, int $n) => ['status' => $s, 'label' => $this->texts->t('status.' . $s, $locale), 'count' => $n], array_keys($counts['byStatus']), $counts['byStatus']),
                'byRule' => array_map(fn (array $r) => ['code' => (string) $r['code'], 'label' => $this->texts->t('rules.' . $r['code'] . '.label', $locale), 'open' => (int) $r['open'], 'total' => (int) $r['total']], $counts['byRule']),
                'byRegister' => array_map(fn (array $r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'open' => (int) $r['open'], 'total' => (int) $r['total']], $counts['byRegister']),
            ],
            'amountAtStake' => $counts['amountAtStake'],
            'currency' => $this->site->currency(),
            'cashDesk' => $this->site->closings($from, $to),
            'staffNotice' => $this->settings->staffNotice($locale),
            'caveats' => $caveats,
            'screen' => self::SCREENS['home'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function findings(array $query, string $locale): array
    {
        return $this->reader->list($query, $locale); // la liste ne porte aucun nom : sa lecture n'est pas journalisée (seule la fiche l'est)
    }

    /** @return array<string, mixed> */
    public function finding(int $id, string $locale, int $userId): array
    {
        return $this->reader->detail($id, $locale, $userId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function decide(int $id, array $body, string $locale, int $userId): array
    {
        return $this->reader->decide($id, $body, $userId, $locale);
    }

    // ----- Mode exercice (lot EX-1) : routes ouvertes SANS le droit du module ; elles ne servent et ne décident que des constats d'exercice,
    // ne lancent jamais l'analyse réelle et ne créent pas la ligne de réglages du site -----

    /** @return array<string, mixed> */
    public function exercises(string $locale): array
    {
        return $this->exercises->overview($locale);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startExercise(array $body, string $locale, int $userId): array
    {
        return $this->exercises->start($body, $userId, $locale);
    }

    /** @return array<string, mixed> */
    public function exercise(int $id, string $locale, int $userId): array
    {
        return $this->reader->detail($id, $locale, $userId, true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function decideExercise(int $id, array $body, string $locale, int $userId): array
    {
        return $this->reader->decide($id, $body, $userId, $locale, true);
    }

    /** @return array{deleted: int} */
    public function deleteExercises(int $userId): array
    {
        return $this->exercises->deleteAll($userId);
    }

    /** @return array<string, mixed> */
    public function rules(string $locale): array
    {
        return ['rules' => $this->rules->list($locale), 'notice' => $this->texts->t('rules.notice', $locale), 'screen' => self::SCREENS['rules']];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function putRule(string $code, array $body, string $locale, int $userId): array
    {
        return $this->rules->put(strtoupper($code), $body, $userId, $locale);
    }

    /**
     * Aperçu d'un réglage avant de l'appliquer : `{ code, enabled?, params?, personVariant? }` (même forme que l'enregistrement d'une règle).
     * Rend le nombre de constats que la règle ouvrirait sur 30 jours avec ses réglages actuels (`current`) et avec ceux proposés
     * (`wouldOpen`), et jusqu'à trois résumés. AUCUNE écriture. Mêmes blocages que l'analyse (409).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function previewRule(array $body, string $locale): array
    {
        $code = is_string($body['code'] ?? null) ? strtoupper($body['code']) : '';
        if (!LossRuleCatalog::exists($code)) {
            throw new GestionConflictException('RULE_NOT_FOUND', 'Règle inconnue.', 404);
        }
        $out = $this->analysis->preview($code, $this->rules->proposed($code, array_diff_key($body, ['code' => 1])));
        if ($out['status'] === 'blocked') {
            throw new GestionConflictException($out['reason'], $out['reason'] === LossSettingsService::MODULE_NOT_GRANTED
                ? 'Le module Prévention des pertes n\'est pas accordé à ce site : aucune analyse.'
                : 'L\'analyse ne démarre qu\'après la confirmation de l\'information des salariés (réglages de la prévention des pertes).', 409);
        }
        $currency = $this->site->currency();

        return ['code' => $code, 'current' => $out['current'], 'wouldOpen' => $out['wouldOpen'], 'days' => $out['days'], 'truncated' => $out['truncated'], 'skipped' => $out['skipped'],
            'sample' => array_map(fn (array $f) => ['summary' => $this->reader->summarize($code, (string) $f['variant'], $f['facts'], $currency, $locale), 'storeDay' => (string) $f['store_day'],
                'amountAtStake' => $f['amount_at_stake'], 'personPattern' => $f['variant'] === LossFinding::VARIANT_PERSON], $out['sample']),
            'notice' => $this->texts->t('rules.previewNotice', $locale)];
    }

    /** @return array<string, mixed> */
    public function settings(string $locale): array
    {
        return $this->settings->view($locale) + ['screen' => self::SCREENS['settings']];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function putSettings(array $body, string $locale, int $userId): array
    {
        return $this->settings->update($body, $userId, $locale) + ['screen' => self::SCREENS['settings']];
    }

    /**
     * Un passage borné de l'analyse, à la demande. 409 `MODULE_NOT_GRANTED` (droit du module non accordé, même en mode « demo » de la
     * plateforme) ou `STAFF_NOTICE_REQUIRED` (notice exigée et non confirmée).
     *
     * @return array{status: string, read: int, opened: int, completed: int, skipped: int, pending: bool}
     */
    public function analyse(): array
    {
        $out = $this->analysis->run();
        if ($out['status'] === 'blocked') {
            throw new GestionConflictException($out['reason'], $out['reason'] === LossSettingsService::MODULE_NOT_GRANTED
                ? 'Le module Prévention des pertes n\'est pas accordé à ce site : aucune analyse.'
                : 'L\'analyse ne démarre qu\'après la confirmation de l\'information des salariés (réglages de la prévention des pertes).', 409);
        }
        unset($out['reason']);

        return $out;
    }
}
