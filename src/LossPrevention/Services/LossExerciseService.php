<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mode exercice de la prévention des pertes (lot EX-1, décisions du propriétaire du 14/11/2026, `docs/prevention-pertes.md`) : le gérant lance
 * un scénario, le serveur écrit un constat FICTIF et ses preuves, exactement comme un vrai (même fiche, mêmes lignes de preuve, même décision
 * à prendre), plus l'aperçu de l'alerte telle qu'elle apparaîtrait. Il sert à se préparer : il est jouable avant la notice aux salariés, et
 * sans le droit du module (démonstration avant achat), dans la limite de `LIMIT` constats d'exercice par site.
 *
 * Ce que ce service ne fait JAMAIS :
 * - lire ou écrire la caisse : les règles réelles ne sont pas jouées ; le constat est écrit à partir d'un scénario, chiffré d'après les
 *   seuils du site (ce que SES réglages relèveraient) ; ses preuves ne renvoient à aucune opération (`ref.type` = `exercise`) ;
 * - nommer une personne réelle : « qui était en poste » et les lignes de preuve portent deux comptes fictifs (identifiants négatifs,
 *   « Caissier d'exercice » et « Responsable d'exercice ») ; motifs et noms d'articles sont des textes du scénario, gardés par leur clé ;
 * - compter dans un chiffre réel : la marque `exercise` l'écarte de la synthèse, de la liste par défaut, de la vignette, du courriel du
 *   jour, de l'aperçu d'un seuil et des règles par personne ;
 * - lancer l'analyse réelle, ni créer la ligne de réglages d'un site qui n'a pas le module.
 *
 * L'effacement est manuel, d'un geste, par la seule porte que la base laisse aux constats d'exercice (`LossFindingRepository::deleteExercises`).
 * Le journal du module garde pour toujours « exercice lancé » (numéro, scénario) et « exercices effacés » (nombre), sans constat rattaché.
 */
class LossExerciseService
{
    /** Plafond de constats d'exercice par site (décision du propriétaire) */
    public const LIMIT = 30;
    public const SCREEN = 'pertes.exercices';
    /** Verrou consultatif propre aux exercices (celui de l'analyse est 7461077) : deux lancements simultanés ne prennent pas le même numéro */
    private const LOCK_KEY = 7461078;

    /** Scénario => règle, variante par personne ; l'ordre est celui de l'écran (vague 1, vague 2, puis les variantes par personne) */
    public const SCENARIOS = [
        'cash_shortage' => ['rule' => LossRuleCatalog::CASH_DISCREPANCY, 'person' => false],
        'drawer_no_sale' => ['rule' => LossRuleCatalog::DRAWER_NO_SALE, 'person' => false],
        'void_quick' => ['rule' => LossRuleCatalog::VOID_AFTER_SALE, 'person' => false],
        'void_series' => ['rule' => LossRuleCatalog::VOID_AFTER_SALE, 'person' => false],
        'refund_no_receipt' => ['rule' => LossRuleCatalog::REFUND_PATTERN, 'person' => false],
        'refund_cash_for_card' => ['rule' => LossRuleCatalog::REFUND_PATTERN, 'person' => false],
        'discount_vague' => ['rule' => LossRuleCatalog::DISCOUNT_PATTERN, 'person' => false],
        'price_override' => ['rule' => LossRuleCatalog::PRICE_OVERRIDE, 'person' => false],
        'approval_refused' => ['rule' => LossRuleCatalog::APPROVAL_PATTERN, 'person' => false],
        'cash_shortage_series' => ['rule' => LossRuleCatalog::CASH_DISCREPANCY, 'person' => true],
        'refund_series' => ['rule' => LossRuleCatalog::REFUND_PATTERN, 'person' => true],
        'discount_series' => ['rule' => LossRuleCatalog::DISCOUNT_PATTERN, 'person' => true],
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossFindingReader $reader,
        private readonly LossRuleService $rules,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
        private readonly AdminSpaceUrl $links,
    ) {
    }

