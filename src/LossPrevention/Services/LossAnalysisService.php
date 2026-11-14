<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\LossPrevention\Services\Rule\CashDiscrepancyRule;
use App\LossPrevention\Services\Rule\CashShortageSeriesRule;
use App\LossPrevention\Services\Rule\DrawerNoSaleRule;
use App\LossPrevention\Services\Rule\LossRuleInterface;
use App\LossPrevention\Services\Rule\VoidAfterSaleRule;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerInterface;

/**
 * Analyse de l'activité de caisse (lot L1-a, `docs/prevention-pertes.md`, plan § C). Elle LIT le journal de caisse et les tables de caisse et
 * n'écrit que dans les tables du module : jamais dans une vente, une session ni le journal de caisse.
 *
 * - Curseur : la séquence du journal de caisse (continue, écrite sous verrou dans la transaction de chaque événement, donc validée dans
 *   l'ordre). Chaque entrée est présentée une seule fois aux règles, y compris une vente hors ligne synchronisée plus tard.
 * - Un passage est borné (`BATCH` entrées) et tient dans UNE transaction : constats, preuves et curseur avancent ensemble ou pas du tout.
 * - Un seul passage à la fois par site (verrou consultatif de la transaction) : un second lancement rend `busy` sans attendre.
 * - Première analyse : reprise des `BACKFILL_DAYS` derniers jours ; les constats nés de ces entrées sont marqués « reprise ».
 * - Aucune analyse (`blocked`, le curseur ne bouge pas) : sans le droit du module accordé au site, même en mode « demo » de la plateforme
 *   (`MODULE_NOT_GRANTED`) ; ou tant que la notice aux salariés exigée par le pays n'est pas confirmée (`STAFF_NOTICE_REQUIRED`). Là où la
 *   notice est exigée, rien de ce qui précède sa confirmation n'est lu (plancher `analysis_floor_sequence`), ni par le curseur ni par une règle.
 * - Une entrée qui fait échouer une règle est sautée (point de reprise SQL par règle) et consignée au journal du serveur : elle ne bloque pas
 *   la suite, mais elle ne sera plus présentée. Ce n'est donc jamais silencieux : `skipped` est compté, gardé au journal du module
 *   (`ENTRIES_SKIPPED` : nombre d'opérations, chacune une fois, et séquences), rendu par la synthèse (`lastAnalysis.skipped`, sur les 30
 *   derniers jours) et par la commande, qui sort en échec.
 */
class LossAnalysisService
{
    public const BATCH = 2000;
    public const BACKFILL_DAYS = 30;
    /** Âge au-delà duquel la synthèse relance un passage */
    public const STALE_SECONDS = 600;
    /** Nombre de lignes récentes du journal du module relues pour y chercher des entrées sautées */
    private const SKIPPED_SCAN = 5000;
    /** La remarque sur les entrées sautées ne porte que sur ces derniers jours */
    public const SKIPPED_DAYS = 30;
    /** Clé du verrou consultatif (par base, donc par site) ; distincte de celle du journal de caisse */
    private const LOCK_KEY = 7461077;

