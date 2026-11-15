<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossTag;
use App\LossPrevention\Repository\LossPieceRepository;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Assistant d'installation des étiquettes à puce (lot L2-b, 15/11/2026, `docs/prevention-pertes.md` § 13.10) : une suite d'étapes
 * dont l'avancement se CALCULE à partir des faits (`loss_piece_event`), jamais d'une case cochée ; rien n'est stocké pour lui.
 *
 * - Lire l'assistant, ou « Vérifier » une étape, n'écrit rien.
 * - Deux gestes écrivent un fait, parce que c'est lui qui prouve l'étape : l'étiquette d'essai (`TEST_LABEL_PRINTED`) et la lecture
 *   d'une puce d'essai (`TEST_TAG_READ`, avec la puce enregistrée « lue, pas encore posée »).
 * - `$exercise` : le même assistant sur des puces simulées, ouvert sans le droit du module ; ses faits s'effacent avec les pièces
 *   d'exercice et n'avancent jamais l'installation réelle.
 * Les étapes du boîtier (L3) et des antennes (L4) s'ajouteront à `STEPS`.
 */
class LossInstallationService
{
    public const SCREEN = 'pertes.installation';
    /** Étapes, dans l'ordre : code => le fait qui prouve l'étape (null : étape d'information, faite dès qu'une étape suivante l'est) */
    public const STEPS = [
        'hardware' => null,
        'test_label' => LossPieceEvent::TEST_LABEL_PRINTED,
        'test_tag' => LossPieceEvent::TEST_TAG_READ,
        'first_piece' => LossPieceEvent::TAG_ASSOCIATED,
    ];
    /** Faits d'essai gardés au plus en exercice (routes ouvertes sans le droit du module) */
    public const EXERCISE_TEST_LIMIT = 60;
    /** Code imprimé sur l'étiquette d'essai : il ne désigne aucun article */
    public const TEST_BARCODE = 'ESSAI-ETIQUETTE';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossPieceService $pieces,
        private readonly CatalogBarcodeService $barcodes,
        private readonly BarcodeLabelPrinter $printer,
        private readonly LossTexts $texts,
    ) {
    }

    /**
     * L'assistant : ses étapes et où l'on en est.
     *
     * @return array{steps: list<array<string, mixed>>, progress: array{done: int, total: int}, complete: bool, exercise: bool, screen: string}
     */
    public function steps(string $locale, bool $exercise): array
    {
        $counts = $this->repository()->eventCounts(array_values(array_filter(self::STEPS)), $exercise);
        $done = [];
        foreach (self::STEPS as $code => $event) {
            $done[$code] = $event !== null && $counts[$event] > 0;
        }
        // une étape d'information est faite dès qu'une étape qui la suit l'est
        $codes = array_keys(self::STEPS);
        foreach ($codes as $i => $code) {
            if (self::STEPS[$code] === null) {
                $done[$code] = in_array(true, array_map(static fn (string $next): bool => $done[$next], array_slice($codes, $i + 1)), true);
            }
        }
        $steps = [];
        $current = null;
        foreach ($codes as $code) {
            $status = $done[$code] ? 'done' : ($current === null ? 'current' : 'todo');
            if ($status === 'current') {
                $current = $code;
            }
            $steps[] = [
                'code' => $code,
                'title' => $this->texts->t("installation.steps.$code.title", $locale),
                'text' => $this->texts->t("installation.steps.$code.text", $locale),
                'image' => 'pertes/installation/' . $code, // clé d'illustration : un dessin tant que le matériel n'est pas choisi
                'status' => $status,
                // « Vérifier » relit les données ; `none` : étape d'information, rien à vérifier
                'verify' => ['kind' => self::STEPS[$code] === null ? 'none' : 'data', 'label' => $this->texts->t("installation.steps.$code.verify", $locale)],
            ];
        }

        return ['steps' => $steps, 'progress' => ['done' => count(array_filter($done)), 'total' => count($done)], 'complete' => !in_array(false, $done, true),
            'exercise' => $exercise, 'screen' => self::SCREEN];
    }

    /**
     * « Vérifier » : l'étape est-elle faite, d'après les données ? Sinon, quoi essayer. N'écrit rien.
     *
     * @return array{code: string, verified: bool, hint: ?string}
     */
    public function verify(string $code, string $locale, bool $exercise): array
    {
        if (!array_key_exists($code, self::STEPS)) {
            throw new GestionConflictException('STEP_NOT_FOUND', $this->texts->t('installation.error.STEP_NOT_FOUND', $locale), 404);
        }
        $step = array_values(array_filter($this->steps($locale, $exercise)['steps'], static fn (array $s): bool => $s['code'] === $code))[0];
        $verified = $step['status'] === 'done';

        return ['code' => $code, 'verified' => $verified, 'hint' => $verified ? null : $this->texts->t("installation.steps.$code.hint", $locale)];
    }

    /**
     * Lire une puce d'essai : la lecture a-t-elle la forme d'une puce ? Si oui, la puce est enregistrée « lue, pas encore posée »
     * (une puce déjà connue reste telle qu'elle est) et un fait `TEST_TAG_READ` est écrit ; sinon rien n'est écrit et on dit quoi essayer.
     *
     * @param array<string, mixed> $body { identifier, technology (uhf | nfc) } ; exercice : `identifier` facultatif, puce simulée
     *
     * @return array<string, mixed> { ok, identifier, technology, refusal, hint, tag, installation }
     */
    public function testRead(array $body, string $locale, int $userId, bool $exercise): array
    {
        $errors = [];
        $technology = $exercise ? LossTagIdentifier::SIMULATED : ($body['technology'] ?? null);
        if (!$exercise && !in_array($technology, LossTagIdentifier::REAL, true)) {
            $errors['technology'] = array_key_exists('technology', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $raw = $body['identifier'] ?? null;
        if ($raw === null && $exercise) {
            $raw = LossTagIdentifier::simulated();
        }
        if (!is_string($raw) || trim($raw) === '') {
            $errors['identifier'] = array_key_exists('identifier', $body) ? 'MUST_BE_STRING' : 'REQUIRED';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $failed = fn (string $refusal, string $hint, array $params = []): array => ['ok' => false, 'identifier' => null, 'technology' => $technology, 'refusal' => $refusal,
            'hint' => $this->texts->t("installation.hint.$hint", $locale, $params), 'tag' => null, 'installation' => $this->steps($locale, $exercise)];
        $identifier = LossTagIdentifier::canonical($raw, $technology);
        if ($identifier === null) {
            // quoi essayer, d'après ce que le lecteur a rendu
            $clean = (string) preg_replace('/[\s:.\-]+/', '', $raw);
            $hint = $exercise ? 'simulated' : (ctype_digit($clean) ? 'decimal' : (preg_match('/\A(0x)?[0-9a-f]+\z/i', $clean) === 1 ? 'length_' . $technology : 'form'));

            return $failed('TAG_INVALID', $hint);
        }
        if (!$exercise) {
            $asBarcode = CatalogBarcode::normalize($raw);
            $holder = ($asBarcode !== null ? $this->barcodes->holder($asBarcode) : null) ?? $this->barcodes->holder($identifier);
            if ($holder !== null) {
                return $failed('TAG_IS_BARCODE', 'barcode', ['label' => $holder['label']]);
            }
        }
        $repository = $this->repository();
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $write = function () use ($repository, $conn, $identifier, $raw, $technology, $exercise, $userId, $now, $locale): array {
            $conn->beginTransaction();
            try {
                if ($exercise) {
                    $conn->executeStatement('SELECT pg_advisory_xact_lock(' . LossPieceRepository::EXERCISE_LOCK . ')');
                    if ($repository->eventCounts([LossPieceEvent::TEST_TAG_READ], true)[LossPieceEvent::TEST_TAG_READ] >= self::EXERCISE_TEST_LIMIT) {
                        throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('installation.error.EXERCISE_TEST_LIMIT', $locale, ['limit' => self::EXERCISE_TEST_LIMIT]), 409, ['limit' => self::EXERCISE_TEST_LIMIT]);
                    }
                }
                $tag = $repository->tagByIdentifier($identifier, true);
                if ($tag !== null && (bool) $tag['exercise'] !== $exercise) {
                    throw new \LogicException('Puce d\'un autre genre que la lecture'); // impossible : les deux formes ne se recouvrent pas
                }
                if ($tag === null) {
                    if ($exercise && $repository->exerciseTagCount() >= LossPieceService::EXERCISE_TAG_LIMIT) {
                        throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('pieces.error.EXERCISE_TAG_LIMIT_REACHED', $locale, ['limit' => LossPieceService::EXERCISE_TAG_LIMIT]), 409, ['limit' => LossPieceService::EXERCISE_TAG_LIMIT]);
                    }
                    $tagId = $repository->insertKnownTag($identifier, mb_substr(trim((string) $raw), 0, LossTagIdentifier::RAW_MAX), $technology, $exercise, $userId, $now);
                } else {
                    $tagId = (int) $tag['id'];
                }
                $repository->event(LossPieceEvent::TEST_TAG_READ, $userId, null, $tagId, $exercise, ['technology' => $technology], $now);
                $conn->commit();

                return (array) $repository->tagById($tagId);
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        };
        try {
            $tag = $write();
        } catch (UniqueConstraintViolationException) {
            $tag = $write(); // la même puce lue au même instant ailleurs : elle est connue maintenant
        }
        // La lecture marche. Si la puce est déjà posée ou retirée, on le dit : il en faudra une libre pour la suite
        $hint = match ($tag['status']) {
            LossTag::ASSOCIATED => $this->texts->t('installation.hint.already_associated', $locale),
            LossTag::RETIRED => $this->texts->t('installation.hint.retired', $locale),
            default => null,
        };

        return ['ok' => true, 'identifier' => $identifier, 'technology' => $technology, 'refusal' => null, 'hint' => $hint, 'tag' => $this->pieces->tagView($tag, $locale),
            'installation' => $this->steps($locale, $exercise)];
    }

    /**
     * L'étiquette d'essai : une étiquette au format demandé, avec un code qui ne désigne aucun article. Le fait `TEST_LABEL_PRINTED`
     * prouve l'étape (le serveur ne voit pas l'imprimante : il sait seulement que l'étiquette a été demandée).
     *
     * @param array<string, mixed> $body { format?: a4-50x30 | roll-50x30 }
     *
     * @return array{content: string, format: string}
     */
    public function testLabel(array $body, string $locale, int $userId, bool $exercise): array
    {
        $format = $body['format'] ?? BarcodeLabelPrinter::A4;
        if (!is_string($format) || !isset(BarcodeLabelPrinter::FORMATS[$format])) {
            throw new GestionValidationException(['format' => 'INVALID_VALUE']);
        }
        $repository = $this->repository();
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            if ($exercise) {
                $conn->executeStatement('SELECT pg_advisory_xact_lock(' . LossPieceRepository::EXERCISE_LOCK . ')');
                if ($repository->eventCounts([LossPieceEvent::TEST_LABEL_PRINTED], true)[LossPieceEvent::TEST_LABEL_PRINTED] >= self::EXERCISE_TEST_LIMIT) {
                    throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('installation.error.EXERCISE_TEST_LIMIT', $locale, ['limit' => self::EXERCISE_TEST_LIMIT]), 409, ['limit' => self::EXERCISE_TEST_LIMIT]);
                }
            }
            $repository->event(LossPieceEvent::TEST_LABEL_PRINTED, $userId, null, null, $exercise, ['format' => $format], (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $label = ['title' => $this->texts->t('installation.test_label.title', $locale), 'subtitle' => $this->texts->t('installation.test_label.subtitle', $locale),
            'barcode' => self::TEST_BARCODE, 'price' => null, 'copies' => 1, 'note' => $this->texts->t('installation.test_label.note', $locale)];

        return ['content' => $this->printer->pdf([$label], $this->texts->t('installation.test_label.title', $locale), $format), 'format' => $format];
    }

    private function repository(): LossPieceRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossPiece::class);
    }
}
