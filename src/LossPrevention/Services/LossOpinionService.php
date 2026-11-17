<?php

namespace App\LossPrevention\Services;

use App\Entity\AiUsage;
use App\Gestion\Services\GestionConflictException;
use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossOpinion;
use App\LossPrevention\Message\WriteLossOpinionMessage;
use App\LossPrevention\Repository\LossOpinionRepository;
use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * L'avis de l'IA sur un constat (lot L5-c, 17/11/2026, `docs/prevention-pertes.md` § 18.3 et § 18.10). Il suit le circuit de la console de
 * la gestion (unité réservée, file `gestion_ai`, client commun du modèle, contrôle des nombres), avec ses règles propres :
 *
 * - **Le pourcentage est calculé par le serveur, jamais par l'IA** (décision 11 du § 12) : la part des constats SEMBLABLES confirmés parmi
 *   ceux qui ont été tranchés (même règle et même variante, ce site, 12 mois, sans l'exercice ni le constat lui-même), donnée seulement à
 *   partir de `MIN_DECIDED` cas tranchés. L'IA ne rédige que le résumé, les facteurs et les autres explications.
 * - **L'entrée du modèle est construite par LISTE BLANCHE** (`input`) : le texte de la règle, le résumé du constat, les NOMBRES de ses faits
 *   et de ses preuves (jamais un identifiant), le genre et l'heure du magasin de chaque preuve, les mesures, et la liste des nombres admis
 *   dans le texte (`numbers` : ceux de l'entrée et leurs dérivés évidents). Jamais un nom, un rôle, un identifiant de compte, un motif saisi,
 *   ni « qui était en poste ».
 * - **La sortie est vérifiée** (`rejected`) : aucun nombre hors de la liste, aucun mot de la liste refusée (`FORBIDDEN`), aucun nom d'un
 *   compte de la boutique. Un seul nouvel essai, puis l'échec : l'unité est rendue.
 * - **Jamais sur une règle « par personne »** (`OPINION_NOT_ALLOWED`) ; jamais automatique : à la demande du gérant, 1 unité.
 * - La source `clip` (extrait vidéo, lot L3-d) est réservée et refusée (`SOURCES`) : rien ne l'ouvre avant l'avis de l'avocat.
 */
class LossOpinionService
{
    public const COST = 1;
    public const DAILY_LIMIT = 30;
    public const MIN_DECIDED = 20;
    public const SIMILAR_MONTHS = 12;
    /** Délai d'un avis (relecture du 17/11/2026 : deux essais de 60 s, plus l'attente dans la file partagée `gestion_ai`) */
    public const TIMEOUT_SECONDS = 300;
    /** Verrou de la limite du jour : deux demandes simultanées ne passent pas toutes les deux la dernière place */
    private const LIMIT_LOCK = 0x4c4f504e;
    public const MODEL = 'claude-sonnet-5-5';
    public const MODEL_TIMEOUT = 60.0;
    /** Ce que l'avis peut lire. `clip` (extrait vidéo) est réservé à L3-d, après l'avis de l'avocat : refusé jusque-là */
    public const SOURCES = ['finding' => true, 'evidence' => true, 'clip' => false];
    /**
     * Clés NUMÉRIQUES que l'entrée peut porter (relecture du 17/11/2026 : une liste BLANCHE, jamais une liste refusée) : des mesures, jamais
     * un identifiant de compte, de vente, de poste ou de pièce. Une clé nouvelle d'une règle n'entre dans l'avis qu'ajoutée ici.
     */
    public const NUMBER_KEYS = ['absent', 'amount', 'bankDeposit', 'cardRefund', 'cashPart', 'cashRefund', 'catalogPrice', 'catalogStock', 'catchUp', 'count', 'countedCash',
        'counter', 'days', 'delayMinutes', 'difference', 'discount', 'discrepancy', 'exitReadsCount', 'expected', 'expectedCash', 'expectedPieces', 'found', 'foundPieces',
        'gross', 'judged', 'keptFund', 'manualPrice', 'margin', 'max', 'min', 'minAmount', 'minCount', 'minShare', 'minutes', 'missing', 'perDay', 'perSession',
        'presenceDays', 'price', 'quantity', 'reads', 'refundAmount', 'remaining', 'returned', 'share', 'threshold', 'total', 'tracked', 'unitPrice', 'unread',
        'writtenReasons', 'zoneMovesCount', 'minutesOutsideHours'];
    /** Clés de texte (jamais libre) que l'entrée peut porter : des valeurs d'une liste fermée */
    public const TEXT_KEYS = ['zone', 'case', 'scope', 'level', 'origin', 'kind', 'channel', 'side'];
    /** Mots qui désignent une faute, une personne soupçonnée ou une suite : refusés dans un avis (français et anglais) */
    /**
     * Expressions refusées dans un avis (français et anglais ; relecture du 17/11/2026) : une faute, une suite, un vol, une personne désignée,
     * un pourcentage ou une fraction que le serveur n'a pas calculés. Bornées par des limites de mot.
     */
    public const FORBIDDEN = [
        // une suite ou une sanction
        '/(*UCP)\\bsanction/u', '/(*UCP)\\blicenci/u', '/(*UCP)\\bmise à pied/u', '/(*UCP)\\bavertissement/u', '/(*UCP)\\bplainte/u', '/(*UCP)\\bpolice/u', '/(*UCP)\\bgendarm/u', '/(*UCP)\\bfouill/u',
        '/(*UCP)\\bdisciplin/u', '/(*UCP)\\bpuni/u', '/(*UCP)\\bfaute grave/u', '/(*UCP)\\baccus(?!é de réception)/u',
        '/(*UCP)\\bfired?\\b/u', '/(*UCP)\\bfiring/u', '/(*UCP)\\bterminat/u', '/(*UCP)\\blawsuit/u', '/(*UCP)\\bcomplaint/u', '/(*UCP)\\bwarning/u', '/(*UCP)\\bpunish/u', '/(*UCP)\\baccuse/u', '/(*UCP)\\bsearch (the|him|her)/u', '/(*UCP)\\bquestion (the|him|her)/u',
        // une faute ou un vol
        '/(*UCP)\\bcoupable/u', '/(*UCP)\\bsuspect/u', '/(*UCP)\\bvol(s|eur|euse|é|ée|er)?\\b/u', '/(*UCP)\\bdérob/u', '/(*UCP)\\bsubtilis/u', '/(*UCP)\\bguilty/u', '/(*UCP)\\bthie(f|ves)/u', '/(*UCP)\\btheft/u', '/(*UCP)\\bsteal/u',
        '/(*UCP)\\bstole/u', '/(*UCP)\\bshoplift/u',
        // une personne désignée
        '/(*UCP)\\bemploy(é|ée|és|ées|e|ee|ees)\\b/u', '/(*UCP)\\bsalarié/u', '/(*UCP)\\bcaissi(er|ère)/u', '/(*UCP)\\bvendeu(r|se)/u', '/(*UCP)\\bcollègue/u', '/(*UCP)\\bcliente?s?\\b/u',
        '/(*UCP)\\bstaff\\b/u', '/(*UCP)\\bcashiers?\\b/u', '/(*UCP)\\bemployees?\\b/u', '/(*UCP)\\bclerks?\\b/u', '/(*UCP)\\bcolleagues?\\b/u', '/(*UCP)\\bcustomers?\\b/u',
        // un pourcentage ou une fraction que le serveur n'a pas calculés
        '/(*UCP)\\bpour ?cent\\b/u', '/(*UCP)\\bpercent\\b/u', '/(*UCP)\\bmoitié/u', '/(*UCP)\\bhalf of\\b/u', '/(*UCP)\\btiers\\b/u', '/(*UCP)\\bthirds?\\b/u', '/(*UCP)\\bquarts?\\b/u', '/(*UCP)\\bquarters?\\b/u',
        '/(*UCP)\\bmajorit/u',
    ];
    /** Nombres écrits en lettres, contrôlés comme les chiffres (« un », « one » et « neuf », trop courants, ne le sont pas) */
    public const NUMBER_WORDS = ['deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'dix' => 10, 'onze' => 11, 'douze' => 12,
        'quinze' => 15, 'vingt' => 20, 'trente' => 30, 'quarante' => 40, 'cinquante' => 50, 'soixante' => 60, 'cent' => 100,
        'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fifteen' => 15,
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'hundred' => 100];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly LossSettingsService $settings,
        private readonly LandingAiQuotaService $quota,
        private readonly LandingAiClientInterface $client,
        private readonly MessageBusInterface $bus,
        private readonly TenantConnectionManager $tenants,
        private readonly LoggerInterface $logger,
        private readonly ?EntitlementService $entitlements = null,
    ) {
    }

    /**
     * Le gérant demande un avis (ou l'actualise). En exercice : un avis d'exemple écrit par le serveur, sans le modèle, à 0 unité.
     *
     * @param array<string, mixed> $finding ligne du constat
     *
     * @throws GestionConflictException 409 OPINION_PENDING, 422 OPINION_NOT_ALLOWED, 429 AI_DAILY_LIMIT
     * @throws LandingAiException 403 palier d'IA, 402 plus d'unités
     */
    public function request(array $finding, int $userId, ?string $user, bool $platformOwner, string $locale): void
    {
        $id = (int) $finding['id'];
        $exercise = (bool) $finding['exercise'];
        if ($finding['subject_type'] === 'user_window') {
            throw new GestionConflictException('OPINION_NOT_ALLOWED', 'Aucun avis de l\'IA sur un constat qui porte sur une personne.', 422);
        }
        $this->releaseStale();
        $repo = $this->opinions();
        if (($repo->row($id)['state'] ?? null) === LossOpinion::PENDING) {
            throw new GestionConflictException('OPINION_PENDING', 'Un avis est déjà en préparation pour ce constat.', 409);
        }
        if ($exercise) {
            if (!$repo->begin($id, true, null, ['similar' => 0, 'decided' => 0, 'confirmed' => 0], $userId, null, (string) $finding['last_evidence_at'])) {
                throw new GestionConflictException('OPINION_PENDING', 'Un avis est déjà en préparation pour ce constat.', 409);
            }
            $repo->finish($id, $this->example($locale), 0, true);

            return;
        }
        $this->assertAiIncluded($platformOwner);
        [$dayStart, $dayEnd] = $this->site->clock()->range(null, null);
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->fetchOne('SELECT pg_advisory_lock(?)', [self::LIMIT_LOCK]); // la limite du jour : compter et poser la demande sous le même verrou
        try {
            if ($this->journal()->countRecent(LossJournalEntry::OPINION_REQUESTED, $dayStart, self::DAILY_LIMIT) >= self::DAILY_LIMIT) {
                throw new GestionConflictException('AI_DAILY_LIMIT', sprintf('%d avis de l\'IA par jour au plus : reprenez demain.', self::DAILY_LIMIT), 429,
                    ['limit' => self::DAILY_LIMIT, 'resetAt' => $dayEnd->format(\DateTimeInterface::ATOM)]);
            }
            [$percent, $measures] = $this->measure($finding);
            $usage = $platformOwner ? null : $this->quota->reserve('assistant', 'loss_opinion', self::COST, $user, 'Avis sur le constat n° ' . (int) $finding['number'], self::TIMEOUT_SECONDS + 60);
            if (!$repo->begin($id, false, $percent, $measures, $userId, $usage?->getId(), (string) $finding['last_evidence_at'])) {
                if ($usage !== null) {
                    $this->quota->release($usage, null);
                }
                throw new GestionConflictException('OPINION_PENDING', 'Un avis est déjà en préparation pour ce constat.', 409);
            }
            $this->journal()->append(LossJournalEntry::OPINION_REQUESTED, $userId, $id, ['percent' => $percent, 'credits' => $usage !== null ? self::COST : 0]);
        } finally {
            $conn->fetchOne('SELECT pg_advisory_unlock(?)', [self::LIMIT_LOCK]);
        }
        $this->bus->dispatch(new WriteLossOpinionMessage($id, (string) $this->tenants->getCurrentTenantCode(), $locale));
    }

    /** Le worker rédige l'avis : appel au modèle, vérification, un seul nouvel essai, puis l'avis ou l'échec (unité décomptée ou rendue) */
    public function write(int $findingId, string $locale): void
    {
        $repo = $this->opinions();
        $opinion = $repo->row($findingId);
        $finding = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM loss_finding WHERE id = ?', [$findingId]) ?: null;
        if ($opinion === null || $opinion['state'] !== LossOpinion::PENDING || $finding === null) {
            return;
        }
        $usage = $opinion['usage_id'] !== null ? $this->emProvider->getEntityManager()->find(AiUsage::class, (int) $opinion['usage_id']) : null;
        // relecture du 17/11/2026 : la demande que CE passage rédige. Une demande actualisée entre-temps porte un autre numéro : rien de ce
        // passage ne s'écrit sur elle
        $attempt = (int) $opinion['attempt'];
        // la même garde que la demande, au moment d'appeler le modèle (relecture du 17/11/2026) : jamais une personne, jamais l'exercice
        if ($finding['subject_type'] === 'user_window' || $finding['exercise']) {
            $this->settle($findingId, $usage, null, false, 'OPINION_NOT_ALLOWED', $attempt);

            return;
        }
        $stats = new LandingAiUsageStats(self::MODEL);
        try {
            $measures = json_decode((string) $opinion['measures'], true) ?: [];
            $percent = $opinion['percent'] !== null ? (int) $opinion['percent'] : null;
            $input = $this->input($finding, $percent, $measures, $locale);
            $names = $this->staffNames();
            // les mots que le serveur emploie lui-même : un nom de compte qui en est un n'est pas refusé
            preg_match_all('/\p{L}+/u', mb_strtolower(json_encode($input, JSON_UNESCAPED_UNICODE) . ' ' . $this->system($locale)), $known);
            $vocabulary = array_values(array_unique($known[0]));
            $note = null;
            $content = null;
            for ($try = 0; $try < 2 && $content === null; ++$try) {
                $out = self::clean($this->call($input, $locale, $note, $stats)); // coupé AVANT le contrôle : on contrôle ce qui sera gardé
                $why = self::rejected($out, $input['numbers'], $names, $vocabulary);
                if ($why === null) {
                    $content = $out;
                } else {
                    $note = $why;
                }
            }
            if ($content === null) {
                // refusé par le serveur : l'IA a répondu, ce n'est pas un échec de l'IA (il ne compte pas dans le plafond d'échecs du site,
                // qui suspendrait toute son IA) ; rien n'est décompté
                $this->settle($findingId, $usage, $stats, false, 'OPINION_REJECTED', $attempt, true);

                return;
            }
            $usage = $this->fresh($usage); // le statut en base : le délai a pu la rendre pendant l'appel
            if ($repo->finish($findingId, $content, $usage !== null ? self::COST : 0, false, $attempt)) {
                if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                    $this->quota->complete($usage, $stats, 'Avis sur un constat de la prévention des pertes');
                }
                $this->journal()->append(LossJournalEntry::OPINION_WRITTEN, null, $findingId, ['percent' => $percent, 'credits' => $usage !== null ? self::COST : 0]);
            } elseif ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($usage, $stats); // un avis tardif ou remplacé : rien n'est écrit, l'unité est rendue
            }
        } catch (\Throwable $e) {
            // jamais le message de l'exception au journal du serveur : sa classe suffit
            $this->logger->warning('Prévention des pertes : avis de l\'IA non rédigé (' . $e::class . ').');
            $this->settle($findingId, $usage, $stats, false, 'AI_UNAVAILABLE', $attempt);
        }
    }

    /**
     * L'avis courant d'un constat, tel que la fiche le rend ; null s'il n'y en a jamais eu.
     *
     * @param array<string, mixed> $finding
     *
     * @return array<string, mixed>|null
     */
    public function view(array $finding, string $locale): ?array
    {
        $this->releaseStale();
        $row = $this->opinions()->row((int) $finding['id']);
        if ($row === null) {
            return null;
        }
        $content = json_decode((string) ($row['content'] ?? 'null'), true) ?: [];
        $iso = static fn (mixed $utc): ?string => $utc === null ? null : (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        return [
            'state' => (string) $row['state'],
            'percent' => $row['percent'] !== null ? (int) $row['percent'] : null,
            'calibrated' => false,
            'calibrationNotice' => $this->texts->t('opinion.notCalibrated', $locale),
            'measures' => array_map('intval', json_decode((string) $row['measures'], true) ?: []),
            'factors' => array_values($content['factors'] ?? []),
            'summary' => $content['summary'] ?? null,
            'alternatives' => array_values($content['alternatives'] ?? []),
            'generatedAt' => $iso($row['generated_at']),
            'creditsUsed' => (int) $row['credits_used'],
            // une preuve arrivée après la demande : l'avis ne la connaît pas, la fiche propose « Actualiser »
            'stale' => $row['evidence_at'] !== null && (string) $finding['last_evidence_at'] > (string) $row['evidence_at'],
            'example' => (bool) $row['example'],
            'error' => $row['error'],
            'notice' => $this->texts->t('opinion.notice', $locale),
        ];
    }

    /**
     * Le pourcentage du serveur et ses mesures : part des semblables confirmés parmi les tranchés, à partir de `MIN_DECIDED` tranchés.
     *
     * @param array<string, mixed> $finding
     *
     * @return array{0: ?int, 1: array{similar: int, decided: int, confirmed: int}}
     */
    public function measure(array $finding): array
    {
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . self::SIMILAR_MONTHS . ' months')->format('Y-m-d H:i:s');
        $measures = $this->opinions()->similar((string) $finding['rule_code'], (string) $finding['variant'], (int) $finding['id'], $since);
        $percent = $measures['decided'] >= self::MIN_DECIDED ? (int) round($measures['confirmed'] * 100 / $measures['decided']) : null;

        return [$percent, $measures];
    }

    /**
     * L'entrée du modèle, construite par LISTE BLANCHE : rien d'autre n'y entre. `numbers` : les nombres admis dans le texte rédigé (ceux de
     * l'entrée, et leurs dérivés évidents : montant en unités, total, complément du pourcentage, non confirmés).
     *
     * @param array<string, mixed> $finding
     * @param array<string, int> $measures
     *
     * @return array<string, mixed>
     */
    public function input(array $finding, ?int $percent, array $measures, string $locale): array
    {
        $tz = $this->site->clock()->timezone();
        $facts = json_decode((string) $finding['facts'], true) ?: [];
        $rule = (string) $finding['rule_code'];
        // relecture du 17/11/2026 : ni date ni heure (la chronologie d'une session dessinerait celle d'une personne) ; l'écart en minutes
        // depuis la première preuve suffit à dire ce qui se suit
        $evidence = [];
        $first = null;
        // une règle qui dépend de l'heure (hors des heures d'ouverture) : l'écart avec les heures confirmées, jamais l'heure (chef de projet)
        $hours = $rule === LossRuleCatalog::OUT_OF_HOURS ? LossDepotService::hours($this->emProvider->getEntityManager()->getConnection()) : null;
        foreach ($this->emProvider->getEntityManager()->getRepository(LossEvidence::class)->forFinding((int) $finding['id']) as $e) {
            $moment = new \DateTimeImmutable((string) $e['occurred_at'], new \DateTimeZone('UTC'));
            $at = $moment->getTimestamp();
            $first ??= $at;
            $numbers = self::numbersOf(json_decode((string) $e['snapshot'], true) ?: []);
            $outside = $hours !== null ? LossDepotService::outside($hours, $moment, $tz) : null;
            if ($outside !== null) {
                $numbers += ['minutesOutsideHours' => $outside['minutes'], 'side' => $outside['side']];
            }
            $evidence[] = ['kind' => (string) $e['kind'], 'minutesFromFirst' => intdiv(max(0, $at - $first), 60), 'numbers' => $numbers];
        }
        $input = [
            'sources' => array_keys(array_filter(self::SOURCES)),
            'rule' => ['code' => $rule, 'label' => $this->texts->t('rules.' . $rule . '.label', $locale)],
            'finding' => ['weekday' => strtolower((new \DateTimeImmutable((string) $finding['store_day'], $tz))->format('l')), 'status' => (string) $finding['status'], 'facts' => self::numbersOf($facts),
                'amountAtStake' => $finding['amount_at_stake'] !== null ? (int) $finding['amount_at_stake'] : null, 'currency' => (string) $finding['currency']],
            'evidence' => $evidence,
            'measures' => $measures + ['percent' => $percent, 'minDecided' => self::MIN_DECIDED],
        ];
        // les nombres admis : tous ceux de l'entrée, puis leurs dérivés évidents
        preg_match_all('/\d+(?:\.\d+)?/', (string) json_encode($input), $found); // sans signe
        $numbers = array_map('floatval', $found[0]);
        foreach ($numbers as $n) {
            $numbers[] = $n / 100; // un montant en cents, dit en unités
        }
        $numbers[] = (float) count($evidence);
        $numbers[] = (float) ($measures['decided'] - $measures['confirmed']);
        if ($percent !== null) {
            $numbers[] = (float) (100 - $percent);
        }
        $numbers[] = (float) array_sum(array_filter(self::numbersOf($facts), 'is_numeric'));
        $input['numbers'] = array_values(array_unique(array_map(static fn (float $n): float => round($n, 2), $numbers)));

        return $input;
    }

    /**
     * La sortie du modèle est-elle refusée ? Rend la raison (la note du nouvel essai), ou null si elle est acceptée.
     *
     * @param array<string, mixed> $out
     * @param list<float> $numbers nombres admis
     * @param list<string> $names noms des comptes de la boutique
     * @param list<string> $vocabulary mots (en minuscules) que le serveur emploie lui-même dans l'entrée et les consignes
     */
    public static function rejected(array $out, array $numbers, array $names, array $vocabulary = []): ?string
    {
        $texts = [(string) ($out['summary'] ?? '')];
        foreach (is_array($out['factors'] ?? null) ? $out['factors'] : [] as $f) {
            $texts[] = (string) ($f['label'] ?? '');
        }
        foreach (is_array($out['alternatives'] ?? null) ? $out['alternatives'] : [] as $a) {
            $texts[] = (string) $a;
        }
        $text = implode("\n", $texts);
        if (trim((string) ($out['summary'] ?? '')) === '') {
            return 'Le résumé est vide.';
        }
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $found);
        $lower = mb_strtolower($text);
        // les nombres écrits en lettres sont contrôlés comme les chiffres (« trente pour cent » n'échappe pas au contrôle)
        preg_match_all('/(?<!\p{L})(' . implode('|', array_keys(self::NUMBER_WORDS)) . ')(?!\p{L})/u', $lower, $words);
        foreach ([...$found[0], ...$words[1]] as $raw) {
            $value = isset(self::NUMBER_WORDS[$raw]) ? (float) self::NUMBER_WORDS[$raw] : (float) str_replace(',', '.', $raw);
            if (!array_filter($numbers, static fn (float $n): bool => abs($n - $value) < 0.005)) {
                return 'Le texte cite le nombre ' . $raw . ', que les données ne portent pas : ne citez que les nombres de la liste « numbers ».';
            }
        }
        foreach (self::FORBIDDEN as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return 'Le texte parle d\'une faute, d\'une personne, d\'une suite ou d\'une proportion que les données ne donnent pas : décrivez seulement ce que disent les preuves.';
            }
        }
        foreach ($names as $name) {
            // un nom de compte, quelles que soient ses majuscules ; seuls les mots que le serveur emploie lui-même (`$vocabulary` : libellé de la
            // règle, consignes) restent permis : un compte nommé « Porte » n'empêche pas d'écrire « porte arrière »
            if (!in_array(mb_strtolower($name), $vocabulary, true) && preg_match('/(?<!\p{L})' . preg_quote(mb_strtolower($name), '/') . '(?!\p{L})/u', $lower) === 1) {
                return 'Le texte nomme une personne : ne nommez personne.';
            }
        }

        return null;
    }

    /** Les avis en préparation depuis trop longtemps passent en échec, et leur unité est rendue */
    public function releaseStale(): void
    {
        foreach ($this->opinions()->staleBefore(gmdate('Y-m-d H:i:s', time() - self::TIMEOUT_SECONDS)) as $stale) {
            $usage = $stale['usage_id'] !== null ? $this->emProvider->getEntityManager()->find(AiUsage::class, $stale['usage_id']) : null;
            $this->settle($stale['finding_id'], $usage, null, false, 'OPINION_TIMEOUT', $stale['attempt']); // seulement cette demande-là
        }
    }

    /**
     * Les nombres d'un ensemble de faits ou d'une preuve (clés de `NUMBER_KEYS` seulement), et quelques textes d'une liste fermée
     * (`TEXT_KEYS`) : jamais un identifiant, jamais un texte libre, jamais un tableau imbriqué.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int|float|string>
     */
    public static function numbersOf(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ((is_int($value) || is_float($value)) && in_array($key, self::NUMBER_KEYS, true)) {
                $out[$key] = $value;
            } elseif (is_string($value) && in_array($key, self::TEXT_KEYS, true) && preg_match('/^[a-z_]{1,30}$/', $value) === 1) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $out @return array<string, mixed> */
    private static function clean(array $out): array
    {
        $factors = [];
        foreach (array_slice(is_array($out['factors'] ?? null) ? $out['factors'] : [], 0, 6) as $f) {
            if (is_string($f['label'] ?? null) && in_array($f['effect'] ?? null, ['up', 'down'], true)) {
                $factors[] = ['label' => mb_substr(trim($f['label']), 0, 200), 'effect' => $f['effect']];
            }
        }
        $alternatives = [];
        foreach (array_slice(is_array($out['alternatives'] ?? null) ? $out['alternatives'] : [], 0, 4) as $a) {
            if (is_string($a) && trim($a) !== '') {
                $alternatives[] = mb_substr(trim($a), 0, 240);
            }
        }

        return ['summary' => mb_substr(trim((string) $out['summary']), 0, 800), 'factors' => $factors, 'alternatives' => $alternatives];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function call(array $input, string $locale, ?string $note, LandingAiUsageStats $stats): array
    {
        $payload = $input + ($note !== null ? ['correction' => $note] : []);
        $response = $this->client->createMessage([
            'model' => self::MODEL, 'max_tokens' => 1200, 'system' => $this->system($locale),
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]]]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ], self::MODEL_TIMEOUT);
        $stats->attempts++;
        $stats->add($response->usage ?? null);
        $out = null;
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (($block->type ?? null) === 'text') {
                $decoded = json_decode((string) ($block->text ?? ''), true);
                $out = is_array($decoded) ? $decoded : $out;
            }
        }
        if ($out === null || in_array((string) ($response->stop_reason ?? ''), ['refusal', 'max_tokens'], true)) {
            throw new LandingAiException(503, 'Assistant indisponible', 'L\'avis n\'a pas pu être rédigé.');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'factors', 'alternatives'], 'properties' => [
            'summary' => ['type' => 'string'],
            'factors' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'effect'],
                'properties' => ['label' => ['type' => 'string'], 'effect' => ['type' => 'string', 'enum' => ['up', 'down']]]]],
            'alternatives' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
    }

    private function system(string $locale): string
    {
        $language = $locale === 'en' ? 'anglais' : 'français';

        return "Tu aides le gérant d'une boutique à relire un constat de la prévention des pertes. Écris en $language, simplement.\n"
            . "Tu reçois en JSON la règle, les nombres du constat, ses preuves (genre, minutes écoulées depuis la première preuve, nombres ; pour une règle d'heures d'ouverture, "
            . "`minutesOutsideHours` et `side` : l'écart avant l'ouverture ou après la fermeture) et des mesures calculées par le serveur.\n"
            . "Rends : `summary` (ce que disent les preuves, deux ou trois phrases), `factors` (ce qui pèse pour ou contre une anomalie, `effect` up ou down), "
            . "`alternatives` (les explications sans faute : erreur de caisse, pièce rangée près de la sortie, retour mal saisi, antenne qui lit à travers une cloison, etc.).\n"
            . "Règles strictes :\n"
            . "- ne cite AUCUN nombre absent de la liste `numbers` ; ne calcule pas de pourcentage toi-même ; le pourcentage, s'il existe, est `measures.percent` ;\n"
            . "- ne désigne, ne soupçonne et ne nomme personne ; n'emploie pas les mots employé, salarié, caissier, vendeur, collègue ni client ;\n"
            . "- n'écris aucun nombre en lettres, aucune fraction ni proportion (moitié, tiers, « deux sur trois », pour cent) : seulement les nombres de `numbers`, en chiffres ;\n"
            . "- ne propose jamais de sanction, de licenciement, de plainte, de fouille, de confrontation ni d'appel à la police ;\n"
            . "- ne parle pas de vol prouvé : un constat est une chose à vérifier, la décision appartient au gérant ;\n"
            . "- si `correction` est présent, corrige ton texte précédent selon cette remarque.";
    }

    /** @return array<string, mixed> l'avis d'exemple de l'exercice, écrit par le serveur */
    private function example(string $locale): array
    {
        return ['summary' => $this->texts->t('opinion.example.summary', $locale),
            'factors' => [['label' => $this->texts->t('opinion.example.factorUp', $locale), 'effect' => 'up'], ['label' => $this->texts->t('opinion.example.factorDown', $locale), 'effect' => 'down']],
            'alternatives' => [$this->texts->t('opinion.example.alternative', $locale)]];
    }

    /** Le palier d'IA du site (droit ET allumé par le commerçant) ; le compte de la plateforme passe */
    private function assertAiIncluded(bool $platformOwner): void
    {
        if ($platformOwner || $this->entitlements === null) {
            return;
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        try {
            $allowed = $this->entitlements->isActive($code, 'ai.full') || $this->entitlements->isActive($code, 'ai.help');
        } catch (\Throwable) {
            $allowed = false;
        }
        if (!$allowed) {
            throw new LandingAiException(403, 'IA non incluse', 'L\'avis de l\'IA demande l\'option « Aide IA » pour ce site.');
        }
    }

    /** Noms des comptes de la boutique (équipe : caisse et administration), pour refuser un texte qui en citerait un @return list<string> */
    private function staffNames(): array
    {
        $names = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT firstname, lastname FROM "user" WHERE deleted_at IS NULL AND (roles::text LIKE \'%ROLE_USER_POS%\' OR roles::text LIKE \'%ROLE_POS_MANAGER%\' OR roles::text LIKE \'%ROLE_ADMIN%\') LIMIT 2000') as $u) {
            foreach ([$u['firstname'] ?? '', $u['lastname'] ?? ''] as $part) {
                $part = trim((string) $part);
                if (mb_strlen($part) >= 3) {
                    $names[mb_strtolower($part)] = $part;
                }
            }
        }

        return array_values($names);
    }

    /**
     * L'échec d'un avis, et son unité. `$attempt` : seulement pour cette demande-là ; `$answered` : l'IA a répondu (texte refusé par le
     * serveur) : rien n'est décompté, et ce n'est pas un échec de l'IA. La réservation n'est réglée que si elle l'est encore EN BASE.
     */
    private function settle(int $findingId, ?AiUsage $usage, ?LandingAiUsageStats $stats, bool $charge, string $error, ?int $attempt = null, bool $answered = false): void
    {
        $this->opinions()->fail($findingId, $error, $attempt);
        $usage = $this->fresh($usage);
        if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
            if ($answered) {
                $this->quota->completeWithoutCharge($usage, $stats ?? new LandingAiUsageStats(self::MODEL));
            } else {
                $charge ? $this->quota->complete($usage, $stats ?? new LandingAiUsageStats(self::MODEL)) : $this->quota->release($usage, $stats);
            }
        }
    }

    /** La réservation relue en base (un autre passage a pu la régler) */
    private function fresh(?AiUsage $usage): ?AiUsage
    {
        if ($usage === null) {
            return null;
        }
        $em = $this->emProvider->getEntityManager();
        if ($em->contains($usage)) {
            $em->refresh($usage);

            return $usage;
        }

        return $em->find(AiUsage::class, $usage->getId());
    }

    private function opinions(): LossOpinionRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossOpinion::class);
    }

    private function journal(): \App\LossPrevention\Repository\LossJournalRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class);
    }
}