    /**
     * GET /exercices : les scénarios, les constats d'exercice du site (lignes de liste, sans aucun nom), le plafond.
     *
     * @return array<string, mixed>
     */
    public function overview(string $locale): array
    {
        $rows = $this->findings()->exercises();
        $personAllowed = $this->personAllowed();

        return [
            'scenarios' => array_map(fn (string $code) => $this->scenario($code, $locale, $personAllowed), array_keys(self::SCENARIOS)),
            'findings' => $this->reader->linesOf($rows, $locale),
            'count' => count($rows), 'limit' => self::LIMIT, 'remaining' => max(0, self::LIMIT - count($rows)),
            'notice' => $this->texts->t('exercise.notice', $locale),
            'screen' => self::SCREEN,
        ];
    }

    /**
     * POST /exercices `{ scenario, sendTestEmail? }` : écrit le constat d'exercice et ses preuves ; rend sa fiche, l'aperçu de l'alerte, et ce
     * qu'il est advenu du courriel d'exercice quand il a été demandé.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function start(array $body, int $userId, string $locale): array
    {
        $errors = [];
        $code = $body['scenario'] ?? null;
        if (!is_string($code) || $code === '') {
            $errors['scenario'] = 'REQUIRED';
        } elseif (!isset(self::SCENARIOS[$code])) {
            $errors['scenario'] = 'INVALID_VALUE';
        }
        if (array_key_exists('sendTestEmail', $body) && !is_bool($body['sendTestEmail'])) {
            $errors['sendTestEmail'] = 'MUST_BE_BOOLEAN';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        if (self::SCENARIOS[$code]['person'] && !$this->personAllowed()) {
            throw new GestionConflictException('EXERCISE_SCENARIO_NOT_AVAILABLE', 'Ce scénario n\'est pas proposé dans votre région.', 409, ['scenario' => $code]);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $journal = $em->getRepository(LossJournalEntry::class);
        $conn->beginTransaction();
        try {
            // Numéro, constat et ligne « exercice lancé » dans UNE transaction, sous le verrou des exercices
            $conn->executeStatement('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY . ')');
            if ($this->findings()->exerciseCount() >= self::LIMIT) {
                throw new GestionConflictException('EXERCISE_LIMIT_REACHED', sprintf('Vous avez déjà %d constats d\'exercice : effacez-les avant d\'en lancer d\'autres.', self::LIMIT), 409, ['limit' => self::LIMIT]);
            }
            $number = $journal->lastExerciseNumber() + 1; // lu au journal, qui ne se supprime pas : le numéro ne redescend jamais
            [$finding, $evidence] = $this->build($code, $number);
            $id = $this->findings()->create($finding + ['exercise' => true, 'number' => $number, 'dedup_key' => 'EXERCISE:' . $code . ':' . $number, 'backfill' => false]);
            $evidenceRepo = $em->getRepository(LossEvidence::class);
            foreach ($evidence as $i => $e) {
                // une preuve d'exercice ne renvoie à AUCUNE opération de la caisse : son renvoi est son rang dans le scénario
                $evidenceRepo->add($id, $e['kind'], $e['at'], 'exercise', $i + 1, $e['snapshot']);
            }
            $journal->append(LossJournalEntry::EXERCISE_STARTED, $userId, null, ['number' => $number, 'scenario' => $code]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $detail = $this->reader->detail($id, $locale, null, true);
        $alert = $this->alertPreview($detail, $locale);

        return [
            'finding' => $detail,
            'alertPreview' => $alert,
            'testEmail' => ($body['sendTestEmail'] ?? false) === true ? $this->sendTestEmail($detail, $alert, $userId, $locale) : ['requested' => false, 'sent' => false, 'reason' => null],
            'remaining' => max(0, self::LIMIT - $this->findings()->exerciseCount()),
            'screen' => self::SCREEN,
        ];
    }

    /** DELETE /exercices : efface tous les constats d'exercice du site (effacement manuel seulement) @return array{deleted: int} */
    public function deleteAll(int $userId): array
    {
        $deleted = $this->findings()->deleteExercises();
        if ($deleted > 0) {
            $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class)->append(LossJournalEntry::EXERCISES_DELETED, $userId, null, ['count' => $deleted]);
        }

