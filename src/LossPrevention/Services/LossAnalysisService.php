<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\LossPrevention\Services\Rule\LossRuleInterface;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
    /** Aperçu d'un réglage : période relue, nombre maximal d'entrées du journal de caisse, taille de l'échantillon */
    public const PREVIEW_DAYS = 30;
    /**
     * Entrées jouées au plus par un aperçu, les plus récentes. Mesuré sur la base de test (14/11/2026) : 180 ouvertures de tiroir en trois
     * sessions de 60, 840 ms pour les deux jeux, soit environ 2,3 ms par entrée et par jeu pour une règle qui relit sa session ; à 1 000
     * entrées, de l'ordre de 5 s au pire. Au-delà, `truncated` le dit.
     */
    public const PREVIEW_MAX = 1000;
    public const PREVIEW_SAMPLE = 3;
    /** Nombre de lignes récentes du journal du module relues pour y chercher des entrées sautées */
    private const SKIPPED_SCAN = 5000;
    /** La remarque sur les entrées sautées ne porte que sur ces derniers jours */
    public const SKIPPED_DAYS = 30;
    /** Clé du verrou consultatif (par base, donc par site) ; distincte de celle du journal de caisse */
    private const LOCK_KEY = 7461077;

    /** @var list<LossRuleInterface> */
    private readonly array $rules;

    /** @param iterable<LossRuleInterface> $rules toutes les règles du module (étiquette `loss_prevention.rule`), rangées par classe pour un ordre stable */
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSettingsService $settings,
        private readonly LossRuleService $ruleSettings,
        private readonly LossSite $site,
        private readonly LossFindingWriter $writer,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('loss_prevention.rule')] iterable $rules,
        private readonly ?LossSaleLinkService $sales = null,
        private readonly ?LossExitService $exits = null,
        private readonly ?LossDepotService $depot = null,
        private readonly ?LossCountService $counts = null,
    ) {
        $list = [...$rules];
        usort($list, fn (LossRuleInterface $a, LossRuleInterface $b) => strcmp($a::class, $b::class));
        $this->rules = $list;
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
            $this->sales?->begin();
            // Les ventes et les retours sont toujours lus (lot L3-b2a) : la vente se relie aux pièces quelles que soient les règles allumées
            $types = array_values(array_unique(array_merge(array_keys($byType), $this->sales !== null ? LossSaleLinkService::TYPES : [])));
            $rows = $types === [] ? [] : $conn->fetchAllAssociative(
                'SELECT ' . LossAuditEntry::COLUMNS . ' FROM pos_audit_log WHERE sequence > :cursor AND sequence <= :max AND type IN (:types) ORDER BY sequence LIMIT ' . max(1, $limit),
                ['cursor' => $cursor, 'max' => $max, 'types' => $types], ['types' => ArrayParameterType::STRING]);
            $skipped = 0;
            $skippedSequences = [];
            // Y a-t-il quelque chose à rattraper ? Oui au début du passage (des lectures ont pu arriver depuis le précédent), puis de
            // nouveau après chaque vente ou retour lu : entre deux clôtures sans vente, rien n'a changé
            $toCatchUp = true;
            $settledFirst = $config[LossRuleCatalog::UNREAD_SOLD]['enabled'] ?? false;
            foreach ($rows as $row) {
                $entry = LossAuditEntry::fromRow($row);
                if ($this->sales !== null && in_array($entry['type'], LossSaleLinkService::TYPES, true)) {
                    // la vente reliée aux pièces : sous son propre point de reprise, comme une règle (une entrée en échec est sautée et dite)
                    $toCatchUp = true;
                    $conn->executeStatement('SAVEPOINT loss_entry');
                    try {
                        $this->sales->handle($entry);
                        $conn->executeStatement('RELEASE SAVEPOINT loss_entry');
                    } catch (\Throwable $e) {
                        $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_entry');
                        $skippedSequences[$entry['sequence']] = true;
                        $skipped = count($skippedSequences);
                        $this->logger->error(sprintf('Prévention des pertes : vente reliée aux pièces en échec sur l\'entrée %d du journal de caisse, entrée sautée : %s', $entry['sequence'], $e->getMessage()), ['exception' => $e]);
                    }
                }
                if ($this->sales !== null && $toCatchUp && $settledFirst && $entry['type'] === 'Z_REPORT_CREATED') {
                    // Une clôture : la règle qui compte les articles vendus la juge APRÈS le rattrapage (tapis retenté, sortie), pas
                    // avant (lot L3-b2b). Seulement si cette règle est allumée et s'il y a du neuf depuis le dernier rattrapage du
                    // passage (plusieurs postes fermés à la suite : un seul rattrapage) ; sous son point de reprise, un échec n'arrête rien
                    $toCatchUp = false;
                    $conn->executeStatement('SAVEPOINT loss_sweep');
                    try {
                        $this->sales->catchUp(time(), $entry['occurredAt']->getTimestamp());
                        $conn->executeStatement('RELEASE SAVEPOINT loss_sweep');
                    } catch (\Throwable $e) {
                        $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_sweep');
                        $this->logger->error('Prévention des pertes : rattrapage avant une clôture en échec, passage poursuivi : ' . $e->getMessage(), ['exception' => $e]);
                    }
                }
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
            if ($this->sales !== null) {
                // commandes web (lot L4-b2 : payées, annulées, articles rapportés), rattrapage (tapis, sortie) puis conservation des
                // présences : à chaque passage, chacun sous son point de reprise (un échec de l'un n'arrête ni les autres ni l'analyse ; il
                // est écrit au journal du serveur). Un article de commande web écrit est rattrapé dans le même passage
                // l'heure jusqu'à laquelle les ventes sont lues (comme pour la sortie, plus bas) : une commande web ne prend pas une lecture que
                // la caisse peut encore expliquer
                $readsUntil = count($rows) >= max(1, $limit) ? (new \DateTimeImmutable((string) end($rows)['occurred_at'], new \DateTimeZone('UTC')))->getTimestamp() : time();
                $steps = [
                    'commandes web' => function () use (&$toCatchUp): int {
                        $written = $this->sales->webOrders(time());
                        $toCatchUp = $toCatchUp || $written > 0;

                        return $written;
                    },
                    'rattrapage' => function () use (&$toCatchUp, $readsUntil): int {
                        return $toCatchUp ? $this->sales->catchUp(time(), $readsUntil) : 0;
                    },
                    'conservation des présences' => fn (): int => $this->sales->purge(time()),
                ];
                $stepFailed = [];
                foreach ($steps as $what => $step) {
                    $conn->executeStatement('SAVEPOINT loss_sweep');
                    try {
                        $step();
                        $conn->executeStatement('RELEASE SAVEPOINT loss_sweep');
                    } catch (\Throwable $e) {
                        $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_sweep');
                        $stepFailed[$what] = true;
                        $this->logger->error(sprintf('Prévention des pertes : %s en échec, passage poursuivi : %s', $what, $e->getMessage()), ['exception' => $e]);
                    }
                }
            }
            if ($skipped > 0) {
                $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::ENTRIES_SKIPPED, null, null, ['count' => $skipped, 'sequences' => array_slice(array_keys($skippedSequences), 0, 50)]);
            }
            $full = count($rows) >= max(1, $limit);
            $newCursor = $full ? (int) end($rows)['sequence'] : $max;
            $exitPending = false;
            // les commandes web n'ont pas pu être lues (ou rattrapés) : les sorties attendent le passage suivant plutôt que d'ouvrir de faux constats
            if ($this->sales !== null && $this->exits !== null && !isset($stepFailed['commandes web']) && !isset($stepFailed['rattrapage'])) {
                // Lot L4-b : les règles de la sortie, APRÈS le rattrapage. Les ventes sont lues jusqu'à maintenant si le journal a été lu
                // jusqu'au bout, sinon jusqu'à l'heure de la dernière entrée lue ; une lecture plus récente que cela, moins la fenêtre du
                // rattrapage, attend le passage suivant. Sous son point de reprise : un échec n'arrête pas l'analyse
                $horizon = $full ? (new \DateTimeImmutable((string) end($rows)['occurred_at'], new \DateTimeZone('UTC')))->getTimestamp() : time();
                $exitConfigs = [];
                foreach (LossRuleCatalog::EXIT_RULES as $code) {
                    $exitConfigs[$code] = $this->ruleSettings->effective($code);
                }
                $conn->executeStatement('SAVEPOINT loss_sweep');
                $counters = $this->writer->counters();
                try {
                    $windows = $this->sales->windows();
                    $exitPending = $this->exits->sweep($ctx, $exitConfigs, $horizon, $windows['catchUp'], $windows['webDays'] * 86400)['pending'];
                    $conn->executeStatement('RELEASE SAVEPOINT loss_sweep');
                } catch (\Throwable $e) {
                    $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_sweep');
                    $this->writer->restoreCounters($counters);
                    $this->logger->error('Prévention des pertes : règles de la sortie en échec, passage poursuivi : ' . $e->getMessage(), ['exception' => $e]);
                }
                if ($this->depot !== null) {
                    // Lot L4-c : les règles du dépôt et des heures d'ouverture, après la sortie, à la même heure des ventes ; sous leur point
                    // de reprise : un échec n'arrête pas l'analyse
                    $depotConfigs = [];
                    // la porte arrière dit le doute d'une vente rattrapée comme la sortie : la règle EXIT_COVERED est passée aussi
                    foreach ([...LossRuleCatalog::DEPOT_RULES, LossRuleCatalog::EXIT_COVERED] as $code) {
                        $depotConfigs[$code] = $this->ruleSettings->effective($code);
                    }
                    $conn->executeStatement('SAVEPOINT loss_sweep');
                    $counters = $this->writer->counters();
                    try {
                        $exitPending = $this->depot->sweep($ctx, $depotConfigs, $horizon, $this->sales->windows()['catchUp'])['pending'] || $exitPending;
                        $conn->executeStatement('RELEASE SAVEPOINT loss_sweep');
                    } catch (\Throwable $e) {
                        $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_sweep');
                        $this->writer->restoreCounters($counters);
                        $this->logger->error('Prévention des pertes : règles du dépôt en échec, passage poursuivi : ' . $e->getMessage(), ['exception' => $e]);
                    }
                }
            }
            if ($this->counts !== null) {
                // Lot L4-d : les comptages oubliés se ferment sans conclusion ; les pièces absentes relues reprennent leur état (le constat de
                // leur comptage le dit). Sous son point de reprise : un échec n'arrête pas l'analyse
                $conn->executeStatement('SAVEPOINT loss_sweep');
                $counters = $this->writer->counters();
                try {
                    $this->counts->sweep($this->writer);
                    $conn->executeStatement('RELEASE SAVEPOINT loss_sweep');
                } catch (\Throwable $e) {
                    $conn->executeStatement('ROLLBACK TO SAVEPOINT loss_sweep');
                    $this->writer->restoreCounters($counters);
                    $this->logger->error('Prévention des pertes : comptages en échec, passage poursuivi : ' . $e->getMessage(), ['exception' => $e]);
                }
            }
            $settings->update(['cursor_sequence' => $newCursor, 'backfill_until_sequence' => $backfillUntil, 'last_analysis_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->writer->flushCounters();
            throw $e;
        }

        // il en reste : des entrées du journal de caisse, ou des lectures de sortie mûres derrière la limite du passage (lot L4-b)
        return ['status' => 'done', 'read' => count($rows), 'skipped' => $skipped, 'pending' => $full || $exitPending] + $this->writer->flushCounters();
    }

    /**
     * Aperçu d'un réglage (lot L1-b) : combien de constats une règle ouvrirait sur les `PREVIEW_DAYS` derniers jours avec ses réglages actuels,
     * et combien avec les réglages proposés. Les VRAIES règles sont jouées sur le journal de caisse, mais leurs constats vont dans un puits en
     * mémoire : RIEN n'est écrit (ni constat, ni preuve, ni journal, ni curseur). Les deux nombres sont calculés de la même façon, sans tenir
     * compte des constats déjà là ni des décisions : ils se comparent. Lecture bornée (`PREVIEW_MAX` entrées, `truncated` au-delà), et jamais
     * sous le plancher de l'analyse.
     *
     * @param array{enabled: bool, params: array<string, int>, personEnabled: bool, personParams: array<string, int>} $proposed
     * @return array{status: string, reason?: string, current: int, wouldOpen: int, days: int, truncated: bool, skipped: int, limit: int, sample: list<array<string, mixed>>}
     */
    public function preview(string $code, array $proposed, int $max = self::PREVIEW_MAX): array
    {
        $max = max(1, min(self::PREVIEW_MAX, $max)); // la borne ne se relève jamais ; un test peut la baisser pour la vérifier
        $none = ['current' => 0, 'wouldOpen' => 0, 'days' => self::PREVIEW_DAYS, 'truncated' => false, 'skipped' => 0, 'limit' => $max, 'sample' => []];
        $blocked = $this->settings->blockedReason();
        if ($blocked !== null) {
            return ['status' => 'blocked', 'reason' => $blocked] + $none;
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        if (in_array($code, LossRuleCatalog::ANTENNA_RULES, true)) {
            // les règles de la sortie et du dépôt (lots L4-b, L4-c) lisent les antennes, pas le journal de caisse : l'aperçu n'a rien à rejouer, il le dit
            return ['status' => 'unavailable', 'reason' => 'READS_NOT_REPLAYED'] + $none;
        }
        $rules = array_values(array_filter($this->rules, fn (LossRuleInterface $r) => $r->code() === $code));
        $types = array_values(array_unique(array_merge([], ...array_map(fn (LossRuleInterface $r) => $r->types(), $rules))));
        if ($types === []) {
            return ['status' => 'done'] + $none;
        }
        $floor = (int) ($em->getRepository(LossSetting::class)->row()['analysis_floor_sequence'] ?? 0);
        $since = (new \DateTimeImmutable('-' . self::PREVIEW_DAYS . ' days'))->format('Y-m-d H:i:s');
        $from = max($floor, (int) $conn->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM pos_audit_log WHERE occurred_at < ?', [$since]));
        $rows = $conn->fetchAllAssociative(
            'SELECT ' . LossAuditEntry::COLUMNS . ' FROM pos_audit_log WHERE sequence > :from AND type IN (:types) ORDER BY sequence DESC LIMIT ' . ($max + 1),
            ['from' => $from, 'types' => $types], ['types' => ArrayParameterType::STRING]);
        $truncated = count($rows) > $max;
        // Au-delà de la borne, ce sont les entrées les plus RÉCENTES qui sont jouées, dans l'ordre du journal
        $entries = array_map([LossAuditEntry::class, 'fromRow'], array_reverse(array_slice($rows, 0, $max)));
        $skipped = [];
        $play = function (array $config) use ($conn, $entries, $rules, $code, $floor, &$skipped): array {
            $sink = new LossPreviewSink();
            if (!$config['enabled']) {
                return [];
            }
            $ctx = new LossAnalysisContext($conn, $this->site->clock(), $this->site->currency(), $this->site->discrepancyThreshold(), [$code => $config], $sink, null, $floor);
            foreach ($entries as $entry) {
                foreach ($rules as $rule) {
                    if (!in_array($entry['type'], $rule->types(), true)) {
                        continue;
                    }
                    $before = $sink->snapshot();
                    try { // rien n'est écrit (puits en mémoire) : une règle en échec saute l'entrée, l'aperçu continue et le dit (`skipped`)
                        $rule->handle($entry, $ctx);
                    } catch (\Throwable $e) {
                        $sink->restore($before); // ce que la règle avait posé dans le puits avant de lever n'est pas compté
                        $skipped[$entry['sequence']] = true;
                        $this->logger->error(sprintf('Prévention des pertes : aperçu, règle %s en échec sur l\'entrée %d du journal de caisse, entrée sautée : %s', $rule::class, $entry['sequence'], $e->getMessage()), ['exception' => $e]);
                    }
                }
            }

            return $sink->findings();
        };
        $would = $play($proposed);

        return ['status' => 'done', 'current' => count($play($this->ruleSettings->effective($code))), 'wouldOpen' => count($would), 'days' => self::PREVIEW_DAYS,
            'truncated' => $truncated, 'skipped' => count($skipped), 'limit' => $max, 'sample' => array_slice($would, 0, self::PREVIEW_SAMPLE)];
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
        // lecture bornée, par l'index (type, id) du journal du module (lot EX-1) : les lignes « entrées sautées » les plus récentes, jamais
        // le journal entier. Lues par leur TYPE : d'autres lignes en nombre (exercices lancés, consultations) ne les repoussent pas hors de
        // la lecture
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT occurred_at, data FROM loss_journal WHERE type = ? ORDER BY id DESC LIMIT ' . self::SKIPPED_SCAN, [LossJournalEntry::ENTRIES_SKIPPED]);
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