    /** @var list<LossRuleInterface> */
    private readonly array $rules;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSettingsService $settings,
        private readonly LossRuleService $ruleSettings,
        private readonly LossSite $site,
        private readonly LossFindingWriter $writer,
        private readonly LoggerInterface $logger,
        CashDiscrepancyRule $cashDiscrepancy,
        CashShortageSeriesRule $cashShortageSeries,
        DrawerNoSaleRule $drawerNoSale,
        VoidAfterSaleRule $voidAfterSale,
    ) {
        $this->rules = [$cashDiscrepancy, $cashShortageSeries, $drawerNoSale, $voidAfterSale];
    }

    /**
     * Un passage borné.
     *
     * @return array{status: string, read: int, opened: int, completed: int, skipped: int, pending: bool, reason?: string} status : done | blocked | busy
     */
    public function run(int $limit = self::BATCH): array
    {
        $none = ['read' => 0, 'opened' => 0, 'completed' => 0, 'skipped' => 0, 'pending' => false];
        $blocked = $this->settings->blockedReason();
        if ($blocked !== null) {
            return ['status' => 'blocked', 'reason' => $blocked] + $none;
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $settings = $em->getRepository(LossSetting::class);
        $settings->row(); // la ligne existe avant la transaction
        $conn->beginTransaction();
        try {
            if (!$conn->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [self::LOCK_KEY])) {
                $conn->rollBack();

                return ['status' => 'busy'] + $none;
            }
            $state = $settings->row(true);
            $max = (int) $conn->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM pos_audit_log');
            $cursor = $state['cursor_sequence'] !== null ? (int) $state['cursor_sequence'] : null;
            $backfillUntil = $state['backfill_until_sequence'] !== null ? (int) $state['backfill_until_sequence'] : null;
            // Plancher (choix A) : là où la notice aux salariés est exigée, rien de ce qui précède sa confirmation n'est lu
            $floor = (int) ($state['analysis_floor_sequence'] ?? 0);
            if ($cursor !== null) {
                $cursor = max($cursor, $floor);
            }
            if ($cursor === null) { // première analyse : reprise de l'historique récent (jamais sous le plancher)
                $since = (new \DateTimeImmutable('-' . self::BACKFILL_DAYS . ' days'))->format('Y-m-d H:i:s');
                $cursor = max($floor, (int) $conn->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM pos_audit_log WHERE occurred_at < ?', [$since]));
                $backfillUntil = $max;
                $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::ANALYSIS_STARTED, null, null, ['from' => $cursor, 'backfillUntil' => $backfillUntil, 'days' => self::BACKFILL_DAYS]);
            }
            $config = [];
            $byType = [];
            foreach ($this->rules as $rule) {
                $config[$rule->code()] = $this->ruleSettings->effective($rule->code());
                if ($config[$rule->code()]['enabled']) {
                    foreach ($rule->types() as $type) {
                        $byType[$type][] = $rule;
                    }
                }
            }
            $ctx = new LossAnalysisContext($conn, $this->site->clock(), $this->site->currency(), $this->site->discrepancyThreshold(), $config, $this->writer, $backfillUntil, $floor);
            $this->writer->flushCounters();
            $rows = $byType === [] ? [] : $conn->fetchAllAssociative(
                'SELECT ' . LossAuditEntry::COLUMNS . ' FROM pos_audit_log WHERE sequence > :cursor AND sequence <= :max AND type IN (:types) ORDER BY sequence LIMIT ' . max(1, $limit),
                ['cursor' => $cursor, 'max' => $max, 'types' => array_keys($byType)], ['types' => ArrayParameterType::STRING]);
            $skipped = 0;
            $skippedSequences = [];
            foreach ($rows as $row) {
                $entry = LossAuditEntry::fromRow($row);
                foreach ($byType[$entry['type']] ?? [] as $rule) {
                    $conn->executeStatement('SAVEPOINT loss_entry');
                    $counters = $this->writer->counters();
                    try {
                        $rule->handle($entry, $ctx);
                        $conn->executeStatement('RELEASE SAVEPOINT loss_entry');
                    } catch (\Throwable $e) {
                        $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_entry');
                        $this->writer->restoreCounters($counters); // ce que la règle avait écrit est annulé : ne pas le compter
                        $skippedSequences[$entry['sequence']] = true;
                        $skipped = count($skippedSequences); // une opération sautée par deux règles compte une fois
                        $this->logger->error(sprintf('Prévention des pertes : règle %s en échec sur l\'entrée %d du journal de caisse, entrée sautée : %s', $rule::class, $entry['sequence'], $e->getMessage()), ['exception' => $e]);
                    }
                }
            }
            if ($skipped > 0) {
                $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::ENTRIES_SKIPPED, null, null, ['count' => $skipped, 'sequences' => array_slice(array_keys($skippedSequences), 0, 50)]);
            }
            $full = count($rows) >= max(1, $limit);
            $newCursor = $full ? (int) end($rows)['sequence'] : $max;
            $settings->update(['cursor_sequence' => $newCursor, 'backfill_until_sequence' => $backfillUntil, 'last_analysis_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->writer->flushCounters();
            throw $e;
        }

        return ['status' => 'done', 'read' => count($rows), 'skipped' => $skipped, 'pending' => $full] + $this->writer->flushCounters();
    }

    /**
     * État de l'analyse pour la synthèse.
     *
     * @return array{at: ?string, pending: bool, blocked: ?string, skipped: ?array{total: int, lastAt: string}}
     */
    public function state(): array
    {
        $em = $this->emProvider->getEntityManager();
        $row = $em->getRepository(LossSetting::class)->row();
        $max = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM pos_audit_log');

        return [
            'at' => $row['last_analysis_at'] !== null ? LossAuditEntry::iso(LossAuditEntry::utc((string) $row['last_analysis_at'])) : null,
            'pending' => $row['cursor_sequence'] === null ? $max > 0 : (int) $row['cursor_sequence'] < $max,
            'blocked' => $this->settings->blockedReason(),
            'skipped' => $this->skipped(),
        ];
    }

    /**
     * Opérations de caisse qu'une règle n'a pas pu lire (sautées, jamais représentées) sur les `SKIPPED_DAYS` derniers jours : total (chaque
     * opération une fois) et date du dernier cas, ou null. Une anomalie à faire remonter : la synthèse et la commande le montrent ; passé ce
     * délai, la remarque s'éteint d'elle-même (le journal du module, lui, garde tout).
     *
     * @return array{total: int, lastAt: string}|null
     */
    public function skipped(): ?array
    {
        // lecture bornée : les dernières lignes du journal du module par identifiant (clé primaire), filtrées ensuite ; le journal n'a pas
        // d'index sur le type et ne doit pas être relu en entier à chaque synthèse
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT occurred_at, data FROM (SELECT id, type, occurred_at, data FROM loss_journal ORDER BY id DESC LIMIT ' . self::SKIPPED_SCAN . ') recent WHERE type = ? ORDER BY id DESC',
            [LossJournalEntry::ENTRIES_SKIPPED]);
        $since = (new \DateTimeImmutable('-' . self::SKIPPED_DAYS . ' days'))->format('Y-m-d H:i:s');
        $rows = array_values(array_filter($rows, fn (array $row) => (string) $row['occurred_at'] >= $since));
        if ($rows === []) {
            return null;
        }
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) ((json_decode((string) $row['data'], true) ?: [])['count'] ?? 0);
        }

        return ['total' => $total, 'lastAt' => LossAuditEntry::iso(LossAuditEntry::utc((string) $rows[0]['occurred_at']))];
    }

    /** La dernière analyse est-elle assez ancienne pour qu'on en relance une à la lecture de la synthèse ? */
    public function isStale(): bool
    {
        $at = $this->emProvider->getEntityManager()->getRepository(LossSetting::class)->row()['last_analysis_at'];

        return $at === null || LossAuditEntry::utc((string) $at)->getTimestamp() < time() - self::STALE_SECONDS;
    }
}
