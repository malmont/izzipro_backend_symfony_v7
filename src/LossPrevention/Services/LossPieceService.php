<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossJournalEntry;
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
 * Pièces et puces (lot L2-a, 15/11/2026, `docs/prevention-pertes.md` § 13) : créer les pièces d'une variante, poser une puce sur une
 * pièce d'un geste (lire le code-barres, lire la puce), retrouver ce que porte une puce, retirer une puce ou une pièce, synthèse.
 *
 * - Le module n'écrit que dans ses tables : rien dans le stock, les ventes ni le catalogue (lu seulement).
 * - Un identifiant de puce désigne une seule puce sur le site : l'index unique de la base tranche deux lectures simultanées.
 * - Une puce associée ne passe jamais à une autre pièce ; une puce retirée ne revient pas (la base le refuse aussi).
 * - Chaque changement écrit un fait (`loss_piece_event`) dans la même transaction que la ligne qu'il change.
 * - `$exercise` : les mêmes gestes sur des pièces d'exercice et des puces simulées ; les deux mondes ne se croisent sur aucune route
 *   (une vraie pièce est « inconnue » des routes d'exercice, et l'inverse), et rien d'exercice n'entre dans un chiffre réel.
 */
class LossPieceService
{
    public const CREATE_LIMIT = 500;
    public const PAGE_LIMIT = 100;
    /** Pièces d'exercice présentes au plus sur un site, et créations d'exercice par jour du magasin (routes ouvertes sans le droit du module) */
    public const EXERCISE_LIMIT = 30;
    public const EXERCISE_CREATE_LIMIT = 10;
    public const EXERCISE_DAILY_LIMIT = 30;
    /** Puces simulées présentes au plus sur un site, retirées comprises : poser puis retirer en boucle ne remplit pas la base */
    public const EXERCISE_TAG_LIMIT = 60;
    private const TAG_IDENTIFIER_INDEX = 'uniq_loss_tag_identifier';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CatalogBarcodeService $barcodes,
        private readonly LossTexts $texts,
        private readonly LossSite $site,
        private readonly int $exerciseDailyLimit = self::EXERCISE_DAILY_LIMIT, // les tests du plafond du jour en donnent un plus bas
    ) {
    }

    /**
     * Liste des pièces, les plus récentes d'abord.
     *
     * @param array<string, mixed> $query variantId, productId, status, tagged (0 | 1), page, limit
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(array $query, string $locale, bool $exercise): array
    {
        $errors = [];
        $filters = [];
        foreach (['variantId', 'productId'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $id = self::positiveInt($query[$key]);
                $id === null ? $errors[$key] = 'MUST_BE_POSITIVE' : $filters[$key] = $id;
            }
        }
        if (isset($query['status']) && $query['status'] !== '') {
            // depuis le lot L3-b2a une pièce peut être vendue, ou revenue (de nouveau vendable)
            in_array($query['status'], [LossPiece::CREATED, LossPiece::SOLD, LossPiece::RETURNED, LossPiece::RETIRED], true) ? $filters['status'] = $query['status'] : $errors['status'] = 'INVALID_VALUE';
        }
        if (isset($query['tagged']) && $query['tagged'] !== '') {
            in_array($query['tagged'], ['0', '1'], true) ? $filters['tagged'] = $query['tagged'] === '1' : $errors['tagged'] = 'INVALID_VALUE';
        }
        [$page, $limit] = self::paging($query, $errors);
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $found = $this->repository()->page($filters, $exercise, $page, $limit);

        return ['rows' => $this->sheets($found['rows'], $locale), 'total' => $found['total'], 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string, mixed> la fiche d'une pièce, avec ses puces et ses faits */
    public function sheet(int $id, string $locale, bool $exercise): array
    {
        $piece = $this->existing($id, $exercise, $locale);
        $events = [];
        foreach ($this->repository()->eventsOf($id) as $row) {
            $events[] = ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'label' => $this->texts->t('pieces.event.' . $row['type'], $locale),
                'at' => LossAuditEntry::iso(new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC'))),
                'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null, 'tagId' => $row['tag_id'] !== null ? (int) $row['tag_id'] : null,
                'data' => json_decode((string) $row['data'], true) ?: (object) []];
        }

        return $this->sheets([$piece], $locale)[0] + ['sale' => $this->saleOf($id, $locale), 'events' => $events];
    }

    /**
     * La vente à laquelle une pièce est reliée (lot L3-b2a) : le ticket, le poste, l'heure, et par quoi (lue sur le tapis, ou
     * rattrapée à la sortie). Aucun nom de personne. Null si la pièce n'est pas vendue.
     *
     * @return array<string, mixed>|null
     */
    private function saleOf(int $pieceId, string $locale): ?array
    {
        $sale = $this->emProvider->getEntityManager()->getRepository(\App\LossPrevention\Entity\LossSaleUnit::class)->salesOfPieces([$pieceId])[$pieceId] ?? null;
        if ($sale === null) {
            return null;
        }

        return ['orderId' => (int) $sale['order_id'], 'reference' => $sale['reference'], 'cashRegisterId' => $sale['cash_register_id'] !== null ? (int) $sale['cash_register_id'] : null,
            'soldAt' => self::iso($sale['sold_at']), 'by' => (string) $sale['matched_by'], 'byLabel' => $this->texts->t('pieces.sale.' . $sale['matched_by'], $locale),
            'linkedAt' => self::iso($sale['matched_at'])];
    }

    /**
     * Crée les pièces d'une variante : à la réception d'une commande, ou pour le stock déjà en rayon.
     *
     * @param array<string, mixed> $body { variantId | barcode, count, commandeId? }
     *
     * @return array{pieces: list<array<string, mixed>>, count: int}
     */
    public function create(array $body, string $locale, int $userId, bool $exercise): array
    {
        $max = $exercise ? self::EXERCISE_CREATE_LIMIT : self::CREATE_LIMIT;
        $errors = [];
        $count = $body['count'] ?? 1;
        if (!is_int($count) || $count < 1 || $count > $max) {
            $errors['count'] = 'OUT_OF_RANGE';
        }
        $commandeId = $body['commandeId'] ?? null;
        if ($commandeId !== null && (!is_int($commandeId) || $commandeId < 1 || $commandeId > 2147483647 || $exercise)) {
            $errors['commandeId'] = $exercise ? 'NOT_ALLOWED' : 'MUST_BE_POSITIVE';
        }
        $target = $this->targetOf($body, ['variantId', 'barcode'], $errors);
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $variant = $this->variant($target, $locale);
        $repository = $this->repository();
        if ($commandeId !== null && !$repository->commandeExists($commandeId)) {
            throw new GestionConflictException('COMMANDE_NOT_FOUND', $this->texts->t('pieces.error.COMMANDE_NOT_FOUND', $locale), 404);
        }
        $now = self::now();
        $ids = $this->transaction(function () use ($repository, $variant, $commandeId, $exercise, $userId, $count, $now, $locale): array {
            if ($exercise) {
                $this->exerciseRoom($count, $locale);
            }
            $ids = $repository->createPieces((int) $variant['id'], (int) $variant['product_id'], $commandeId, $exercise, $userId, $count, $now);
            foreach ($ids as $id) {
                $repository->event(LossPieceEvent::PIECE_CREATED, $userId, $id, null, $exercise, ['variantId' => (int) $variant['id'], 'commandeId' => $commandeId], $now);
            }
            if ($exercise) {
                $this->journal()->append(LossJournalEntry::EXERCISE_PIECES_CREATED, $userId, null, ['count' => count($ids)]);
            }

            return $ids;
        });

        return ['pieces' => $this->sheets($repository->pieces($ids), $locale), 'count' => count($ids)];
    }

    /**
     * LE geste : poser une puce sur une pièce. La pièce est donnée (`pieceId`), ou créée pour la variante désignée (`variantId`, ou
     * `barcode` : le code-barres lu). Rejouer le même geste ne crée rien de plus : la puce déjà posée sur une pièce de cette
     * variante rend cette pièce.
     *
     * @param array<string, mixed> $body { pieceId | variantId | barcode, identifier, technology } ; exercice : `identifier` facultatif
     *                                   (le serveur simule la lecture), technologie toujours `simulated`
     *
     * @return array{created: bool, associated: bool, piece: array<string, mixed>, tag: array<string, mixed>}
     */
    public function associate(array $body, string $locale, int $userId, bool $exercise): array
    {
        $errors = [];
        $target = $this->targetOf($body, ['pieceId', 'variantId', 'barcode'], $errors);
        $technology = $exercise ? LossTagIdentifier::SIMULATED : ($body['technology'] ?? null);
        if (!$exercise && !in_array($technology, LossTagIdentifier::REAL, true)) {
            $errors['technology'] = array_key_exists('technology', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $raw = $body['identifier'] ?? null;
        if ($raw === null && $exercise) {
            $raw = LossTagIdentifier::simulated(); // « Simuler une lecture »
        }
        if (!is_string($raw) || trim($raw) === '') {
            $errors['identifier'] = array_key_exists('identifier', $body) ? 'MUST_BE_STRING' : 'REQUIRED';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $identifier = LossTagIdentifier::canonical($raw, $technology);
        if ($identifier === null) {
            throw new GestionConflictException('TAG_INVALID', $this->texts->t('pieces.error.TAG_INVALID.' . $technology, $locale), 422, ['technology' => $technology]);
        }
        // Un lecteur branché en clavier écrit là où est le curseur : le code-barres d'un article n'est pas une puce. On compare la
        // lecture telle qu'un code-barres l'écrirait (« ABCD-1234 ») et sa forme nettoyée de puce (« ABCD1234 »)
        if (!$exercise) {
            $asBarcode = CatalogBarcode::normalize($raw);
            $holder = ($asBarcode !== null ? $this->barcodes->holder($asBarcode) : null) ?? $this->barcodes->holder($identifier);
            if ($holder !== null) {
                throw new GestionConflictException('TAG_IS_BARCODE', $this->texts->t('pieces.error.TAG_IS_BARCODE', $locale, ['label' => $holder['label']]), 409, ['holder' => $holder]);
            }
        }
        $variant = isset($target['pieceId']) ? null : $this->variant($target, $locale);
        $repository = $this->repository();
        $now = self::now();
        $attempt = fn (): array => $this->transaction(function () use ($repository, $target, $variant, $identifier, $raw, $technology, $exercise, $userId, $now, $locale): array {
                if ($exercise) {
                    $this->exerciseLock(); // toute écriture d'exercice attend un effacement en cours, et l'inverse
                }
                $pieceId = $target['pieceId'] ?? null;
                $piece = null;
                if ($pieceId !== null) {
                    $piece = $repository->piece($pieceId, true); // sous verrou : une pièce qu'on retire au même instant ne reçoit pas de puce
                    if ($piece === null || (bool) $piece['exercise'] !== $exercise) {
                        throw new GestionConflictException('PIECE_NOT_FOUND', $this->texts->t('pieces.error.PIECE_NOT_FOUND', $locale), 404);
                    }
                    if ($piece['status'] === LossPiece::RETIRED) {
                        throw new GestionConflictException('PIECE_RETIRED', $this->texts->t('pieces.error.PIECE_RETIRED', $locale), 409);
                    }
                }
                $tag = $repository->tagByIdentifier($identifier, true);
                if ($tag !== null && ($tag['status'] !== LossTag::KNOWN || (bool) $tag['exercise'] !== $exercise)) {
                    $same = $tag['status'] === LossTag::ASSOCIATED && (bool) $tag['exercise'] === $exercise ? $repository->piece((int) $tag['piece_id']) : null;
                    $replay = $same !== null && ($piece !== null ? (int) $same['id'] === (int) $piece['id'] : (int) $same['variant_id'] === (int) $variant['id']);
                    if ($replay) {
                        return ['created' => false, 'associated' => false, 'pieceId' => (int) $same['id'], 'tagId' => (int) $tag['id']];
                    }
                    throw $this->tagTaken($tag, $locale);
                }
                $created = false;
                if ($piece === null) {
                    if ($exercise) {
                        $this->exerciseRoom(1, $locale);
                    }
                    $pieceId = $repository->createPieces((int) $variant['id'], (int) $variant['product_id'], null, $exercise, $userId, 1, $now)[0];
                    $repository->event(LossPieceEvent::PIECE_CREATED, $userId, $pieceId, null, $exercise, ['variantId' => (int) $variant['id'], 'commandeId' => null], $now);
                    if ($exercise) {
                        $this->journal()->append(LossJournalEntry::EXERCISE_PIECES_CREATED, $userId, null, ['count' => 1]);
                    }
                    $created = true;
                }
                if ($exercise && $tag === null && $repository->exerciseTagCount() >= self::EXERCISE_TAG_LIMIT) {
                    throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('pieces.error.EXERCISE_TAG_LIMIT_REACHED', $locale, ['limit' => self::EXERCISE_TAG_LIMIT]), 409, ['limit' => self::EXERCISE_TAG_LIMIT]);
                }
                $active = $repository->activeTagCount($pieceId);
                if ($active >= LossTag::MAX_PER_PIECE) {
                    throw new GestionConflictException('PIECE_TAG_LIMIT', $this->texts->t('pieces.error.PIECE_TAG_LIMIT', $locale, ['limit' => LossTag::MAX_PER_PIECE]), 409, ['limit' => LossTag::MAX_PER_PIECE]);
                }
                if ($tag !== null) {
                    // une puce déjà connue garde la technologie sous laquelle elle a été enregistrée (la base la fige) : le fait dit la même
                    $tagId = (int) $tag['id'];
                    $technology = (string) $tag['technology'];
                    $repository->associateKnownTag($tagId, $pieceId, $now);
                } else {
                    $tagId = $repository->insertAssociatedTag($identifier, mb_substr(trim((string) $raw), 0, LossTagIdentifier::RAW_MAX), $technology, $pieceId, $exercise, $userId, $now);
                }
                $repository->event(LossPieceEvent::TAG_ASSOCIATED, $userId, $pieceId, $tagId, $exercise, ['technology' => $technology], $now);
                if ($active >= 1) {
                    $repository->event(LossPieceEvent::PAIR_SET, $userId, $pieceId, $tagId, $exercise, ['tags' => $active + 1], $now);
                }

                return ['created' => $created, 'associated' => true, 'pieceId' => $pieceId, 'tagId' => $tagId];
            });
        try {
            $done = $attempt();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), self::TAG_IDENTIFIER_INDEX)) {
                throw $e; // une autre unicité violée n'est pas « cette puce est déjà posée »
            }
            // La même puce lue au même instant ailleurs : la base a tranché et tout ce que cette demande avait écrit est annulé. On
            // rejoue la demande une fois : elle voit maintenant la puce, et rend le rejeu (même geste envoyé deux fois) ou le refus
            // avec ce qui la porte
            try {
                $done = $attempt();
            } catch (UniqueConstraintViolationException) {
                throw new GestionConflictException('TAG_ALREADY_ASSOCIATED', $this->texts->t('pieces.error.TAG_ALREADY_ASSOCIATED_UNKNOWN', $locale), 409, ['holder' => null]);
            }
        }
        $piece = $this->pieceSheet($done['pieceId'], $locale);
        $tags = array_values(array_filter($piece['tags'], static fn (array $t): bool => $t['id'] === $done['tagId']));
        if ($tags === []) {
            throw new GestionConflictException('TAG_NOT_FOUND', $this->texts->t('pieces.error.TAG_NOT_FOUND', $locale), 404); // effacée entre-temps (exercice)
        }

        return ['created' => $done['created'], 'associated' => $done['associated'], 'piece' => $piece, 'tag' => $tags[0]];
    }

    /**
     * Ce que porte une puce.
     *
     * @return array{identifier: ?string, found: bool, refusal: ?string, tag: ?array<string, mixed>, piece: ?array<string, mixed>}
     */
    public function search(mixed $raw, string $locale, bool $exercise): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new GestionValidationException(['identifier' => 'REQUIRED']);
        }
        $identifier = $exercise ? LossTagIdentifier::canonical($raw, LossTagIdentifier::SIMULATED) : LossTagIdentifier::loose($raw);
        $tag = $identifier === null ? null : $this->repository()->tagByIdentifier($identifier);
        if ($tag !== null && (bool) $tag['exercise'] !== $exercise) {
            $tag = null; // une puce de l'autre monde est « inconnue » ici
        }
        $piece = $tag !== null && $tag['piece_id'] !== null ? ($this->sheets($this->repository()->pieces([(int) $tag['piece_id']]), $locale)[0] ?? null) : null;

        return [
            'identifier' => $identifier,
            'found' => $tag !== null,
            'refusal' => $identifier === null ? 'TAG_INVALID' : null, // la lecture n'a pas la forme d'une puce
            'tag' => $tag !== null ? $this->tagRow($tag, $locale) : null,
            'piece' => $piece,
        ];
    }

    /**
     * Retire une puce (détachée, détruite, perdue, posée par erreur) : elle ne reviendra sur aucune pièce.
     *
     * @param array<string, mixed> $body { reason, confirm: true }
     *
     * @return array{changed: bool, tag: array<string, mixed>, piece: ?array<string, mixed>}
     */
    public function retireTag(int $id, array $body, string $locale, int $userId, bool $exercise): array
    {
        $reason = $this->retirement($body, LossTag::RETIRE_REASONS, $locale);
        $repository = $this->repository();
        $now = self::now();
        $changed = $this->transaction(function () use ($repository, $id, $reason, $exercise, $userId, $now, $locale): bool {
            if ($exercise) {
                $this->exerciseLock();
            }
            $tag = $repository->tagById($id, true);
            if ($tag === null || (bool) $tag['exercise'] !== $exercise) {
                throw new GestionConflictException('TAG_NOT_FOUND', $this->texts->t('pieces.error.TAG_NOT_FOUND', $locale), 404);
            }
            if ($tag['status'] === LossTag::RETIRED) {
                return false;
            }
            $repository->retireTag($id, $reason, $now);
            $repository->event(LossPieceEvent::TAG_RETIRED, $userId, $tag['piece_id'] !== null ? (int) $tag['piece_id'] : null, $id, $exercise, ['reason' => $reason], $now);

            return true;
        });
        $tag = $repository->tagById($id);
        if ($tag === null) {
            throw new GestionConflictException('TAG_NOT_FOUND', $this->texts->t('pieces.error.TAG_NOT_FOUND', $locale), 404); // effacée entre-temps (exercice)
        }

        return ['changed' => $changed, 'tag' => $this->tagRow($tag, $locale), 'piece' => $tag['piece_id'] !== null ? $this->pieceSheet((int) $tag['piece_id'], $locale) : null];
    }

    /**
     * Retire une pièce (abîmée, perdue, vendue sans suivi, créée par erreur), et ses puces actives avec elle.
     *
     * @param array<string, mixed> $body { reason, confirm: true }
     *
     * @return array{changed: bool, piece: array<string, mixed>}
     */
    public function retirePiece(int $id, array $body, string $locale, int $userId, bool $exercise): array
    {
        $reason = $this->retirement($body, LossPiece::RETIRE_REASONS, $locale);
        $repository = $this->repository();
        $now = self::now();
        $changed = $this->transaction(function () use ($repository, $id, $reason, $exercise, $userId, $now, $locale): bool {
            if ($exercise) {
                $this->exerciseLock();
            }
            $piece = $repository->piece($id, true);
            if ($piece === null || (bool) $piece['exercise'] !== $exercise) {
                throw new GestionConflictException('PIECE_NOT_FOUND', $this->texts->t('pieces.error.PIECE_NOT_FOUND', $locale), 404);
            }
            if ($piece['status'] === LossPiece::RETIRED) {
                return false;
            }
            foreach ($repository->lockActiveTags($id) as $tagId) {
                $repository->retireTag($tagId, 'piece_retired', $now);
                $repository->event(LossPieceEvent::TAG_RETIRED, $userId, $id, $tagId, $exercise, ['reason' => 'piece_retired'], $now);
            }
            $repository->retirePiece($id, $reason, $now);
            $repository->event(LossPieceEvent::PIECE_RETIRED, $userId, $id, null, $exercise, ['reason' => $reason], $now);

            return true;
        });

        return ['changed' => $changed, 'piece' => $this->pieceSheet($id, $locale)];
    }

    /**
     * Synthèse des vraies pièces par variante : à côté du stock, pour information (la pièce ne remplace pas le stock).
     *
     * @param array<string, mixed> $query variantId, productId, page, limit
     *
     * @return array<string, mixed>
     */
    public function synthesis(array $query, string $locale): array
    {
        $errors = [];
        $filters = [];
        foreach (['variantId', 'productId'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $id = self::positiveInt($query[$key]);
                $id === null ? $errors[$key] = 'MUST_BE_POSITIVE' : $filters[$key] = $id;
            }
        }
        [$page, $limit] = self::paging($query, $errors);
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $found = $this->repository()->synthesis($filters, $page, $limit);
        $variants = $this->repository()->variants(array_map(static fn (array $r): int => (int) $r['variant_id'], $found['rows']));
        $rows = [];
        foreach ($found['rows'] as $row) {
            $variant = $variants[(int) $row['variant_id']] ?? null;
            $active = (int) $row['active'];
            $stock = $variant !== null ? (int) $variant['stock_quantity'] : null;
            $rows[] = ['variantId' => (int) $row['variant_id'], 'productId' => (int) $row['product_id']] + self::catalogue($variant) + [
                'stock' => $stock,
                // en service = dans la boutique : ni retirée ni vendue (une pièce revenue est de nouveau en service)
                'pieces' => ['active' => $active, 'sold' => (int) $row['sold'], 'retired' => (int) $row['retired'], 'tagged' => (int) $row['tagged'], 'untagged' => $active - (int) $row['tagged']],
                // écart « stock − pièces en service » : positif, des exemplaires en stock ne sont pas suivis ; négatif, plus de pièces que de stock
                'gap' => $stock !== null ? $stock - $active : null,
            ];
        }

        return ['rows' => $rows, 'total' => $found['total'], 'page' => $page, 'limit' => $limit,
            'totals' => ['variants' => $found['total'], 'pieces' => $found['totals']['active'], 'sold' => $found['totals']['sold'], 'retired' => $found['totals']['retired'],
                'tagged' => $found['totals']['tagged'], 'untagged' => $found['totals']['active'] - $found['totals']['tagged']]];
    }

    /**
     * Pièces d'exercice du site, avec ce qu'il reste à créer.
     *
     * @return array<string, mixed>
     */
    public function exerciseList(string $locale): array
    {
        $found = $this->repository()->page([], true, 1, self::EXERCISE_LIMIT);
        $count = $this->repository()->exerciseCount();

        return ['pieces' => $this->sheets($found['rows'], $locale), 'count' => $count, 'limit' => self::EXERCISE_LIMIT, 'remaining' => max(0, self::EXERCISE_LIMIT - $count),
            'createLimit' => self::EXERCISE_CREATE_LIMIT, 'tagLimit' => self::EXERCISE_TAG_LIMIT,
            'daily' => ['limit' => $this->exerciseDailyLimit, 'remaining' => max(0, $this->exerciseDailyLimit - $this->createdToday()), 'resetAt' => $this->resetAt()]];
    }

    /** Efface toutes les pièces d'exercice du site, avec leurs puces et leurs faits @return array{deleted: int} */
    public function deleteExercise(int $userId): array
    {
        $deleted = $this->transaction(function () use ($userId): int {
            $this->exerciseLock();
            $deleted = $this->repository()->deleteExercise();
            if ($deleted > 0) {
                $this->journal()->append(LossJournalEntry::EXERCISE_PIECES_DELETED, $userId, null, ['deleted' => $deleted]);
            }

            return $deleted;
        });

        return ['deleted' => $deleted];
    }

    /**
     * Place pour `$count` pièces d'exercice de plus : plafond des pièces présentes et plafond des créations du jour, sous le verrou
     * des pièces d'exercice (dans la transaction de l'appelant).
     */
    private function exerciseRoom(int $count, string $locale): void
    {
        $this->exerciseLock();
        if ($this->createdToday() >= $this->exerciseDailyLimit) {
            throw new GestionConflictException('EXERCISE_DAILY_LIMIT', $this->texts->t('pieces.error.EXERCISE_DAILY_LIMIT', $locale, ['limit' => $this->exerciseDailyLimit]), 409,
                ['limit' => $this->exerciseDailyLimit, 'resetAt' => $this->resetAt()]);
        }
        if ($this->repository()->exerciseCount() + $count > self::EXERCISE_LIMIT) {
            throw new GestionConflictException('EXERCISE_LIMIT_REACHED', $this->texts->t('pieces.error.EXERCISE_LIMIT_REACHED', $locale, ['limit' => self::EXERCISE_LIMIT]), 409, ['limit' => self::EXERCISE_LIMIT]);
        }
    }

    /**
     * Verrou des écritures d'exercice, tenu jusqu'à la fin de la transaction de l'appelant : créer, poser, retirer et effacer ne se
     * croisent pas (un effacement ne laisse ni puce ni fait sans leur pièce). Les vraies pièces ne le prennent jamais.
     */
    private function exerciseLock(): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(' . LossPieceRepository::EXERCISE_LOCK . ')');
    }

    /** @return array<string, mixed> la fiche d'une pièce ; 404 si elle n'existe plus (une pièce d'exercice effacée entre-temps) */
    private function pieceSheet(int $id, string $locale): array
    {
        $sheets = $this->sheets($this->repository()->pieces([$id]), $locale);
        if ($sheets === []) {
            throw new GestionConflictException('PIECE_NOT_FOUND', $this->texts->t('pieces.error.PIECE_NOT_FOUND', $locale), 404);
        }

        return $sheets[0];
    }

    /** Créations d'exercice du jour du magasin, lues au journal du module (qui ne se supprime pas : effacer ne rend aucune création) */
    private function createdToday(): int
    {
        [$start] = $this->site->clock()->range(null, null);

        return $this->journal()->countRecent(LossJournalEntry::EXERCISE_PIECES_CREATED, $start, $this->exerciseDailyLimit);
    }

    private function resetAt(): string
    {
        [, $end] = $this->site->clock()->range(null, null);

        return LossAuditEntry::iso($end);
    }

    /**
     * La cible d'un geste : exactement une des clés permises.
     *
     * @param array<string, mixed> $body
     * @param list<string> $keys parmi pieceId, variantId, barcode
     * @param array<string, string> $errors
     *
     * @return array{pieceId?: int, variantId?: int, barcode?: string}
     */
    private function targetOf(array $body, array $keys, array &$errors): array
    {
        $given = array_values(array_filter($keys, static fn (string $k): bool => isset($body[$k])));
        if (count($given) !== 1) {
            $errors['target'] = 'ONE_TARGET_REQUIRED'; // une seule de : pieceId, variantId, barcode

            return [];
        }
        $key = $given[0];
        if ($key === 'barcode') {
            if (!is_string($body[$key]) || CatalogBarcode::normalize($body[$key]) === null) {
                $errors[$key] = 'MUST_BE_STRING';

                return [];
            }

            return ['barcode' => (string) CatalogBarcode::normalize($body[$key])];
        }
        if (!is_int($body[$key]) || $body[$key] < 1 || $body[$key] > 2147483647) {
            $errors[$key] = 'MUST_BE_POSITIVE';

            return [];
        }

        return [$key => $body[$key]];
    }

    /**
     * La variante désignée, lue dans le catalogue : par son identifiant, ou par un code-barres (celui d'une variante ; celui d'un
     * article quand il n'a qu'une variante).
     *
     * @param array{variantId?: int, barcode?: string} $target
     *
     * @return array<string, mixed>
     */
    private function variant(array $target, string $locale): array
    {
        $variantId = $target['variantId'] ?? null;
        if ($variantId === null) {
            $holder = $this->barcodes->holder((string) $target['barcode']);
            if ($holder === null) {
                throw new GestionConflictException('BARCODE_NOT_FOUND', $this->texts->t('pieces.error.BARCODE_NOT_FOUND', $locale), 404, ['barcode' => $target['barcode']]);
            }
            if ($holder['type'] === 'variant') {
                $variantId = $holder['id'];
            } else {
                $ids = $this->repository()->variantIdsOfProduct($holder['id']);
                if (count($ids) !== 1) {
                    $code = $ids === [] ? 'NO_VARIANT' : 'BARCODE_AMBIGUOUS';
                    throw new GestionConflictException($code, $this->texts->t("pieces.error.$code", $locale, ['label' => $holder['label']]), 409, ['productId' => $holder['id'], 'variantIds' => $ids]);
                }
                $variantId = $ids[0];
            }
        }
        $variant = $this->repository()->variants([$variantId])[$variantId] ?? null;
        if ($variant === null) {
            throw new GestionConflictException('VARIANT_NOT_FOUND', $this->texts->t('pieces.error.VARIANT_NOT_FOUND', $locale), 404);
        }

        return $variant;
    }

    /** @return array<string, mixed> la ligne d'une pièce de ce monde (réel ou exercice), ou 404 */
    private function existing(int $id, bool $exercise, string $locale): array
    {
        $piece = $this->repository()->piece($id);
        if ($piece === null || (bool) $piece['exercise'] !== $exercise) {
            throw new GestionConflictException('PIECE_NOT_FOUND', $this->texts->t('pieces.error.PIECE_NOT_FOUND', $locale), 404);
        }

        return $piece;
    }

    /**
     * Motif d'un retrait, confirmé : un retrait ne se fait pas d'un geste distrait.
     *
     * @param array<string, mixed> $body
     * @param list<string> $reasons
     */
    private function retirement(array $body, array $reasons, string $locale): string
    {
        $errors = [];
        if (!in_array($body['reason'] ?? null, $reasons, true)) {
            $errors['reason'] = array_key_exists('reason', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        if (array_key_exists('confirm', $body) && !is_bool($body['confirm'])) {
            $errors['confirm'] = 'MUST_BE_BOOLEAN';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        if (($body['confirm'] ?? false) !== true) {
            throw new GestionConflictException('CONFIRMATION_REQUIRED', $this->texts->t('pieces.error.CONFIRMATION_REQUIRED', $locale), 409);
        }

        return (string) $body['reason'];
    }

    /** Refus d'une puce déjà posée ou déjà retirée, avec ce qui la porte */
    private function tagTaken(array $tag, string $locale): GestionConflictException
    {
        if ($tag['status'] === LossTag::RETIRED) {
            return new GestionConflictException('TAG_RETIRED', $this->texts->t('pieces.error.TAG_RETIRED', $locale), 409, ['tagId' => (int) $tag['id']]);
        }
        $piece = $tag['piece_id'] !== null ? $this->repository()->piece((int) $tag['piece_id']) : null;
        $sheet = $piece !== null ? $this->sheets([$piece], $locale)[0] : null;
        $holder = $sheet === null ? null : array_intersect_key($sheet, array_flip(['id', 'number', 'variantId', 'productId', 'name', 'variantLabel']));

        return new GestionConflictException('TAG_ALREADY_ASSOCIATED',
            $this->texts->t($holder === null ? 'pieces.error.TAG_ALREADY_ASSOCIATED_UNKNOWN' : ((string) $holder['name'] !== '' ? 'pieces.error.TAG_ALREADY_ASSOCIATED' : 'pieces.error.TAG_ALREADY_ASSOCIATED_NO_NAME'),
                $locale, ['number' => (string) ($holder['number'] ?? ''), 'name' => (string) ($holder['name'] ?? '')]),
            409, ['holder' => $holder]);
    }

    /**
     * Fiches de pièces prêtes à afficher : la pièce, ce que le catalogue dit de sa variante, ses puces.
     *
     * @param list<array<string, mixed>> $pieces lignes de `loss_piece`
     *
     * @return list<array<string, mixed>>
     */
    private function sheets(array $pieces, string $locale): array
    {
        $repository = $this->repository();
        $variants = $repository->variants(array_map(static fn (array $p): int => (int) $p['variant_id'], $pieces));
        $tags = $repository->tagsOf(array_map(static fn (array $p): int => (int) $p['id'], $pieces));
        $out = [];
        foreach ($pieces as $piece) {
            $id = (int) $piece['id'];
            $rows = array_map(fn (array $t): array => $this->tagRow($t, $locale), $tags[$id] ?? []);
            $out[] = [
                'id' => $id,
                'number' => (int) $piece['number'],
                'status' => (string) $piece['status'],
                'statusLabel' => $this->texts->t('pieces.status.' . $piece['status'], $locale),
                'zone' => $piece['zone'],
                'exercise' => (bool) $piece['exercise'],
                'variantId' => (int) $piece['variant_id'],
                'productId' => (int) $piece['product_id'],
            ] + self::catalogue($variants[(int) $piece['variant_id']] ?? null) + [
                'tags' => $rows,
                'tagged' => array_filter($rows, static fn (array $t): bool => $t['status'] === LossTag::ASSOCIATED) !== [],
                'commandeId' => $piece['commande_id'] !== null ? (int) $piece['commande_id'] : null,
                'retiredReason' => $piece['retired_reason'],
                'retiredReasonLabel' => $piece['retired_reason'] !== null ? $this->texts->t('pieces.reason.' . $piece['retired_reason'], $locale) : null,
                'createdAt' => self::iso($piece['created_at']),
                'retiredAt' => self::iso($piece['retired_at']),
            ];
        }

        return $out;
    }

    /**
     * Ce que le catalogue dit de la variante d'une pièce. Le code-barres n'est pas recopié dans la pièce : c'est celui de la variante,
     * à défaut celui de l'article quand elle est sa seule variante (règle des étiquettes, `docs/codes-barres.md` § 7).
     *
     * @param array<string, mixed>|null $variant null : la variante n'existe plus dans le catalogue
     *
     * @return array<string, mixed>
     */
    public static function catalogue(?array $variant): array
    {
        if ($variant === null) {
            return ['variantMissing' => true, 'name' => null, 'variantLabel' => null, 'barcode' => null, 'barcodeOf' => null, 'symbology' => null];
        }
        $label = implode(' / ', array_filter([trim((string) $variant['color']), trim((string) $variant['size'])], static fn (string $part): bool => $part !== ''));
        $code = $variant['barcode'];
        $of = $code !== null ? 'variant' : null;
        if ($code === null && $variant['product_barcode'] !== null && (int) $variant['siblings'] === 1) {
            $code = $variant['product_barcode'];
            $of = 'product';
        }

        return ['variantMissing' => false, 'name' => (string) $variant['name'], 'variantLabel' => $label !== '' ? $label : null,
            'barcode' => $code, 'barcodeOf' => $of, 'symbology' => $code !== null ? BarcodeLabelPrinter::symbology((string) $code) : null];
    }

    /** @return array<string, mixed> une puce prête à afficher (ligne de `loss_tag`) */
    public function tagView(array $tag, string $locale): array
    {
        return $this->tagRow($tag, $locale);
    }

    /** @return array<string, mixed> une puce prête à afficher */
    private function tagRow(array $tag, string $locale): array
    {
        return [
            'id' => (int) $tag['id'],
            'identifier' => (string) $tag['identifier'],
            'technology' => (string) $tag['technology'],
            'technologyLabel' => $this->texts->t('pieces.technology.' . $tag['technology'], $locale),
            'status' => (string) $tag['status'],
            'statusLabel' => $this->texts->t('pieces.tag_status.' . $tag['status'], $locale),
            'pieceId' => $tag['piece_id'] !== null ? (int) $tag['piece_id'] : null,
            'exercise' => (bool) $tag['exercise'],
            'associatedAt' => self::iso($tag['associated_at']),
            'retiredAt' => self::iso($tag['retired_at']),
            'retiredReason' => $tag['retired_reason'],
            'retiredReasonLabel' => $tag['retired_reason'] !== null ? $this->texts->t('pieces.reason.' . $tag['retired_reason'], $locale) : null,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $errors
     *
     * @return array{int, int} page, limite
     */
    private static function paging(array $query, array &$errors): array
    {
        $page = 1;
        $limit = 50;
        if (isset($query['page']) && $query['page'] !== '') {
            $page = self::positiveInt($query['page']) ?? 0;
            if ($page < 1 || $page > 100000) {
                $errors['page'] = 'MUST_BE_POSITIVE';
            }
        }
        if (isset($query['limit']) && $query['limit'] !== '') {
            $limit = self::positiveInt($query['limit']) ?? 0;
            if ($limit < 1 || $limit > self::PAGE_LIMIT) {
                $errors['limit'] = 'OUT_OF_RANGE';
            }
        }

        return [$page, $limit];
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_string($value) && preg_match('/\A[1-9]\d{0,9}\z/', $value) === 1 && (int) $value <= 2147483647 ? (int) $value : null;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private static function iso(mixed $stored): ?string
    {
        return $stored === null ? null : LossAuditEntry::iso(new \DateTimeImmutable((string) $stored, new \DateTimeZone('UTC')));
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
            $out = $work();
            $conn->commit();

            return $out;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private function repository(): LossPieceRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossPiece::class);
    }

    private function journal(): \App\LossPrevention\Repository\LossJournalRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class);
    }
}