        return ['deleted' => $deleted];
    }

    /** @return array<string, mixed> */
    private function scenario(string $code, string $locale, bool $personAllowed): array
    {
        $s = self::SCENARIOS[$code];

        return [
            'code' => $code,
            'label' => $this->texts->t("exercise.scenarios.$code.label", $locale),
            'description' => $this->texts->t("exercise.scenarios.$code.description", $locale),
            'learn' => $this->texts->t("exercise.scenarios.$code.learn", $locale),
            'rule' => ['code' => $s['rule'], 'label' => $this->texts->t('rules.' . $s['rule'] . ($s['person'] ? '.person.label' : '.label'), $locale)],
            'personPattern' => $s['person'],
            'available' => !$s['person'] || $personAllowed,
        ];
    }

    /** Les variantes par personne ne sont proposées que là où le pays les permet (jamais là où la notice aux salariés est exigée) */
    private function personAllowed(): bool
    {
        $place = $this->site->place();

        return LossCountryPolicy::personPatternsAllowed($place['country'], $place['region']);
    }

    /**
     * L'alerte telle qu'elle apparaîtrait pour ce constat s'il était réel : la vignette de l'accueil et le courriel du jour. Montrée à
     * l'écran ; rien n'est envoyé ici.
     *
     * @param array<string, mixed> $detail
     * @return array{tile: array{title: string, text: string}, email: array{subject: string, message: string}}
     */
    private function alertPreview(array $detail, string $locale): array
    {
        $params = ['%count%' => 1, '%amount%' => LossTexts::money((int) ($detail['amountAtStake'] ?? 0), $locale, (string) $detail['currency'])];

        return [
            'tile' => ['title' => $this->translator->trans('tiles.loss_findings.title', $params, 'gestion', $locale), 'text' => $this->translator->trans('tiles.loss_findings.text', $params, 'gestion', $locale)],
            'email' => ['subject' => $this->translator->trans('loss_digest.subject', $params, 'emails', $locale), 'message' => $this->translator->trans('loss_digest.message', $params, 'emails', $locale)],
        ];
    }

    /**
     * Courriel d'exercice (décision du propriétaire) : un VRAI courriel « [Exercice] », au SEUL administrateur qui lance, par le gabarit du
     * courriel du jour avec le bandeau « Exercice ». Il n'entre pas dans le « un par jour » du courriel du jour (ni jour ni borne ne bougent).
     *
     * @param array<string, mixed> $detail
     * @param array{tile: array<string, string>, email: array{subject: string, message: string}} $alert
     * @return array{requested: bool, sent: bool, reason: ?string} `reason` : NO_RECIPIENT_ADDRESS, NOT_DELIVERED (pas de configuration d'envoi sur le site)
     */
    private function sendTestEmail(array $detail, array $alert, int $userId, string $locale): array
    {
        $em = $this->emProvider->getEntityManager();
        $to = trim((string) $em->getConnection()->fetchOne('SELECT email FROM "user" WHERE id = ? AND deleted_at IS NULL', [$userId]));
        if ($to === '' || !str_contains($to, '@')) {
            return ['requested' => true, 'sent' => false, 'reason' => 'NO_RECIPIENT_ADDRESS'];
        }
        $t = fn (string $key, array $params = []) => $this->translator->trans('loss_digest.' . $key, $params, 'emails', $locale);
        $siteName = (string) ($em->getConnection()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1') ?: '');
        $amount = LossTexts::money((int) ($detail['amountAtStake'] ?? 0), $locale, (string) $detail['currency']);
        $context = [
            'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $alert['email']['message'],
            'rows' => [$t('row_count') => '1', $t('row_amount') => $amount, $t('row_day') => LossTexts::day(new \DateTimeImmutable((string) $detail['storeDay']), $locale)],
            'nextStep' => $t('next'), 'notice' => $t('notice'), 'siteLine' => $t('signature', ['%site%' => $siteName]),
            'exerciseBanner' => $t('exercise_banner'),
            // le lien du courriel d'exercice mène à l'écran des exercices (lot LIEN) ; null : pas de lien
            'linkUrl' => $this->links->to(self::SCREEN), 'linkLabel' => $t('exercise_link'),
            'locale' => $locale, 'intlLocale' => $locale === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $sent = false;
        try {
            $sent = $this->mailer->sendTemplatedEmail($to, $t('exercise_subject', ['%subject%' => $alert['email']['subject']]), LossDigestService::TEMPLATE, $context, $locale, $this->host());
        } catch (\Throwable $e) { // jamais le message de l'exception au journal du serveur : il peut porter l'adresse du destinataire
            $this->logger->error('Prévention des pertes : courriel d\'exercice, envoi en échec (' . $e::class . ').');
        }
        if ($sent) {
            $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::EXERCISE_EMAIL_SENT, $userId, null, ['number' => abs((int) $detail['number'])]);
        }

        return ['requested' => true, 'sent' => $sent, 'reason' => $sent ? null : 'NOT_DELIVERED'];
    }

    /**
     * Le constat et les preuves d'un scénario, chiffrés d'après les seuils du site. Aucune lecture de la caisse ; aucune personne réelle.
     *
     * @return array{0: array<string, mixed>, 1: list<array{kind: string, at: \DateTimeImmutable, snapshot: array<string, mixed>}>}
     */
    private function build(string $code, int $number): array
    {
        $s = self::SCENARIOS[$code];
        $config = $this->rules->effective($s['rule']);
        $params = $config['params'];
        $personParams = $config['personParams'] !== [] ? $config['personParams'] : LossRuleCatalog::defaults($s['rule'], true);
        $clock = $this->site->clock();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ago = fn (int $minutes): \DateTimeImmutable => $now->modify('-' . $minutes . ' minutes');
        $iso = fn (\DateTimeImmutable $d): string => LossAuditEntry::iso($d);
        $sql = fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
        $cashier = LossFindingReader::EXERCISE_CASHIER;
        $manager = LossFindingReader::EXERCISE_MANAGER;
        $ref = fn (int $i): string => 'EXERCICE-' . $number . '-' . $i; // référence fictive d'une vente ou d'un retour
        $from = $ago(60);
        $to = $ago(1);
        $amount = null;
        $facts = [];
        $evidence = [];
        $onDuty = [['id' => $cashier, 'role' => 'operator']];
        $subject = 'session';
        $subjectId = 0;
        $zReport = function (int $n, int $discrepancy, \DateTimeImmutable $closedAt) use ($iso, $cashier): array {
            $expected = 30000;

            return ['kind' => LossEvidence::Z_REPORT, 'at' => $closedAt, 'snapshot' => [
                'reportNumber' => $n, 'cashRegisterId' => null, 'sessionId' => null, 'closedAt' => $iso($closedAt), 'closedById' => $cashier,
                'expectedCash' => $expected, 'countedCash' => $expected + $discrepancy, 'discrepancy' => $discrepancy, 'keptFund' => 10000, 'bankDeposit' => $expected + $discrepancy - 10000,
                'reasonText' => 'discrepancy_reason']];
        };
        $return = function (int $i, int $value, bool $withReceipt, \DateTimeImmutable $at) use ($iso, $cashier, $manager, $ref): array {
            return ['kind' => LossEvidence::RETURN, 'at' => $at, 'snapshot' => [
                'returnId' => null, 'reference' => $ref($i), 'originalOrderId' => $withReceipt ? 0 : null, 'returnedAt' => $iso($at), 'amount' => $value, 'cashRefund' => $value, 'cardRefund' => 0,
                'refundMethod' => 'cash', 'reasonCode' => 'autre', 'restock' => true, 'userId' => $cashier, 'approvedById' => $manager, 'reasonText' => $withReceipt ? 'return_reason' : 'return_no_receipt_reason']];
        };
        switch ($code) {
            case 'cash_shortage':
                $threshold = max($this->site->discrepancyThreshold(), (int) $params['minAmount']);
                $discrepancy = -($threshold + 500); // un manque juste au-dessus du seuil du site
                $proof = $zReport($number, $discrepancy, $to);
                $evidence[] = $proof;
                $facts = ['discrepancy' => $discrepancy, 'expectedCash' => $proof['snapshot']['expectedCash'], 'countedCash' => $proof['snapshot']['countedCash'], 'reportNumber' => $number,
                    'threshold' => $threshold, 'hasReason' => true];
                $amount = abs($discrepancy);
                $onDuty = [['id' => $cashier, 'role' => 'closer'], ['id' => $cashier, 'role' => 'seller']];
                $subject = 'z_report';
                $from = $ago(480);
                break;
            case 'drawer_no_sale':
                $max = (int) $params['maxPerSession'];
                foreach (range(1, $max + 1) as $i) {
                    $evidence[] = ['kind' => LossEvidence::DRAWER, 'at' => $ago(($max + 2 - $i) * 12), 'snapshot' => ['userId' => $cashier, 'approvedById' => null, 'reasonText' => $i % 2 === 1 ? 'drawer_reason_change' : 'drawer_reason_check']];
                }
                $facts = ['count' => $max + 1, 'max' => $max];
                $from = $evidence[0]['at'];
                break;
            case 'void_quick':
                $minutes = (int) $params['minutes'];
                $delay = min(3, $minutes);
                $total = 4500;
                $soldAt = $ago($delay + 1);
                $evidence[] = ['kind' => LossEvidence::ORDER, 'at' => $soldAt, 'snapshot' => ['reference' => $ref(1), 'soldAt' => $iso($soldAt), 'total' => $total, 'cashPart' => $total, 'sellerId' => $cashier]];
                $evidence[] = ['kind' => LossEvidence::CREDIT_NOTE, 'at' => $to, 'snapshot' => ['orderId' => null, 'reference' => $ref(1), 'soldAt' => $iso($soldAt), 'canceledAt' => $iso($to), 'total' => $total,
                    'creditNoteNumber' => $number, 'refundAmount' => $total, 'cashRefund' => $total, 'canceledById' => $cashier, 'approvedById' => $manager]];
                $facts = ['case' => 'quick', 'delayMinutes' => $delay, 'minutes' => $minutes, 'total' => $total, 'cashPart' => $total];
                $amount = $total;
                $onDuty = [['id' => $cashier, 'role' => 'seller'], ['id' => $cashier, 'role' => 'operator'], ['id' => $manager, 'role' => 'approver']];
                $subject = 'order';
                $from = $soldAt;
                break;
            case 'void_series':
                $perSession = (int) $params['perSession'];
                $total = 0;
                foreach (range(1, $perSession) as $i) {
                    $value = 2000 + 500 * $i;
                    $canceledAt = $ago(($perSession + 1 - $i) * 25);
                    $soldAt = $canceledAt->modify('-40 minutes');
                    $evidence[] = ['kind' => LossEvidence::CREDIT_NOTE, 'at' => $canceledAt, 'snapshot' => ['orderId' => null, 'reference' => $ref($i), 'soldAt' => $iso($soldAt), 'canceledAt' => $iso($canceledAt), 'total' => $value,
                        'creditNoteNumber' => $i, 'refundAmount' => $value, 'cashRefund' => $value, 'canceledById' => $cashier, 'approvedById' => $manager]];
                    $total += $value;
                }
                $facts = ['case' => 'series', 'count' => $perSession, 'perSession' => $perSession, 'total' => $total];
                $amount = $total;
                $onDuty = [['id' => $cashier, 'role' => 'seller'], ['id' => $cashier, 'role' => 'operator'], ['id' => $manager, 'role' => 'approver']];
                $from = $evidence[0]['at'];
                break;
            case 'refund_no_receipt':
            case 'refund_cash_for_card':
                $value = 3900;
                $withReceipt = $code === 'refund_cash_for_card';
                $evidence[] = $return(1, $value, $withReceipt, $to);
                $facts = ['case' => $withReceipt ? 'cash_for_card' : 'no_receipt', 'amount' => $value, 'cashRefund' => $value];
                $amount = $value;
                $onDuty = array_merge([['id' => $cashier, 'role' => 'operator']], $withReceipt ? [['id' => $cashier, 'role' => 'seller']] : [], [['id' => $manager, 'role' => 'approver']]);
                $subject = 'return';
                $from = $to;
                break;
            case 'discount_vague':
                $perSession = (int) $params['perSession'];
                $total = 0;
                foreach (range(1, $perSession) as $i) {
                    $evidence[] = ['kind' => LossEvidence::DISCOUNT, 'at' => $ago(($perSession + 1 - $i) * 20), 'snapshot' => ['orderId' => null, 'reference' => $ref($i), 'gross' => 5000, 'discount' => 500,
                        'reasonCodes' => ['autre'], 'writtenReasons' => 0, 'userId' => $cashier, 'approvedById' => null]];
                    $total += 500;
                }
                $facts = ['count' => $perSession, 'perSession' => $perSession, 'total' => $total];
                $amount = $total;
                $from = $evidence[0]['at'];
                break;
            case 'price_override':
                $perSession = (int) $params['perSession'];
                $total = 0;
                foreach (range(1, $perSession) as $i) {
                    $difference = 400 + 100 * $i;
                    $evidence[] = ['kind' => LossEvidence::PRICE_CHANGE, 'at' => $ago(($perSession + 1 - $i) * 20), 'snapshot' => ['orderId' => null, 'orderItemId' => null, 'productVariantId' => null, 'reference' => $ref($i),
                        'quantity' => 1, 'catalogPrice' => 2900, 'manualPrice' => 2900 - $difference, 'difference' => $difference, 'reasonCode' => null, 'userId' => $cashier, 'approvedById' => null,
                        'articleText' => 'article', 'reasonText' => 'price_reason']];
                    $total += $difference;
                }
                $facts = ['count' => $perSession, 'perSession' => $perSession, 'total' => $total];
                $amount = $total;
                $from = $evidence[0]['at'];
                break;
            case 'approval_refused':
                $perDay = (int) $params['perDay'];
                foreach (range(1, $perDay) as $i) {
                    $evidence[] = ['kind' => LossEvidence::APPROVAL, 'at' => $ago(($perDay + 1 - $i) * 4), 'snapshot' => ['action' => 'refund', 'refusal' => 'PIN_INVALID', 'userId' => $cashier, 'managerId' => $manager, 'terminalId' => null]];
                }
                $facts = ['count' => $perDay, 'perDay' => $perDay];
                $subject = 'terminal';
                $from = $evidence[0]['at'];
                break;
            case 'cash_shortage_series':
                $count = (int) $personParams['count'];
                $threshold = max($this->site->discrepancyThreshold(), (int) $params['minAmount']);
                $total = 0;
                foreach (range(1, $count) as $i) {
                    $discrepancy = -($threshold + 300 * $i);
                    $evidence[] = $zReport($i, $discrepancy, $ago(($count - $i) * 1440 + 1));
                    $total += abs($discrepancy);
                }
                $facts = ['count' => $count, 'total' => $total, 'days' => (int) $personParams['days']];
                $amount = $total;
                $onDuty = [['id' => $cashier, 'role' => 'closer']];
                $subject = 'user_window';
                $subjectId = $cashier;
                $from = $evidence[0]['at'];
                break;
            case 'refund_series':
                $count = (int) $personParams['count'];
                $total = 0;
                foreach (range(1, $count) as $i) {
                    $value = 2500 + 400 * $i;
                    $evidence[] = $return($i, $value, true, $ago(($count + 1 - $i) * 30));
                    $total += $value;
                }
                $facts = ['count' => $count, 'total' => $total, 'days' => 1];
                $amount = $total;
                $subject = 'user_window';
                $subjectId = $cashier;
                $from = $evidence[0]['at'];
                break;
            case 'discount_series':
                $count = (int) $personParams['count'];
                $total = 0;
                foreach (range(1, $count) as $i) {
                    $evidence[] = ['kind' => LossEvidence::DISCOUNT, 'at' => $ago(($count - $i) * 1440 + 30), 'snapshot' => ['orderId' => null, 'reference' => $ref($i), 'gross' => 6000, 'discount' => 600, 'userId' => $cashier]];
                    $total += 600;
                }
                $facts = ['count' => $count, 'total' => $total, 'days' => (int) $personParams['days'], 'share' => (int) $personParams['share']];
                $amount = $total;
                $onDuty = [['id' => $cashier, 'role' => 'seller']];
                $subject = 'user_window';
                $subjectId = $cashier;
                $from = $evidence[0]['at'];
                break;
        }

        return [[
            'rule_code' => $s['rule'], 'variant' => $s['person'] ? LossFinding::VARIANT_PERSON : LossFinding::VARIANT_RULE,
            'store_day' => $clock->day($to), 'occurred_from' => $sql($from), 'occurred_to' => $sql($to),
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => $amount, 'currency' => $this->site->currency(),
            'subject_type' => $subject, 'subject_id' => $subjectId, 'facts' => $facts, 'on_duty' => LossFindingWriter::mergeOnDuty([], $onDuty),
        ], $evidence];
    }

    /** Hôte public du site (adresse du logo de l'envoi commun), comme le courriel du jour */
    private function host(): string
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $code !== '' ? (string) ($this->hosts->describe($code)['host'] ?? $code) : 'localhost';
    }

    private function findings(): \App\LossPrevention\Repository\LossFindingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossFinding::class);
    }
}
