<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossPrintJob;
use App\LossPrevention\Entity\LossPrintJobLine;
use App\LossPrevention\Entity\LossTag;
use App\LossPrevention\Repository\LossBoxRepository;
use App\LossPrevention\Repository\LossPieceRepository;
use App\LossPrevention\Repository\LossPrintRepository;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\CaisseService\SiteLocaleResolver;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Travaux d'impression de l'imprimante à puce (lot L3-c, 16/11/2026, `docs/prevention-pertes.md` § 16.20).
 *
 * - Le gérant choisit des pièces et une imprimante à puce d'un boîtier : un travail, une ligne par pièce, avec l'identifiant de
 *   96 bits à écrire (`LossTagEpc`). Le boîtier vient chercher ses travaux ; il imprime, écrit, RELIT, puis renvoie un fait par
 *   étiquette. Une écriture relue conforme pose la puce sur la pièce ; sans cela, la pièce reste sans puce.
 * - Le mot de passe d'accès des puces : un par site, tiré au premier travail, rangé chiffré (`LossTagVault`). Il ne sort que
 *   par `jobsOf`, vers le boîtier. Aucune autre méthode ne le rend, aucun journal ni aucun fait ne le porte.
 * - Sans clé de chiffrement posée, aucun vrai travail ne se crée (409 `PRINT_KEY_MISSING`).
 * - Mode exercice : une imprimante simulée joue ses scénarios côté serveur, sur des pièces d'exercice et des puces simulées, SANS
 *   mot de passe (la base refuse qu'un travail d'exercice en porte un).
 * - Le module n'écrit que dans ses tables.
 */
class LossPrintService
{
    /** Lignes par travail au plus (le rouleau d'étiquettes des pièces a la même limite) */
    public const MAX_LINES = BarcodeLabelPrinter::ROLL_MAX_LABELS;
    /** Travaux ouverts au plus sur un site */
    public const MAX_OPEN_JOBS = 20;
    public const PAGE_LIMIT = 50;
    /** Code d'écran du web (`docs/liens-espace-administration.md`) : « Impressions de puces » */
    public const SCREEN = 'pertes.impressions';
    /** Lignes servies au boîtier par appel */
    public const BOX_BATCH = 50;
    /** Scénarios de l'imprimante simulée, et nombre d'étiquettes qu'ils jouent */
    public const SCENARIOS = ['print_ok', 'print_refused', 'print_no_labels'];
    public const EXERCISE_LINES = 3;
    private const OPEN_PIECE_INDEX = 'uniq_loss_print_line_open_piece';
    /** Motifs d'échec posés par la plateforme elle-même (le boîtier, lui, avait écrit la puce) */
    private const OWN_REASONS = [LossPrintJobLine::PIECE_RETIRED, LossPrintJobLine::TAG_LIMIT, LossPrintJobLine::IDENTIFIER_TAKEN];
    private const KEY_CODES = [LossTagVault::MISSING => 'PRINT_KEY_MISSING', LossTagVault::INVALID => 'PRINT_KEY_INVALID'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly LossTagVault $vault,
        private readonly LossPieceLabelService $labels,
        private readonly LossTexts $texts,
        private readonly LossSite $site,
        private readonly ?SiteLocaleResolver $siteLocale = null,
        #[Autowire('%env(default:app.tenant_template_db:TENANT_TEMPLATE_DB)%')] private readonly ?string $templateDb = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    // ----- L'administrateur -----

    /**
     * @param array<string, mixed> $query page, limit
     *
     * @return array<string, mixed> { items, total, page, limit, printers, ready, limits, exercise }
     */
    public function list(array $query, string $locale, bool $exercise): array
    {
        $errors = [];
        $page = self::positive($query['page'] ?? 1, 'page', $errors);
        $limit = self::positive($query['limit'] ?? self::PAGE_LIMIT, 'limit', $errors);
        if ($limit !== null && $limit > self::PAGE_LIMIT) {
            $errors['limit'] = 'OUT_OF_RANGE';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $repository = $this->repository();
        [$rows, $total] = $repository->jobs($exercise, $page, $limit);
        $printers = array_map(fn (array $row): array => $this->printerView($row, $locale), $repository->printers($exercise));

        return ['items' => array_map(fn (array $row): array => $this->jobView($row, $locale), $rows), 'total' => $total, 'page' => $page, 'limit' => $limit,
            'printers' => $printers, 'ready' => $this->readiness($locale, $exercise, $printers !== []),
            'limits' => ['lines' => self::MAX_LINES, 'openJobs' => self::MAX_OPEN_JOBS], 'exercise' => $exercise, 'screen' => self::SCREEN];
    }

    /** @return array<string, mixed> le travail et ses lignes */
    public function sheet(int $id, string $locale, bool $exercise): array
    {
        $repository = $this->repository();
        $row = $repository->jobRow($id, $exercise);
        if ($row === null) {
            throw new GestionConflictException('PRINT_JOB_NOT_FOUND', $this->texts->t('prints.error.PRINT_JOB_NOT_FOUND', $locale), 404);
        }
        $lines = $repository->linesOf($id);
        $variants = $this->pieces()->variants(array_map(static fn (array $l): int => (int) $l['variant_id'], $lines));

        return $this->jobView($row, $locale) + ['lineItems' => array_map(function (array $line) use ($variants, $locale): array {
            $catalogue = LossPieceService::catalogue($variants[(int) $line['variant_id']] ?? null);

            return ['id' => (int) $line['id'], 'position' => (int) $line['position'],
                'piece' => ['id' => (int) $line['piece_id'], 'number' => (int) $line['number'], 'name' => $catalogue['name'], 'variantLabel' => $catalogue['variantLabel']],
                'identifier' => (string) $line['identifier'], 'status' => (string) $line['status'], 'statusLabel' => $this->texts->t('prints.line.' . $line['status'], $locale),
                'reason' => $line['reason'], 'reasonLabel' => $line['reason'] !== null ? $this->texts->t('prints.reason.' . $line['reason'], $locale) : null,
                'tagId' => $line['tag_id'] !== null ? (int) $line['tag_id'] : null, 'doneAt' => self::iso($line['done_at'])];
        }, $lines), 'screen' => self::SCREEN];
    }

    /**
     * Crée un vrai travail d'impression `{ deviceId, pieceIds, withPrice?, confirmTagged? }`.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> le travail créé (`sheet`)
     */
    public function create(array $body, string $locale, int $userId): array
    {
        $errors = [];
        $deviceId = self::positive($body['deviceId'] ?? null, 'deviceId', $errors, true);
        foreach (['withPrice', 'confirmTagged'] as $flag) {
            if (array_key_exists($flag, $body) && !is_bool($body[$flag])) {
                $errors[$flag] = 'MUST_BE_BOOLEAN';
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $withPrice = ($body['withPrice'] ?? false) === true;
        // les pièces : distinctes, connues, en service, avec un code-barres à imprimer (les règles des étiquettes de pièces)
        $this->labels->selection(['pieceIds' => $body['pieceIds'] ?? null, 'showPrice' => $withPrice, 'format' => BarcodeLabelPrinter::ROLL], $locale, false);
        /** @var list<int> $ids */
        $ids = array_values($body['pieceIds']);
        // Le site de référence, que tout site neuf copie, ne porte JAMAIS de mot de passe de puces : le chiffre est lié au site, un
        // site né de cette copie ne pourrait plus imprimer (et un mot de passe rangé ne se supprime pas)
        if ($this->templateDb !== null && $this->templateDb !== '' && $this->emProvider->getEntityManager()->getConnection()->getDatabase() === $this->templateDb) {
            throw new GestionConflictException('PRINT_ON_REFERENCE_SITE', $this->texts->t('prints.error.PRINT_ON_REFERENCE_SITE', $locale), 409);
        }
        $problem = $this->keyProblem();
        if ($problem !== null) {
            // le marchand ne lit jamais le nom de la variable : il est pour l'exploitant, dans le journal du serveur et dans la fiche
            $this->logger?->warning(sprintf('Prévention des pertes : travail d\'impression refusé (%s). Variable d\'environnement %s : voir docs/prevention-pertes.md § 16.20.', $problem, LossTagVault::VARIABLE));
            throw new GestionConflictException($problem, $this->texts->t('prints.error.' . $problem, $locale), 409);
        }
        $site = (string) $this->tenant->getTenantCode();
        $repository = $this->repository();
        $pieces = $this->pieces();
        $now = self::now();
        try {
            $jobId = $this->transaction(function () use ($repository, $pieces, $deviceId, $ids, $withPrice, $body, $locale, $userId, $site, $now): int {
                $printer = $repository->printers(false, $deviceId)[0] ?? null;
                if ($printer === null) {
                    throw new GestionConflictException('PRINTER_NOT_FOUND', $this->texts->t('prints.error.PRINTER_NOT_FOUND', $locale), 404);
                }
                if ($repository->openJobCount(false) >= self::MAX_OPEN_JOBS) {
                    throw new GestionConflictException('PRINT_JOBS_LIMIT', $this->texts->t('prints.error.PRINT_JOBS_LIMIT', $locale, ['limit' => self::MAX_OPEN_JOBS]), 409, ['limit' => self::MAX_OPEN_JOBS]);
                }
                $numbers = [];
                foreach ($ids as $id) { // sous verrou, dans l'ordre des identifiants : une pièce qu'on retire au même instant n'entre pas dans un travail
                    $numbers[$id] = null;
                }
                ksort($numbers);
                foreach (array_keys($numbers) as $id) {
                    $piece = $pieces->piece($id, true);
                    if ($piece === null || (bool) $piece['exercise'] || $piece['status'] === LossPiece::RETIRED) {
                        throw new GestionConflictException('PIECE_RETIRED', $this->texts->t('piece_labels.error.PIECE_RETIRED', $locale), 409, ['pieceIds' => [$id]]);
                    }
                    $numbers[$id] = (int) $piece['number'];
                }
                $queued = $repository->waitingPieces($ids);
                if ($queued !== []) {
                    throw new GestionConflictException('PIECES_ALREADY_QUEUED', $this->texts->t('prints.error.PIECES_ALREADY_QUEUED', $locale), 409, ['pieceIds' => $queued]);
                }
                $active = [];
                foreach ($pieces->tagsOf($ids) as $pieceId => $tags) {
                    $active[$pieceId] = count(array_filter($tags, static fn (array $t): bool => $t['status'] === LossTag::ASSOCIATED));
                }
                $full = array_keys(array_filter($active, static fn (int $n): bool => $n >= LossTag::MAX_PER_PIECE));
                if ($full !== []) {
                    throw new GestionConflictException('PIECE_TAG_LIMIT', $this->texts->t('pieces.error.PIECE_TAG_LIMIT', $locale, ['limit' => LossTag::MAX_PER_PIECE]), 409, ['limit' => LossTag::MAX_PER_PIECE, 'pieceIds' => $full]);
                }
                $tagged = array_keys(array_filter($active, static fn (int $n): bool => $n >= 1));
                if ($tagged !== [] && ($body['confirmTagged'] ?? false) !== true) {
                    throw new GestionConflictException('PIECES_ALREADY_TAGGED', $this->texts->t('prints.error.PIECES_ALREADY_TAGGED', $locale), 409, ['pieceIds' => $tagged]);
                }
                $copies = $repository->copiesOf($ids);
                $worn = array_values(array_filter($ids, static fn (int $id): bool => ($copies[$id] ?? 0) > LossTagEpc::MAX_COPY));
                if ($worn !== []) {
                    throw new GestionConflictException('PIECE_LABELS_EXHAUSTED', $this->texts->t('prints.error.PIECE_LABELS_EXHAUSTED', $locale), 409, ['pieceIds' => $worn]);
                }
                $jobId = $repository->insertJob((int) $printer['box_id'], $deviceId, $withPrice, false, $this->secretRank($repository, $userId, $now), $userId, $now);
                foreach ($ids as $position => $id) { // dans l'ordre demandé
                    $repository->insertLine($jobId, $position + 1, $id, LossTagEpc::make($site, $numbers[$id], $copies[$id] ?? 0));
                }
                $this->journal()->append(LossJournalEntry::PRINT_JOB_CREATED, $userId, null, ['jobId' => $jobId, 'deviceId' => $deviceId, 'lines' => count($ids), 'withPrice' => $withPrice]);

                return $jobId;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), self::OPEN_PIECE_INDEX)) {
                throw $e; // une autre unicité violée n'est pas « cette pièce attend déjà »
            }
            // deux créations au même instant avec une même pièce : la base a tranché, tout ce que celle-ci avait écrit est annulé
            throw new GestionConflictException('PIECES_ALREADY_QUEUED', $this->texts->t('prints.error.PIECES_ALREADY_QUEUED', $locale), 409, ['pieceIds' => []]);
        }

        return $this->sheet($jobId, $locale, false);
    }

    /**
     * Annule un travail : ses lignes qui attendent encore ne seront plus imprimées ; ce qui est écrit le reste. Rejouable.
     *
     * @return array<string, mixed> le travail (`sheet`) et `cancelledLines`
     */
    public function cancel(int $id, string $locale, int $userId, bool $exercise): array
    {
        $repository = $this->repository();
        $cancelled = $this->transaction(function () use ($repository, $id, $locale, $userId, $exercise): int {
            $job = $repository->job($id, $exercise, true);
            if ($job === null) {
                throw new GestionConflictException('PRINT_JOB_NOT_FOUND', $this->texts->t('prints.error.PRINT_JOB_NOT_FOUND', $locale), 404);
            }
            if (!in_array($job['status'], LossPrintJob::OPEN, true)) {
                return 0; // déjà fini ou annulé : rien à faire, et le même geste envoyé deux fois répond pareil
            }
            $now = self::now();
            $lines = $repository->cancelWaiting($id, $now);
            $repository->jobCancelled($id, $now);
            if (!$exercise) {
                $this->journal()->append(LossJournalEntry::PRINT_JOB_CANCELLED, $userId, null, ['jobId' => $id, 'lines' => $lines]);
            }

            return $lines;
        });

        return $this->sheet($id, $locale, $exercise) + ['cancelledLines' => $cancelled];
    }

    /**
     * Peut-on créer un travail ? Sinon pourquoi : la clé de chiffrement manque (ou n'est pas lisible, ou n'est pas celle du mot de
     * passe rangé), ou aucune imprimante à puce n'est branchée. L'exercice n'a besoin d'aucune clé.
     *
     * @return array{ok: bool, reason: ?string, message: ?string}
     */
    public function readiness(string $locale, bool $exercise, ?bool $hasPrinter = null): array
    {
        $reason = $exercise ? null : $this->keyProblem();
        if ($reason === null && !($hasPrinter ?? $this->repository()->printers($exercise) !== [])) {
            $reason = 'NO_PRINTER';
        }

        return ['ok' => $reason === null, 'reason' => $reason, 'message' => $reason !== null ? $this->texts->t('prints.ready.' . $reason, $locale) : null];
    }

    /** Ce qui empêche de chiffrer ou de relire le mot de passe : un code `PRINT_KEY_…`, ou null si tout va */
    public function keyProblem(): ?string
    {
        if (isset(self::KEY_CODES[$this->vault->state()])) {
            return self::KEY_CODES[$this->vault->state()];
        }
        $secret = $this->repository()->currentSecret();

        return $secret !== null && $this->vault->decrypt($secret['key_rank'], $secret['cipher'], $this->secretContext($secret['rank'])) === null ? 'PRINT_KEY_MISMATCH' : null;
    }

    /** Nombre de puces écrites et relues par une imprimante (l'étape d'installation s'y fie) */
    public function writtenCount(bool $exercise): int
    {
        return $this->repository()->writtenCount($exercise);
    }

    // ----- Le boîtier -----

    /**
     * Les travaux qu'un vrai boîtier doit encore imprimer, avec ce qu'il lui faut : les textes, le code-barres, l'identifiant à
     * écrire et le mot de passe d'accès. SEULE méthode qui rend ce mot de passe : sa réponse ne va qu'au boîtier, jamais dans un
     * journal. Une lecture : rien n'est écrit.
     *
     * @return array{jobs: list<array<string, mixed>>, more: bool}
     */
    public function jobsOf(int $boxId): array
    {
        $repository = $this->repository();
        // les mots de passe qui se relisent avec la clé posée : sans eux (clé retirée, autre clé), rien n'est servi, et rien n'attend devant
        $passwords = [];
        foreach ($repository->secrets() as $rank => $secret) {
            $password = $this->vault->decrypt($secret['key_rank'], $secret['cipher'], $this->secretContext($rank));
            if ($password !== null) {
                $passwords[$rank] = $password;
            }
        }
        $lines = $repository->waitingLinesOfBox($boxId, array_keys($passwords), self::BOX_BATCH + 1);
        $more = count($lines) > self::BOX_BATCH;
        $lines = array_slice($lines, 0, self::BOX_BATCH);
        if ($lines === []) {
            return ['jobs' => [], 'more' => false];
        }
        $variants = $this->pieces()->variants(array_map(static fn (array $l): int => (int) $l['variant_id'], $lines));
        $locale = $this->siteLocale?->siteLocale() ?? 'fr';
        $formatter = new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::CURRENCY);
        $currency = $this->site->currency();
        $jobs = [];
        foreach ($lines as $line) {
            $rank = (int) $line['secret_rank'];
            $variant = $variants[(int) $line['variant_id']] ?? null;
            $catalogue = LossPieceService::catalogue($variant);
            if ($catalogue['barcode'] === null) {
                continue; // l'article a changé entre les deux lectures : la ligne attend le prochain appel
            }
            $jobId = (int) $line['job_id'];
            $jobs[$jobId] ??= ['id' => $jobId, 'printer' => (string) $line['serial'], 'withPrice' => (bool) $line['with_price'],
                'access' => ['rank' => $rank, 'password' => $passwords[$rank]], 'lines' => []];
            $price = $variant['price'] !== null ? (int) $variant['price'] : ($variant['product_price'] !== null ? (int) round((float) $variant['product_price']) : null);
            $jobs[$jobId]['lines'][] = ['id' => (int) $line['id'], 'position' => (int) $line['position'], 'identifier' => (string) $line['identifier'], 'piece' => (int) $line['number'],
                'title' => (string) $catalogue['name'], 'subtitle' => $catalogue['variantLabel'], 'barcode' => (string) $catalogue['barcode'], 'symbology' => $catalogue['symbology'],
                'price' => (bool) $line['with_price'] && $price !== null && $price > 0 ? (string) $formatter->formatCurrency($price / 100, $currency) : null,
                'note' => $this->texts->t('piece_labels.note', $locale, ['number' => (int) $line['number']])];
        }

        return ['jobs' => array_values($jobs), 'more' => $more];
    }

    /**
     * Avant de traiter les résultats d'impression d'un lot : tenir d'avance leurs pièces puis leurs travaux, dans l'ordre commun
     * au module. Sans cela, un lot qui tient déjà un travail demanderait la pièce du résultat suivant, à rebours d'un retrait de
     * pièce (qui tient la pièce, puis demande le travail).
     *
     * @param list<array{deviceId: int, line: int}> $facts les résultats d'impression du lot, déjà vérifiés dans leur forme
     */
    public function hold(int $boxId, array $facts): void
    {
        $repository = $this->repository();
        $pieces = [];
        $jobs = [];
        foreach ($facts as $fact) {
            $ref = $repository->lineRef($fact['line'], $boxId, $fact['deviceId']);
            if ($ref !== null) {
                $pieces[$ref['piece_id']] = true;
                $jobs[$ref['job_id']] = true;
            }
        }
        $repository->lockForResults(array_keys($pieces), array_keys($jobs));
    }

    /**
     * Le résultat d'une étiquette, dit par le boîtier, dans la transaction du lot de faits. C'est le verrou de la LIGNE qui met
     * deux résultats en file (le boîtier, lui, n'est tenu qu'en partage). Une ligne ne se clôt
     * qu'une fois : le même résultat renvoyé est un doublon, un résultat contraire est refusé.
     *
     * @param array{type: string, key: string, at: int, deviceId: int, line: int, identifier?: string, reason?: string, doubt?: bool} $fact
     *
     * @return array{0: string, 1: ?string} état (`accepted`, `duplicate`, `refused`) et code du refus
     */
    public function result(int $boxId, array $fact, string $now): array
    {
        $repository = $this->repository();
        $ref = $repository->lineRef($fact['line'], $boxId, $fact['deviceId']);
        if ($ref === null) {
            return ['refused', 'PRINT_LINE_UNKNOWN'];
        }
        // Ordre des verrous, le même partout dans le module : la pièce, le travail, la ligne. `hold` les a déjà pris pour tout le
        // lot ; les reprendre ici ne coûte rien et garde cette méthode juste si elle est appelée seule
        $pieces = $this->pieces();
        $pieceId = $ref['piece_id'];
        $jobId = $ref['job_id'];
        $piece = $pieces->piece($pieceId, true);
        $repository->lockJob($jobId);
        $line = $repository->lockedLine($fact['line']);
        if ($line === null) {
            return ['refused', 'PRINT_LINE_UNKNOWN'];
        }
        $written = $fact['type'] === LossPieceEvent::TAG_WRITTEN;
        if ($written && $fact['identifier'] !== $line['identifier']) {
            return ['refused', 'IDENTIFIER_MISMATCH']; // l'imprimante a relu autre chose que ce qu'on lui a demandé : rien n'est associé
        }
        if ($line['status'] !== LossPrintJobLine::WAITING) {
            // déjà close de la même façon : un doublon. Une écriture que la plateforme a elle-même close en échec (pièce retirée,
            // trop de puces, identifiant déjà connu) a été reçue : la renvoyer ne change rien, c'est un doublon aussi
            $same = $written
                ? $line['status'] === LossPrintJobLine::WRITTEN || ($line['status'] === LossPrintJobLine::FAILED && in_array($line['reason'], self::OWN_REASONS, true))
                : ($line['status'] === LossPrintJobLine::FAILED && $line['reason'] === $fact['reason']);

            return $same ? ['duplicate', null] : ['refused', 'PRINT_LINE_CLOSED'];
        }
        $lineId = (int) $line['id'];
        if (!$written) {
            $repository->lineFailed($lineId, $fact['reason'], $now);
            $repository->refreshJob($jobId, $now);

            return ['accepted', null];
        }
        // La puce est écrite et relue conforme : elle se pose sur la pièce, comme par le geste ordinaire
        $failure = null;
        $tagId = null;
        if ($piece === null || $piece['status'] === LossPiece::RETIRED) {
            $failure = LossPrintJobLine::PIECE_RETIRED;
        } else {
            $tag = $pieces->tagByIdentifier((string) $line['identifier'], true);
            $active = $pieces->activeTagCount($pieceId);
            if ($tag !== null) {
                // l'identifiant est déjà une puce du site (posée à la main entre-temps) : la sienne, ou une autre
                $tag['status'] === LossTag::ASSOCIATED && (int) $tag['piece_id'] === $pieceId ? $tagId = (int) $tag['id'] : $failure = LossPrintJobLine::IDENTIFIER_TAKEN;
            } elseif ($active >= LossTag::MAX_PER_PIECE) {
                $failure = LossPrintJobLine::TAG_LIMIT;
            } else {
                $tagId = $pieces->insertAssociatedTag((string) $line['identifier'], (string) $line['identifier'], LossTagIdentifier::UHF, $pieceId, false, null, $now);
                // l'heure du fait est celle du boîtier, sauf si son horloge est douteuse : celle du serveur alors, et le fait le dit.
                // Sa clé est celle de la LIGNE (une ligne ne s'écrit qu'une fois) : elle ne dépend pas de la clé choisie par le boîtier
                $doubt = (bool) ($fact['doubt'] ?? false);
                $this->boxes()->pieceFact(LossPieceEvent::TAG_ASSOCIATED, $doubt ? $now : gmdate('Y-m-d H:i:s', $fact['at']), $pieceId, $tagId, $boxId, $fact['deviceId'], 'b' . $boxId . ':print:' . $lineId, false,
                    ['technology' => LossTagIdentifier::UHF, 'printLineId' => $lineId] + ($doubt ? ['clockDoubt' => true] : []), $now);
                if ($active >= 1) {
                    $pieces->event(LossPieceEvent::PAIR_SET, null, $pieceId, $tagId, false, ['tags' => $active + 1], $now);
                }
            }
        }
        $failure !== null ? $repository->lineFailed($lineId, $failure, $now) : $repository->lineWritten($lineId, (int) $tagId, $now);
        $repository->refreshJob($jobId, $now);

        return ['accepted', null];
    }

    // ----- Mode exercice : l'imprimante simulée -----

    /**
     * Joue un scénario de l'imprimante simulée d'un boîtier simulé, dans la transaction de l'appelant (verrou d'exercice tenu) : un
     * travail d'exercice sur des pièces d'exercice sans puce, et son résultat, comme un vrai boîtier l'aurait renvoyé.
     *
     * @param array<string, mixed> $printer l'imprimante simulée (`loss_device`)
     *
     * @return array{jobId: int, written: int}
     */
    public function play(int $boxId, array $printer, string $scenario, string $locale, int $userId, string $now): array
    {
        $repository = $this->repository();
        $pieces = $this->pieces();
        $chosen = $repository->exercisePiecesWithoutTag(self::EXERCISE_LINES);
        if ($chosen === []) {
            throw new GestionConflictException('EXERCISE_NO_UNTAGGED_PIECE', $this->texts->t('prints.error.EXERCISE_NO_UNTAGGED_PIECE', $locale), 409);
        }
        if ($pieces->exerciseTagCount() + count($chosen) > LossPieceService::EXERCISE_TAG_LIMIT) {
            throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('pieces.error.EXERCISE_TAG_LIMIT_REACHED', $locale, ['limit' => LossPieceService::EXERCISE_TAG_LIMIT]), 409,
                ['limit' => LossPieceService::EXERCISE_TAG_LIMIT]);
        }
        $deviceId = (int) $printer['id'];
        $jobId = $repository->insertJob($boxId, $deviceId, false, true, null, $userId, $now);
        $written = 0;
        foreach ($chosen as $index => $piece) {
            $identifier = LossTagIdentifier::simulated();
            $lineId = $repository->insertLine($jobId, $index + 1, (int) $piece['id'], $identifier);
            // écriture refusée : la première étiquette échoue, les suivantes passent ; plus d'étiquettes : la première passe, puis plus rien
            $failure = match ($scenario) {
                'print_refused' => $index === 0 ? 'write_refused' : null,
                'print_no_labels' => $index === 0 ? null : 'out_of_labels',
                default => null,
            };
            if ($failure !== null) {
                $repository->lineFailed($lineId, $failure, $now);
                continue;
            }
            $tagId = $pieces->insertAssociatedTag($identifier, $identifier, LossTagIdentifier::SIMULATED, (int) $piece['id'], true, $userId, $now);
            $this->boxes()->pieceFact(LossPieceEvent::TAG_ASSOCIATED, $now, (int) $piece['id'], $tagId, $boxId, $deviceId, null, true, ['technology' => LossTagIdentifier::SIMULATED, 'printLineId' => $lineId], $now);
            $repository->lineWritten($lineId, $tagId, $now);
            ++$written;
        }
        $repository->refreshJob($jobId, $now);

        return ['jobId' => $jobId, 'written' => $written];
    }

    // ----- Outils -----

    /** Le rang du mot de passe à employer : celui du site, tiré et rangé chiffré au premier travail (un seul à la fois le crée) */
    private function secretRank(LossPrintRepository $repository, int $userId, string $now): int
    {
        $repository->lockSecrets();
        $current = $repository->currentSecret();
        if ($current !== null) {
            return $current['rank'];
        }
        [$keyRank, $cipher] = $this->vault->encrypt(LossTagVault::draw(), $this->secretContext(1));
        $repository->insertSecret(1, $keyRank, $cipher, $now);
        $this->journal()->append(LossJournalEntry::TAG_SECRET_CREATED, $userId, null, ['rank' => 1, 'keyRank' => $keyRank]); // le rang seulement, jamais le mot de passe ni son chiffre

        return 1;
    }

    /** À quoi un chiffre est lié : ce site, ce rang de mot de passe. Un chiffre copié d'un autre site ou d'un autre rang ne se relit pas */
    private function secretContext(int $rank): string
    {
        return 'loss-tag-secret:' . $this->tenant->getTenantCode() . ':' . $rank;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function jobView(array $row, string $locale): array
    {
        $lines = (int) $row['lines'];
        $counts = ['written' => (int) $row['written'], 'failed' => (int) $row['failed'], 'waiting' => (int) $row['waiting']];

        return ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'statusLabel' => $this->texts->t('prints.status.' . $row['status'], $locale),
            'printer' => ['deviceId' => (int) $row['device_id'], 'serial' => (string) $row['serial'], 'model' => $row['model'], 'boxId' => (int) $row['box_id'], 'boxName' => (string) $row['box_name']],
            'withPrice' => (bool) $row['with_price'], 'lines' => $lines] + $counts + ['cancelled' => $lines - array_sum($counts),
            'createdAt' => self::iso($row['created_at']), 'finishedAt' => self::iso($row['finished_at']), 'cancelledAt' => self::iso($row['cancelled_at']), 'exercise' => (bool) $row['exercise']];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function printerView(array $row, string $locale): array
    {
        return ['deviceId' => (int) $row['id'], 'serial' => (string) $row['serial'], 'model' => $row['model'], 'status' => (string) $row['status'],
            'statusLabel' => $this->texts->t('boxes.deviceStatus.' . $row['status'], $locale),
            'boxId' => (int) $row['box_id'], 'boxName' => (string) $row['box_name'], 'boxEnrolled' => $row['box_status'] === LossBox::ACTIVE];
    }

    /** @param array<string, string> $errors */
    private static function positive(mixed $value, string $field, array &$errors, bool $required = false): ?int
    {
        if (is_string($value) && preg_match('/\A[0-9]{1,9}\z/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > 2147483647) {
            $errors[$field] = $value === null && $required ? 'REQUIRED' : 'MUST_BE_POSITIVE';

            return null;
        }

        return $value;
    }

    private static function iso(mixed $stored): ?string
    {
        return $stored === null ? null : (new \DateTimeImmutable((string) $stored, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $result = $work();
            $conn->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private function repository(): LossPrintRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossPrintJob::class);
    }

    private function pieces(): LossPieceRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossPiece::class);
    }

    private function boxes(): LossBoxRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossBox::class);
    }

    private function journal(): \App\LossPrevention\Repository\LossJournalRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class);
    }
}
